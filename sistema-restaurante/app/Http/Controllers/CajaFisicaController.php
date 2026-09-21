<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Services\Auditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Las cajas FÍSICAS del local (el mostrador, el segundo puesto...), no los
 * turnos: eso es `CajaController`. Se separan porque son dos cosas distintas
 * con dos permisos distintos —abrir un turno lo hace el cajero todos los
 * días; dar de alta un puesto de cobro es administrar el local— y mezclarlas
 * en un mismo controlador confundía hasta para leerlas.
 *
 * Hasta ahora esta tabla no tenía pantalla: para agregar una segunda caja
 * había que entrar a MySQL a mano.
 */
class CajaFisicaController extends Controller
{
    public function index(): View
    {
        return view('cajas.index', [
            'title' => 'Cajas',
            'trail' => ['Caja' => route('caja.index')],
            'cajas' => Caja::withCount('sesiones')
                ->with('sesionAbierta.usuarioApertura:id,usuario')
                ->orderBy('nombre')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        $caja = Caja::create($datos);

        Auditor::registrar('CAJA_FISICA_CREADA', 'cajas', $caja->id, $datos);

        return redirect()->route('cajas.index')
            ->with('exito', "Caja «{$caja->nombre}» creada.");
    }

    public function update(Request $request, Caja $caja): RedirectResponse
    {
        $datos = $this->validar($request, $caja);

        // Una caja con un turno abierto no se puede desactivar: el cajero se
        // quedaría a mitad de camino, sin poder cerrar lo que ya cobró.
        if (! ($datos['activo'] ?? false) && $caja->sesionAbierta()->exists()) {
            return redirect()->route('cajas.index')
                ->with('error', "La caja «{$caja->nombre}» tiene un turno abierto. Ciérralo antes de desactivarla.");
        }

        $caja->update($datos);

        Auditor::registrar('CAJA_FISICA_ACTUALIZADA', 'cajas', $caja->id, $datos);

        return redirect()->route('cajas.index')
            ->with('exito', "Caja «{$caja->nombre}» actualizada.");
    }

    /**
     * Con turnos en el historial se desactiva en lugar de borrarse: esos
     * turnos son el respaldo de arqueos ya firmados, y no se tocan.
     */
    public function destroy(Caja $caja): RedirectResponse
    {
        if ($caja->sesionAbierta()->exists()) {
            return redirect()->route('cajas.index')
                ->with('error', "La caja «{$caja->nombre}» tiene un turno abierto. Ciérralo antes de darla de baja.");
        }

        if ($caja->sesiones()->exists()) {
            $caja->update(['activo' => false]);

            Auditor::registrar('CAJA_FISICA_DESACTIVADA', 'cajas', $caja->id);

            return redirect()->route('cajas.index')
                ->with('exito', "La caja «{$caja->nombre}» tiene turnos registrados, así que se desactivó en lugar de eliminarse.");
        }

        $nombre = $caja->nombre;
        $caja->delete();

        Auditor::registrar('CAJA_FISICA_ELIMINADA', 'cajas', null, ['nombre' => $nombre]);

        return redirect()->route('cajas.index')->with('exito', "Caja «{$nombre}» eliminada.");
    }

    /** @return array<string, mixed> */
    private function validar(Request $request, ?Caja $caja = null): array
    {
        return $request->validate([
            'nombre' => [
                'required', 'string', 'max:40',
                Rule::unique('cajas', 'nombre')->ignore($caja?->id),
            ],
            'ubicacion' => ['nullable', 'string', 'max:60'],
            'activo' => ['boolean'],
        ], [
            'nombre.unique' => 'Ya hay una caja con ese nombre.',
        ], [
            'nombre' => 'nombre',
            'ubicacion' => 'ubicación',
        ]);
    }
}
