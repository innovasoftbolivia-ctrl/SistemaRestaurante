<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Devolucion;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\TomaInventarioDetalle;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\CobrosQr;
use App\Services\Compras;
use App\Services\Devoluciones;
use App\Services\DevolucionesCompra;
use App\Services\TomasInventario;
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
    use DatabaseTransactions;

    /** @return array<string, array{0: bool}> */
    public static function modos(): array
    {
        return ['facturación oculta' => [false], 'facturación visible' => [true]];
    }

    #[DataProvider('modos')]
    public function test_ninguna_pantalla_da_error_de_servidor_con_ningun_rol(bool $facturacion): void
    {
        config(['ventas.mostrar_facturacion' => $facturacion]);

        $admin = Usuario::where('usuario', 'admin')->firstOrFail();
        $cajero = Usuario::where('usuario', 'cajero1')->firstOrFail();
        $almacen = Usuario::where('usuario', 'almacen')->firstOrFail();

        // ---- datos detrás de cada parámetro
        $this->actingAs($almacen);
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();
        $producto->forceFill(['stock_actual' => 100])->save();
        $compra = Compras::registrar($almacen, Proveedor::firstOrFail(), [
            ['producto_id' => $producto->id, 'cantidad' => 10, 'costo_unitario' => 2],
        ], 'F-RECORRIDO');
        $devCompra = DevolucionesCompra::registrar($almacen, $compra, [
            ['compra_detalle_id' => $compra->detalle()->firstOrFail()->id, 'cantidad' => 1],
        ], 'DEFECTO', 'NOTA_CREDITO');
        $toma = TomasInventario::abrir($almacen);
        TomasInventario::contar(TomaInventarioDetalle::where('toma_id', $toma->id)->firstOrFail(), $almacen, 1);

        $turno = Cajas::abrir(Caja::firstOrFail(), $cajero, 100);
        $venta = Ventas::registrar(
            sesion: $turno->fresh(), usuario: $cajero,
            lineas: [['producto_id' => $producto->id, 'cantidad' => 3]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
        $devolucion = Devoluciones::registrar($venta->fresh(), $admin, $turno->fresh(), [
            ['venta_detalle_id' => $venta->detalle->first()->id, 'cantidad' => 1, 'reingresa_stock' => true],
        ], 'Recorrido de pantallas', Devolucion::EFECTIVO);
        $cobro = CobrosQr::generar($turno->fresh(), $cajero, 5);
        $cerrado = Cajas::abrir(Caja::create(['nombre' => 'Caja recorrido', 'activo' => 1]), $admin, 50);
        Cajas::cerrar($cerrado->fresh(), $admin, 50, null, 0, $cerrado->fresh()->huella());

        $valores = [
            'sesion' => $turno->id,
            'compra' => $compra->id,
            'comprobante' => $venta->comprobante->id,
            'devolucion' => $devolucion->id,
            'devolucionCompra' => $devCompra->id,
            'empleado' => DB::table('empleados')->value('id'),
            'producto' => $producto->id,
            'toma' => $toma->id,
            'usuario' => $cajero->id,
            'venta' => $venta->id,
            'cobro' => $cobro->id,
        ];

        $fallas = [];
        $visitadas = 0;

        foreach (Route::getRoutes() as $ruta) {
            if (! in_array('GET', $ruta->methods(), true)) {
                continue;
            }

            $uri = $ruta->uri();

            // Fuera del recorrido: los que no son pantallas del sistema.
            if (in_array($uri, ['up', 'login', 'storage/{path}', 'respaldos/{nombre}/descargar'], true) || str_starts_with($uri, '_')) {
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

            foreach (['admin' => $admin, 'cajero1' => $cajero, 'almacen' => $almacen] as $nombre => $usuario) {
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
                foreach (['admin' => $admin, 'cajero1' => $cajero, 'almacen' => $almacen] as $nombre => $usuario) {
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

        $this->assertGreaterThan(150, $visitadas, 'el recorrido no llegó a las pantallas');
        $this->assertSame([], $fallas, implode("\n", $fallas));
    }
}
