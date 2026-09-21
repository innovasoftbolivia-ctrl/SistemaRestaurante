<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\SesionCaja;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Pedidos;
use App\Support\Config;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El número que se canta en la barra.
 *
 * El `id` del pedido es global y creciente: a los pocos meses el ticket decía
 * «PEDIDO #4812», que no hay manera de gritar por encima del ruido del salón.
 * `numero_dia` es el de toda la vida —1, 2, 3…— y vuelve a empezar cada día.
 * El `id` se queda como clave y en las URLs, donde no lo lee nadie.
 *
 * Lo que se cuida acá es lo único que puede salir mal de verdad: que dos
 * pedidos del mismo día saquen el mismo número. Quien lo impide es la base
 * (`uq_pedido_numero_dia`), no el SELECT que busca el siguiente.
 */
class NumeroDelPedidoTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    /** Viaja a un momento en el que el local no abrió ningún pedido todavía en esa jornada. */
    private function enUnDiaSinPedidos(string $cuando): void
    {
        Carbon::setTestNow(Carbon::parse($cuando));

        $this->assertSame(0, Pedido::where('jornada', Config::jornadaActual())->count(),
            "la jornada del {$cuando} ya tiene pedidos: la prueba no puede dar por hecho que el contador arranca");
    }

    private function horaDeCorte(int $hora): void
    {
        DB::table('configuracion')->updateOrInsert(['clave' => 'hora_corte_jornada'], ['valor' => (string) $hora]);
        Config::olvidar();
    }

    // ------------------------------------------------------------ el contador

    public function test_el_primer_pedido_del_dia_es_el_uno(): void
    {
        $this->enUnDiaSinPedidos('2031-03-04 11:00:00');

        $pedido = Pedidos::abrir(Pedido::LOCAL, $this->cajero());

        $this->assertSame(1, $pedido->numero_dia, 'el primer pedido del día no salió con el número 1');
    }

    /**
     * Un solo contador para toda la jornada: comer aquí y para llevar comparten
     * numeración. Si cada uno llevara la suya, en el mismo turno convivirían
     * dos «pedido 7» y el que espera en la barra no sabría cuál es el suyo.
     */
    public function test_el_siguiente_pedido_del_mismo_dia_es_el_dos_sea_para_comer_aqui_o_para_llevar(): void
    {
        $this->enUnDiaSinPedidos('2031-03-04 11:00:00');
        $cajero = $this->cajero();

        $this->assertSame(1, Pedidos::abrir(Pedido::LOCAL, $cajero)->numero_dia);
        $this->assertSame(2, Pedidos::abrir(Pedido::LLEVAR, $cajero, nombreCliente: 'Ana')->numero_dia);
        $this->assertSame(3, Pedidos::abrir(Pedido::LOCAL, $cajero)->numero_dia);
    }

    /** Lo que pedía el dueño: mañana se vuelve a cantar «pedido 1». */
    public function test_al_dia_siguiente_el_contador_vuelve_a_uno(): void
    {
        $this->enUnDiaSinPedidos('2031-03-04 20:00:00');
        $cajero = $this->cajero();

        $anoche = Pedidos::abrir(Pedido::LOCAL, $cajero);
        $this->assertSame(1, $anoche->numero_dia);
        $this->assertSame(2, Pedidos::abrir(Pedido::LLEVAR, $cajero)->numero_dia);

        $this->travel(1)->days();

        $hoy = Pedidos::abrir(Pedido::LOCAL, $cajero);

        $this->assertSame(1, $hoy->numero_dia, 'el contador no volvió a empezar al día siguiente');
        // Y el id sigue subiendo: es la clave, no el número que se canta.
        $this->assertGreaterThan($anoche->id, $hoy->id);
    }

    // ------------------------------------------------------------ la jornada

    /**
     * El local cierra pasada la medianoche: el pedido de la 01:30 es de la
     * noche anterior y sigue su numeración. Con el corte a las 5 (el de
     * fábrica), a las 04:59 todavía es la misma noche, y a las 05:00 empieza
     * la jornada nueva con el 1.
     */
    public function test_un_pedido_de_la_madrugada_sigue_la_numeracion_de_la_noche(): void
    {
        $this->horaDeCorte(5);
        $this->enUnDiaSinPedidos('2031-03-04 22:00:00');
        $cajero = $this->cajero();

        $noche = Pedidos::abrir(Pedido::LLEVAR, $cajero);
        $this->assertSame([1, '2031-03-04'], [$noche->numero_dia, $noche->jornada->toDateString()]);

        Carbon::setTestNow(Carbon::parse('2031-03-05 01:30:00'));
        $madrugada = Pedidos::abrir(Pedido::LLEVAR, $cajero);
        $this->assertSame(2, $madrugada->numero_dia, 'el pedido de la 01:30 empezó otra numeración');
        $this->assertSame('2031-03-04', $madrugada->jornada->toDateString());

        Carbon::setTestNow(Carbon::parse('2031-03-05 04:59:59'));
        $this->assertSame(3, Pedidos::abrir(Pedido::LLEVAR, $cajero)->numero_dia);

        Carbon::setTestNow(Carbon::parse('2031-03-05 05:00:00'));
        $manana = Pedidos::abrir(Pedido::LLEVAR, $cajero);
        $this->assertSame(1, $manana->numero_dia, 'a las 05:00 la numeración no volvió a 1');
        $this->assertSame('2031-03-05', $manana->jornada->toDateString());
    }

    /** La hora de corte es del negocio: con 0, la jornada es el día de calendario. */
    public function test_la_hora_de_corte_sale_de_la_configuracion(): void
    {
        $this->horaDeCorte(0);
        $this->enUnDiaSinPedidos('2031-03-04 23:30:00');
        $cajero = $this->cajero();

        $this->assertSame(1, Pedidos::abrir(Pedido::LLEVAR, $cajero)->numero_dia);

        Carbon::setTestNow(Carbon::parse('2031-03-05 00:30:00'));
        $this->assertSame(0, Pedido::where('jornada', '2031-03-05')->count());
        $this->assertSame(1, Pedidos::abrir(Pedido::LLEVAR, $cajero)->numero_dia);

        // Y un valor fuera de rango ya no llega ni a guardarse: la base lo
        // rechaza (`ck_config_hora`), también en la vía sin triggers. La hora
        // se queda en la última válida.
        $this->assertThrows(fn () => $this->horaDeCorte(40), QueryException::class, 'ck_config_hora');
        $this->assertSame(0, Config::horaCorteJornada());
    }

    // ------------------------------------------------- que no se repita nunca

    /** La unicidad la garantiza la base, no el SELECT que busca el siguiente. */
    public function test_la_base_rechaza_dos_pedidos_con_el_mismo_numero_el_mismo_dia(): void
    {
        $this->enUnDiaSinPedidos('2031-03-04 11:00:00');
        $pedido = Pedidos::abrir(Pedido::LOCAL, $this->cajero());

        try {
            $this->grabarAMano($pedido->numero_dia, $pedido->fecha_apertura);
            $this->fail('la base aceptó dos pedidos con el mismo número el mismo día');
        } catch (QueryException $e) {
            $this->assertStringContainsString('uq_pedido_numero_dia', $e->getMessage());
        }
    }

    /**
     * Otra caja se adelantó y se quedó con el número que iba a tocar: el
     * siguiente pedido toma el que sigue, no lo repite.
     */
    public function test_un_numero_ya_tomado_no_se_reparte_dos_veces(): void
    {
        $this->enUnDiaSinPedidos('2031-03-04 11:00:00');
        $cajero = $this->cajero();

        $primero = Pedidos::abrir(Pedido::LOCAL, $cajero);
        $this->grabarAMano($primero->numero_dia + 1, $primero->fecha_apertura);

        $siguiente = Pedidos::abrir(Pedido::LOCAL, $cajero);

        $this->assertSame($primero->numero_dia + 2, $siguiente->numero_dia,
            'el pedido repitió un número que ya estaba tomado');
    }

    /**
     * El choque de verdad: otra petición graba el número JUSTO entre el SELECT
     * y el INSERT de esta. El índice único rechaza el INSERT, la transacción
     * se deshace entera y el servicio reintenta en lugar de caerse.
     *
     * Se simula desde el evento `creating` porque lanzar dos procesos de PHP
     * contra la misma base desde una prueba no es viable; el efecto sobre el
     * servicio es exactamente el mismo: un 1062 sobre `uq_pedido_numero_dia`.
     */
    public function test_ante_un_choque_el_pedido_se_abre_igual_y_sin_numeros_repetidos(): void
    {
        $this->enUnDiaSinPedidos('2031-03-04 11:00:00');
        $intentos = 0;

        Pedido::creating(function (Pedido $nuevo) use (&$intentos) {
            // Solo el primer intento choca; si chocaran todos, el servicio se
            // rendiría —que es lo correcto— y no habría nada que comprobar.
            if (++$intentos > 1) {
                return;
            }

            $this->grabarAMano($nuevo->numero_dia, $nuevo->fecha_apertura);
        });

        try {
            $pedido = Pedidos::abrir(Pedido::LOCAL, $this->cajero());
        } finally {
            Pedido::flushEventListeners();
        }

        $this->assertSame(2, $intentos, 'el servicio no reintentó tras el choque');
        $this->assertGreaterThan(0, $pedido->numero_dia);
        $this->assertSame(1, Pedido::where('jornada', Config::jornadaActual())
            ->where('numero_dia', $pedido->numero_dia)->count(),
            'quedaron dos pedidos de la misma jornada con el mismo número');
    }

    // ------------------------------------------------------------- el ticket

    /**
     * Lo que ve el cliente. El pedido de la prueba tiene un id que no se
     * parece a su número del día —lo abre después de otro de la víspera—, así
     * que si el ticket imprimiera el id se notaría.
     */
    public function test_el_ticket_imprime_el_numero_del_dia_y_no_el_id(): void
    {
        $this->enUnDiaSinPedidos('2031-03-04 21:00:00');
        $cajero = $this->cajero();

        // El de la víspera solo está para empujar el id hacia arriba.
        Pedidos::abrir(Pedido::LOCAL, $cajero);
        $this->travel(1)->days();

        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);
        Pedidos::agregarLinea($pedido, $this->plato(), 1, null, $cajero);

        $this->assertSame(1, $pedido->numero_dia);
        $this->assertGreaterThan($pedido->numero_dia, $pedido->id, 'la prueba necesita un id distinto del número');

        $venta = $this->cobrar($pedido->fresh());
        $html = $this->ticket($venta);

        $texto = preg_replace('/\s+/u', ' ', strip_tags($html));

        $this->assertStringContainsString("PEDIDO #{$pedido->numero_dia} COMER AQUÍ", $texto,
            'el ticket no encabeza con el número del día');
        $this->assertStringNotContainsString("PEDIDO #{$pedido->id}", $texto,
            'el ticket sigue imprimiendo el id del pedido');
    }

    // -------------------------------------------------------------- utilería

    /**
     * Un pedido grabado saltándose el servicio: es lo que hace la otra
     * petición que se coló.
     */
    private function grabarAMano(int $numero, Carbon $apertura): void
    {
        DB::table('pedidos')->insert([
            'tipo' => Pedido::LLEVAR,
            'numero_dia' => $numero,
            'jornada' => Config::jornadaDe($apertura),
            'usuario_id' => $this->cajero()->id,
            'nombre_cliente' => 'El que se adelantó',
            'estado' => Pedido::ABIERTO,
            'fecha_apertura' => $apertura,
            'creado_en' => $apertura,
        ]);
    }

    private function plato(): Producto
    {
        return Producto::activos()->orderBy('id')->firstOrFail();
    }

    private function turno(Usuario $usuario): SesionCaja
    {
        return (Cajas::sesionDe($usuario) ?? Cajas::abrir(Caja::firstOrFail(), $usuario, 100))->fresh();
    }

    private function cobrar(Pedido $pedido): Venta
    {
        $admin = $this->admin();

        return Pedidos::cobrar($pedido, $this->turno($admin), $admin, [
            ['metodo_pago_id' => (int) MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null],
        ])->fresh();
    }

    private function ticket(Venta $venta): string
    {
        $comprobante = $venta->comprobante;
        $this->assertNotNull($comprobante, 'la venta salió sin comprobante: no hay ticket que mirar');

        return $this->actingAs($this->admin())
            ->get(route('comprobantes.imprimir', $comprobante))
            ->assertOk()
            ->getContent();
    }
}
