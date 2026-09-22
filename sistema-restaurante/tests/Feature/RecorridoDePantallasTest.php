<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Pedidos;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Recorre TODAS las pantallas GET con cada rol, con datos reales detrás de cada
 * parámetro, con la facturación visible y oculta. Ninguna puede responder un
 * error de servidor: lo que no le toca a un rol es 403, no 500.
 */
class RecorridoDePantallasTest extends TestCase
{
    use ConDatosDeInventario;
    use DatabaseTransactions;

    /** @return array<string, array{0: bool}> */
    public static function modos(): array
    {
        return ['facturación oculta' => [false], 'facturación visible' => [true]];
    }

    #[DataProvider('modos')]
    public function test_ninguna_pantalla_da_error_de_servidor_con_ningun_rol(bool $facturacion): void
    {
        config(['restaurante.mostrar_facturacion' => $facturacion]);

        $admin = Usuario::where('usuario', 'admin')->firstOrFail();
        $cajero = Usuario::where('usuario', 'cajero1')->firstOrFail();
        $cocina = Usuario::where('usuario', 'cocina1')->firstOrFail();

        // ---- datos detrás de cada parámetro
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();

        $turno = Cajas::abrir(Caja::firstOrFail(), $cajero, 100);
        $venta = Ventas::registrar(
            sesion: $turno->fresh(), usuario: $cajero,
            lineas: [['producto_id' => $producto->id, 'cantidad' => 3]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
        $cobro = CobrosQr::generar($turno->fresh(), $cajero, 5);

        // Un pedido con el cobro anulado y un plato dentro: es lo que necesita la
        // pantalla de cobro para tener algo que mostrar.
        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);
        Pedidos::agregarLinea($pedido, $producto, 2, 'sin cebolla', $cajero);

        // Y uno para llevar ya cobrado con el plato por hacer: la cocina lo
        // sigue mostrando, marcado como cobrado.
        $llevar = Pedidos::abrir(Pedido::LLEVAR, $cajero, nombreCliente: 'Ana');
        Pedidos::agregarLinea($llevar, $producto, 1, null, $cajero);
        Pedidos::cobrar($llevar, $turno->fresh(), $cajero, [
            ['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null],
        ]);

        // Con el pedido de arriba todavía sin volver a cobrar: se cierra sabiéndolo.
        $cerrado = Cajas::abrir(Caja::create(['nombre' => 'Caja recorrido', 'activo' => 1]), $admin, 50);
        Cajas::cerrar($cerrado->fresh(), $admin, 50, null, 0, $cerrado->fresh()->huella(), conCuentasAbiertas: true);

        $inventario = $this->datosDeInventario($admin);

        $valores = [
            'sesion' => $turno->id,
            'comprobante' => $venta->comprobante->id,
            'empleado' => DB::table('empleados')->value('id'),
            // La bebida: lleva stock, así el kardex también se recorre.
            'producto' => $inventario['bebida'],
            'usuario' => $cajero->id,
            'venta' => $venta->id,
            'cobro' => $cobro->id,
            'pedido' => $pedido->id,
            'linea' => $pedido->detalle()->value('id'),
            'proveedor' => $inventario['proveedor'],
            'compra' => $inventario['compra'],
            'devolucion' => $inventario['devolucion'],
            'toma' => $inventario['toma'],
        ];

        $fallas = [];
        $visitadas = 0;

        foreach (Route::getRoutes() as $ruta) {
            if (! in_array('GET', $ruta->methods(), true)) {
                continue;
            }

            $uri = $ruta->uri();

            // Fuera del recorrido: los que no son pantallas del sistema.
            if (in_array($uri, ['up', 'login', 'storage/{path}'], true) || str_starts_with($uri, '_')) {
                continue;
            }

            $faltan = false;
            $url = '/'.ltrim(preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($valores, &$faltan) {
                if (! isset($valores[$m[1]])) {
                    $faltan = true;

                    return '';
                }

                return $valores[$m[1]];
            }, $uri), '/');

            if ($faltan) {
                $fallas[] = "{$uri}: parámetro sin dato en el recorrido";

                continue;
            }

            foreach (['admin' => $admin, 'cajero1' => $cajero, 'cocina1' => $cocina] as $nombre => $usuario) {
                $this->flushSession();
                app('auth')->forgetGuards();
                DB::table('sesiones_caja')->where('id', $cerrado->id)->exists();

                $respuesta = $this->actingAs($usuario)->get($url);
                $visitadas++;

                if ($respuesta->getStatusCode() >= 500) {
                    $mensaje = $respuesta->exception ? get_class($respuesta->exception).': '.mb_substr($respuesta->exception->getMessage(), 0, 200) : '';
                    $fallas[] = "{$nombre} GET {$url} → {$respuesta->getStatusCode()} {$mensaje}";
                }
            }

            // La del turno cerrado también, para el resumen imprimible.
            if ($uri === 'caja/{sesion}/imprimir') {
                foreach (['admin' => $admin, 'cajero1' => $cajero, 'cocina1' => $cocina] as $nombre => $usuario) {
                    $this->flushSession();
                    app('auth')->forgetGuards();
                    $respuesta = $this->actingAs($usuario)->get("/caja/{$cerrado->id}/imprimir");
                    $visitadas++;

                    if ($respuesta->getStatusCode() >= 500) {
                        $fallas[] = "{$nombre} GET /caja/{$cerrado->id}/imprimir → {$respuesta->getStatusCode()}";
                    }
                }
            }
        }

        // Unas cuarenta pantallas por tres roles (admin, cajero y cocina).
        $this->assertGreaterThan(110, $visitadas, 'el recorrido no llegó a las pantallas');
        $this->assertSame([], $fallas, implode("\n", $fallas));
    }
}
