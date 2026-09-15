<?php

namespace App\Http\Controllers;

use App\Models\Producto;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Support\Menu;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * La portada. Cada bloque se arma solo si el rol puede verlo:
 *
 *   - el turno propio y las ventas propias los ve cualquiera que venda,
 *     porque son su trabajo, no información de gestión;
 *   - el resumen del negocio y el gráfico piden `reportes.ver`;
 *   - las alertas de reposición, `productos.gestionar` o `reportes.ver`.
 *
 * Un cajero entra al mostrador, no aquí (ver {@see Menu::inicio()}), pero
 * puede abrir la portada para ver cómo va su turno.
 */
class DashboardController extends Controller
{
    private const DIAS_GRAFICO = 14;

    public function __invoke(): View
    {
        /** @var Usuario $usuario */
        $usuario = Auth::user();

        $gestion = $usuario->tienePermiso('reportes.ver');
        $vende = $usuario->tienePermiso('ventas.registrar');
        $catalogo = $usuario->tienePermiso('productos.gestionar');

        return view('dashboard', [
            'title' => 'Inicio',
            'usuario' => $usuario,
            'sesion' => Cajas::sesionDe($usuario)?->loadCount('ventas'),
            'mias' => $vende ? $this->ventasPropias($usuario) : null,
            'hoy' => $gestion ? $this->comparativaDelDia() : null,
            'serie' => $gestion ? $this->serie() : null,
            'alertas' => ($gestion || $catalogo) ? $this->alertas() : null,
            // El total aparte: la lista está recortada a seis y la insignia
            // contaba esas seis aunque hubiera cuarenta por reponer.
            'alertasTotal' => ($gestion || $catalogo) ? Producto::alertasDeStock()->count() : null,
            'ultimas' => $gestion ? $this->ultimasVentas() : null,
            'gestion' => $gestion,
            'veArqueo' => CajaController::arquea($usuario),
        ]);
    }

    /**
     * Lo que lleva vendido hoy quien mira: es su propio trabajo.
     *
     * `whereBetween` con las dos puntas del día, y no `whereDate()`: esta
     * pantalla se carga en cada login, y `whereDate('fecha', ...)` compila a
     * `WHERE DATE(fecha) = ...`, que no puede usar `ix_ventas_fecha` como
     * rango —envuelve la columna en una función— y obliga a MySQL a recorrer
     * toda la tabla `ventas` (confirmado con EXPLAIN). Con el rango explícito
     * sí es un `Index range scan`.
     */
    private function ventasPropias(Usuario $usuario): array
    {
        $fila = DB::table('ventas')
            ->where('usuario_id', $usuario->id)
            ->whereBetween('fecha', [now()->startOfDay(), now()->endOfDay()])
            ->where('estado', '<>', 'ANULADA')
            ->selectRaw('COUNT(*) AS operaciones, COALESCE(SUM(total), 0) AS monto')
            ->first();

        return [
            'operaciones' => (int) $fila->operaciones,
            'monto' => (float) $fila->monto,
        ];
    }

    /** Hoy frente a ayer: una cifra sola no dice si va bien o mal. */
    private function comparativaDelDia(): array
    {
        $hoy = $this->totalesDe(now());
        $ayer = $this->totalesDe(now()->subDay());

        return [
            'hoy' => $hoy,
            'ayer' => $ayer,
            'variacion' => $ayer['monto'] > 0
                ? round(($hoy['monto'] - $ayer['monto']) / $ayer['monto'] * 100, 1)
                : null,
        ];
    }

    /**
     * @return array<string, float|int>
     *
     * Mismo motivo que `ventasPropias()`: rango explícito y no `whereDate()`.
     */
    private function totalesDe(Carbon $dia): array
    {
        $fila = DB::table('ventas')
            ->whereBetween('fecha', [$dia->copy()->startOfDay(), $dia->copy()->endOfDay()])
            ->where('estado', '<>', 'ANULADA')
            ->selectRaw('COUNT(*) AS operaciones, COALESCE(SUM(total), 0) AS monto')
            ->first();

        $operaciones = (int) $fila->operaciones;
        $monto = (float) $fila->monto;

        return [
            'operaciones' => $operaciones,
            'monto' => $monto,
            'ticket' => $operaciones > 0 ? round($monto / $operaciones, 2) : 0.0,
        ];
    }

    /**
     * Serie de las últimas dos semanas, con los días sin ventas en cero para
     * que el gráfico no una días lejanos con una recta.
     *
     * `v_ventas_por_dia` es la definición oficial (ver `ReporteController`),
     * pero agrupa por `DATE(fecha)` y filtrarla por rango después de agrupar
     * obliga a un recorrido completo de `ventas` en cada visita a esta
     * pantalla —la primera que carga cualquiera al entrar—. Se repite la
     * misma fórmula contra la tabla base, filtrando antes de agrupar.
     */
    private function serie(): array
    {
        $desde = now()->subDays(self::DIAS_GRAFICO - 1)->startOfDay();

        $filas = DB::table('ventas')
            ->whereBetween('fecha', [$desde, now()->endOfDay()])
            ->where('estado', '<>', 'ANULADA')
            ->groupBy(DB::raw('DATE(fecha)'))
            ->selectRaw('DATE(fecha) AS dia, SUM(total) AS monto_total')
            ->get()
            ->keyBy(fn ($f) => (string) $f->dia);

        $serie = [];

        for ($dia = $desde->copy(); $dia->lte(now()); $dia->addDay()) {
            $fila = $filas->get($dia->toDateString());

            $serie[] = [
                'etiqueta' => $dia->format('d/m'),
                'monto' => (float) ($fila->monto_total ?? 0),
            ];
        }

        return $serie;
    }

    private function alertas(): Collection
    {
        return Producto::alertasDeStock()->orderByDesc('faltante')->limit(6)->get();
    }

    private function ultimasVentas(): Collection
    {
        return Venta::with(['cliente:id,nombre', 'usuario:id,usuario', 'comprobante:id,venta_id,numero_completo'])
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->limit(8)
            ->get();
    }
}
