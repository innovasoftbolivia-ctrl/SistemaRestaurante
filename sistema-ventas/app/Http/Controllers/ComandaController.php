<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Services\Comandas;
use App\Support\Config;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

/**
 * La comanda impresa para la cocina (ver `App\Services\Comandas`).
 *
 * Imprimir y reimprimir son POST: marcan y dejan rastro en la bitácora. Lo que
 * se imprime es un GET aparte, que no toca nada, para que recargar la hoja o
 * volver a abrirla no mande otra vez lo mismo a la cocina ni ensucie la
 * bitácora. Mismo patrón que el comprobante: una hoja sin barra lateral, en 80
 * mm o en A4, que se imprime con `window.print()`.
 */
class ComandaController extends Controller
{
    /** Manda lo pendiente. Si no hay nada pendiente, es una reimpresión. */
    public function imprimir(Pedido $pedido): RedirectResponse
    {
        $marca = Comandas::imprimir($pedido, Auth::user());

        if (! $marca) {
            return $this->reimprimir($pedido);
        }

        return redirect()->route('pedidos.comanda', [
            $pedido,
            'marca' => $marca->format('Y-m-d H:i:s'),
            'imprimir' => 1,
        ]);
    }

    /** Se perdió el papel: la comanda completa otra vez, sin marcar nada. */
    public function reimprimir(Pedido $pedido): RedirectResponse
    {
        Comandas::reimprimir($pedido, Auth::user());

        return redirect()->route('pedidos.comanda', [$pedido, 'reimpresion' => 1, 'imprimir' => 1]);
    }

    /**
     * La hoja: la comanda de una hora (`?marca=`) o la completa, que se marca
     * como reimpresión: la original es la de la marca.
     */
    public function ver(Request $request, Pedido $pedido): View
    {
        $pedido->load(['ultimaVenta.cliente:id,nombre', 'usuario:id,usuario']);

        $marca = null;

        if ($request->filled('marca')) {
            try {
                $marca = Carbon::parse($request->string('marca')->toString());
            } catch (Throwable) {
                $marca = null;
            }
        }

        $lineas = $marca ? Comandas::deLaMarca($pedido, $marca) : Comandas::completa($pedido);

        return view('comandas.imprimir', [
            'pedido' => $pedido,
            'nuevas' => $lineas['nuevas'],
            'canceladas' => $lineas['canceladas'],
            'reimpresion' => $marca === null,
            // La hora que se imprime: la de la comanda original, o la de ahora
            // si es una reimpresión.
            'hora' => $marca ?? now(),
            'ticket' => $request->string('formato')->toString() !== 'a4',
            'autoImprimir' => $request->boolean('imprimir'),
            'negocio' => Config::negocio(),
        ]);
    }
}
