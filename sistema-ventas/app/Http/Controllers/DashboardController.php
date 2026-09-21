<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Support\Config;
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
 *   - el resumen del negocio y el gráfico piden `reportes.ver`.
 *
 * Un cajero entra al mostrador, no aquí (ver {@see Menu::inicio()}), pero
 * puede abrir la portada para ver cómo va su turno.
 *
 * «Hoy» es la JORNADA en curso, no el día de calendario: a la 01:30 el local
 * sigue en la noche de ayer, y lo que vende cuenta para ella, igual que en los
 * reportes y en el número del pedido (`Config::jornadaDe`).
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

        return view('dashboard', [
            'title' => 'Inicio',
            'usuario' => $usuario,
            'sesion' => Cajas::sesionDe($usuario)?->loadCount('ventas'),
            'mias' => $vende ? $this->ventasPropias($usuario) : null,
            'hoy' => $gestion ? $this->comparativaDelDia() : null,
            'serie' => $gestion ? $this->serie() : null,
            'ultimas' => $gestion ? $this->ultimasVentas() : null,
            'gestion' => $gestion,
            'veArqueo' => CajaController::arquea($usuario),
        ]);
    }

    /**
     * Lo que lleva vendido en la jornada quien mira: es su propio trabajo.
     *
     * `whereBetween` con las dos puntas de la jornada, y no `whereDate()`: esta
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
            ->whereBetween('fecha', Config::momentosDeJornadas(Config::jornadaActual(), Config::jornadaActual()))
            ->where('estado', '<>', 'ANULADA')
            ->selectRaw('COUNT(*) AS operaciones, COALESCE(SUM(total), 0) AS monto')
            ->first();

        return [
            'operaciones' => (int) $fila->operaciones,
            'monto' => (float) $fila->monto,
        ];
    }

    /** Esta jornada frente a la anterior: una cifra sola no dice si va bien o mal. */
    private function comparativaDelDia(): array
    {
        $jornada = Carbon::parse(Config::jornadaActual());
        $hoy = $this->totalesDe($jornada);
        $ayer = $this->totalesDe($jornada->copy()->subDay());

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
            ->whereBetween('fecha', Config::momentosDeJornadas($dia, $dia))
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
     * Serie de las últimas dos semanas de jornadas, con las que no tuvieron
     * ventas en cero para que el gráfico no una jornadas lejanas con una recta.
     *
     * `v_ventas_por_dia` es la definición oficial (ver `ReporteController`),
     * pero filtrarla por rango después de agrupar obliga a un recorrido
     * completo de `ventas` en cada visita a esta pantalla —la primera que
     * carga cualquiera al entrar—. Se repite la misma fórmula
     * (`Config::jornadaSql`) contra la tabla base, filtrando antes de agrupar.
     */
    private function serie(): array
    {
        $hasta = Carbon::parse(Config::jornadaActual());
        $desde = $hasta->copy()->subDays(self::DIAS_GRAFICO - 1);

        $filas = DB::table('ventas')
            ->whereBetween('fecha', Config::momentosDeJornadas($desde, $hasta))
            ->where('estado', '<>', 'ANULADA')
            ->groupBy(DB::raw(Config::jornadaSql('fecha')))
            ->selectRaw(Config::jornadaSql('fecha').' AS dia, SUM(total) AS monto_total')
            ->get()
            ->keyBy(fn ($f) => (string) $f->dia);

        $serie = [];

        for ($dia = $desde->copy(); $dia->lte($hasta); $dia->addDay()) {
            $fila = $filas->get($dia->toDateString());

            $serie[] = [
                'etiqueta' => $dia->format('d/m'),
                'monto' => (float) ($fila->monto_total ?? 0),
            ];
        }

        return $serie;
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
