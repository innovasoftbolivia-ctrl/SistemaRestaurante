<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Models\PedidoDetalle;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Support\AlertasStock;
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
 *   - el panel del negocio pide `reportes.ver`, y lo que hay que comprar,
 *     `inventario.gestionar`.
 *
 * El panel es lo que pasa AHORA: lo vendido hoy frente al mismo día de la
 * semana pasada, las ventas por hora, cómo se cobra, los últimos pedidos, la
 * cocina y lo más pedido. La historia (el gráfico de semanas, el listado de
 * ventas) está en Reportes y en Ventas.
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
    /** Cuántas semanas atrás se promedian para «un lunes normal». */
    private const SEMANAS_PROMEDIO = 4;

    /** Cada cuánto se recarga sola la portada de quien gestiona. */
    public const SEGUNDOS_REFRESCO = 60;

    public function __invoke(): View
    {
        /** @var Usuario $usuario */
        $usuario = Auth::user();

        $gestion = $usuario->tienePermiso('reportes.ver');
        $vende = $usuario->tienePermiso('ventas.registrar');
        $jornada = Carbon::parse(Config::jornadaActual());

        return view('dashboard', [
            'title' => 'Inicio',
            'usuario' => $usuario,
            'sesion' => Cajas::sesionDe($usuario)?->loadCount('ventas'),
            'mias' => $vende ? $this->ventasPropias($usuario) : null,
            'gestion' => $gestion,
            'hoy' => $gestion ? $this->comparativaDelDia($jornada) : null,
            'porHora' => $gestion ? $this->porHora($jornada) : null,
            'pagos' => $gestion ? $this->porMetodoPago($jornada) : null,
            'pedidos' => $gestion ? $this->ultimosPedidos($jornada) : null,
            'cocina' => $gestion ? $this->cocina() : null,
            'top' => $gestion ? $this->loMasVendido($jornada) : null,
            'stock' => $gestion ? AlertasStock::para($usuario) : null,
            'cajasAbiertas' => $gestion ? $this->cajasAbiertas() : null,
            'segundos' => self::SEGUNDOS_REFRESCO,
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

    /**
     * Esta jornada frente al MISMO DÍA de la semana pasada: un lunes contra el
     * domingo de ayer engaña, porque el domingo siempre se vende más.
     */
    private function comparativaDelDia(Carbon $jornada): array
    {
        $hoy = $this->totalesDe($jornada);
        $antes = $this->totalesDe($jornada->copy()->subWeek());

        return [
            'hoy' => $hoy,
            'antes' => $antes,
            // «el lunes pasado», «el sábado pasado»: para leerlo de corrido.
            'dia' => $jornada->locale('es')->isoFormat('dddd'),
            'variacion' => $antes['monto'] > 0
                ? round(($hoy['monto'] - $antes['monto']) / $antes['monto'] * 100, 1)
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
     * Lo vendido por hora en la jornada, junto al promedio de las mismas horas
     * en las últimas semanas, el mismo día: dice si hoy va flojo o bien a esta
     * hora, antes de que termine el día. En el orden de la jornada (de la hora
     * de corte en adelante) y solo de la primera a la última hora con ventas.
     *
     * @return array{horas: array<int, string>, hoy: array<int, float>, promedio: array<int, float>}|null
     */
    private function porHora(Carbon $jornada): ?array
    {
        $sumar = fn (Carbon $desde, Carbon $hasta, ?array $dias = null) => DB::table('ventas')
            ->whereBetween('fecha', Config::momentosDeJornadas($desde, $hasta))
            ->when($dias, fn ($q) => $q->whereIn(DB::raw(Config::jornadaSql('fecha')), $dias))
            ->where('estado', '<>', 'ANULADA')
            ->groupBy(DB::raw('HOUR(fecha)'))
            ->selectRaw('HOUR(fecha) AS hora, SUM(total) AS monto')
            ->pluck('monto', 'hora')
            ->map(fn ($m) => (float) $m);

        $hoy = $sumar($jornada, $jornada);

        $dias = [];
        for ($i = 1; $i <= self::SEMANAS_PROMEDIO; $i++) {
            $dias[] = $jornada->copy()->subWeeks($i)->toDateString();
        }
        $antes = $sumar($jornada->copy()->subWeeks(self::SEMANAS_PROMEDIO), $jornada->copy()->subWeek(), $dias);

        if ($hoy->isEmpty() && $antes->isEmpty()) {
            return null;
        }

        $corte = Config::horaCorteJornada();
        $orden = array_map(fn ($i) => ($corte + $i) % 24, range(0, 23));
        $conDatos = array_values(array_filter($orden, fn ($h) => $hoy->has($h) || $antes->has($h)));
        $desde = array_search($conDatos[0], $orden, true);
        $hasta = array_search(end($conDatos), $orden, true);
        $tramo = array_slice($orden, $desde, $hasta - $desde + 1);

        return [
            'horas' => array_map(fn ($h) => $h.'h', $tramo),
            'hoy' => array_map(fn ($h) => round($hoy[$h] ?? 0, 2), $tramo),
            'promedio' => array_map(fn ($h) => round(($antes[$h] ?? 0) / self::SEMANAS_PROMEDIO, 2), $tramo),
        ];
    }

    /** Cómo se cobró hoy: lo mismo que el reporte de ventas, para la jornada. */
    private function porMetodoPago(Carbon $jornada): Collection
    {
        return DB::table('venta_pagos as vp')
            ->join('ventas as v', function ($join) {
                $join->on('v.id', '=', 'vp.venta_id')->where('v.estado', '<>', 'ANULADA');
            })
            ->join('metodos_pago as mp', 'mp.id', '=', 'vp.metodo_pago_id')
            ->whereBetween('v.fecha', Config::momentosDeJornadas($jornada, $jornada))
            ->groupBy('mp.nombre')
            ->selectRaw('mp.nombre AS metodo, SUM(vp.monto) AS monto')
            ->orderByDesc('monto')
            ->get();
    }

    /**
     * Los últimos pedidos de la jornada, con en qué están: lo que el dueño
     * mira para saber si el local anda o se atascó.
     *
     * @return Collection<int, array{pedido: Pedido, estado: string, clase: string}>
     */
    private function ultimosPedidos(Carbon $jornada): Collection
    {
        return Pedido::query()
            ->where('jornada', $jornada->toDateString())
            ->with(['detalle:id,pedido_id,pasa_por_cocina,estado_cocina', 'ultimaVenta.cliente:id,nombre'])
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn (Pedido $p) => ['pedido' => $p, ...self::enQueEsta($p)]);
    }

    /** @return array{estado: string, clase: string} */
    public static function enQueEsta(Pedido $pedido): array
    {
        if ($pedido->estado === Pedido::CANCELADO) {
            return ['estado' => 'Cancelado', 'clase' => 'apagado'];
        }

        $cocina = $pedido->detalle
            ->where('pasa_por_cocina', true)
            ->where('estado_cocina', '<>', PedidoDetalle::CANCELADO);

        [$estado, $clase] = match (true) {
            $cocina->isEmpty() || $cocina->every(fn ($l) => $l->estado_cocina === PedidoDetalle::ENTREGADO) => ['Entregado', 'apagado'],
            $cocina->every(fn ($l) => in_array($l->estado_cocina, [PedidoDetalle::LISTO, PedidoDetalle::ENTREGADO], true)) => ['Listo', 'exito'],
            $cocina->every(fn ($l) => $l->estado_cocina === PedidoDetalle::PENDIENTE) => ['Por hacer', 'neutro'],
            default => ['Cocinando', 'aviso'],
        };

        // Un pedido abierto todavía no se cobró (o se le anuló el cobro).
        if ($pedido->estado === Pedido::ABIERTO) {
            return ['estado' => $estado === 'Entregado' ? 'Por cobrar' : $estado.' · por cobrar', 'clase' => 'aviso'];
        }

        return ['estado' => $estado, 'clase' => $clase];
    }

    /**
     * La cocina en este momento: cuántos pedidos hay en cada columna del
     * tablero y hace cuánto espera el más viejo. Es el mismo tablero que ve
     * la cocina (`CocinaController::tandas`).
     *
     * @return array{hacer: int, cocinando: int, entregar: int, espera: ?int}
     */
    private function cocina(): array
    {
        $tandas = CocinaController::tandas();
        $viejo = $tandas->whereIn('columna', ['hacer', 'cocinando'])->min(fn ($t) => $t['desde']?->getTimestamp());

        return [
            'hacer' => $tandas->where('columna', 'hacer')->count(),
            'cocinando' => $tandas->where('columna', 'cocinando')->count(),
            'entregar' => $tandas->where('columna', 'entregar')->count(),
            'espera' => $viejo ? (int) floor((now()->getTimestamp() - $viejo) / 60) : null,
        ];
    }

    /** Lo más pedido hoy, en unidades: lo que la cocina tiene que tener a mano. */
    private function loMasVendido(Carbon $jornada): Collection
    {
        return DB::table('venta_detalle as d')
            ->join('ventas as v', function ($join) {
                $join->on('v.id', '=', 'd.venta_id')->where('v.estado', '<>', 'ANULADA');
            })
            ->join('productos as p', 'p.id', '=', 'd.producto_id')
            ->whereBetween('v.fecha', Config::momentosDeJornadas($jornada, $jornada))
            ->groupBy('p.id', 'p.nombre')
            ->selectRaw('p.id, p.nombre, SUM(d.cantidad) AS unidades, SUM(d.total_linea) AS monto')
            ->orderByDesc('unidades')
            ->orderByDesc('monto')
            ->limit(5)
            ->get();
    }

    /** Los turnos abiertos ahora, con el nombre de su caja. */
    private function cajasAbiertas(): Collection
    {
        return SesionCaja::where('estado', 'ABIERTA')->with('caja:id,nombre')->get(['id', 'caja_id']);
    }
}
