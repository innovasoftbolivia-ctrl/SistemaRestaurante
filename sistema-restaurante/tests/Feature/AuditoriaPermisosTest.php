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
use Tests\TestCase;

/**
 * Auditoría: ¿el portero de permisos deja pasar a quien no debe?
 *
 * Las pruebas normales verifican los caminos que el sistema SÍ hace. Esta hace
 * lo contrario: recorre TODAS las rutas que escriben y las golpea con cada rol,
 * incluidos los que no tienen nada que hacer ahí.
 *
 * No manda datos válidos a propósito: lo que se mide es el portero, no la
 * validación. Si contesta 403, cortó. Si contesta 422 o redirige, dejó pasar
 * y el que rechazó fue el formulario — que es una defensa mucho más débil,
 * porque basta mandar los datos bien para atravesarla.
 */
class AuditoriaPermisosTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Un id real por cada parámetro, para que la ruta resuelva.
     *
     * Las ventas, turnos y comprobantes se CREAN acá. La base de
     * pruebas arranca solo con catálogos, y sin esos registros el enlace de
     * modelos contesta 404 antes de que el portero de permisos llegue a
     * opinar: la prueba pasaría en verde sin haber medido nada.
     */
    private function ids(): array
    {
        $cajero = Usuario::where('usuario', 'cajero1')->firstOrFail();
        $sesion = Cajas::sesionDe($cajero)
            ?? Cajas::abrir(Caja::firstOrFail(), $cajero, 100);

        $producto = Producto::activos()->firstOrFail();

        $venta = Ventas::registrar(
            sesion: $sesion,
            usuario: $cajero,
            lineas: [['producto_id' => $producto->id, 'cantidad' => 2]],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')
                ->value('id'), 'monto' => null]],
        );

        $cobro = CobrosQr::generar($sesion->fresh(), $cajero, 10.0);

        // Un pedido abierto con una línea: sin ellos, las rutas de pedidos y de
        // cocina contestan 404 antes de que el portero llegue a opinar.
        $pedido = Pedidos::abrir(Pedido::LOCAL, $cajero);
        $linea = Pedidos::agregarLinea($pedido, $producto, 1, null, $cajero);

        return [
            'venta' => $venta->id,
            'pedido' => $pedido->id,
            'linea' => $linea->id,
            'sesion' => $sesion->id,
            'comprobante' => $venta->comprobante->id,
            'cobro' => $cobro->id,
            'producto' => DB::table('productos')->max('id'),
            'categoria' => DB::table('categorias')->max('id'),
            'cliente' => DB::table('clientes')->max('id'),
            'caja' => DB::table('cajas')->max('id'),
            'cargo' => DB::table('cargos')->max('id'),
            'empleado' => DB::table('empleados')->max('id'),
            'usuario' => DB::table('usuarios')->max('id'),
            'rol' => DB::table('roles')->max('id'),
        ];
    }

    /** Las rutas de escritura, con sus parámetros ya resueltos. */
    private function rutasDeEscritura(): array
    {
        $ids = $this->ids();
        $sueltas = ['login', 'logout', 'qr/aviso', 'storage/{path}'];
        $salida = [];

        foreach (Route::getRoutes() as $r) {
            $metodos = array_values(array_diff($r->methods(), ['GET', 'HEAD']));

            if (! $metodos || in_array($r->uri(), $sueltas, true)) {
                continue;
            }

            $uri = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $ids[$m[1]] ?? 1, $r->uri());
            $salida[] = [$metodos[0], '/'.$uri, $r->gatherMiddleware()];
        }

        return $salida;
    }

    /** El permiso que la ruta exige, si exige alguno. */
    private function permisosDe(array $middleware): array
    {
        foreach ($middleware as $mw) {
            if (is_string($mw) && str_starts_with($mw, 'permiso:')) {
                return explode(',', substr($mw, strlen('permiso:')));
            }
        }

        return [];
    }

    public function test_ningun_rol_atraviesa_una_puerta_que_no_le_corresponde(): void
    {
        $rutas = $this->rutasDeEscritura();
        $this->assertGreaterThan(25, count($rutas), 'se esperaban unas 30 rutas de escritura');

        $colados = [];
        $sinMedir = [];
        $revisadas = 0;

        foreach (['admin', 'cajero1', 'cocina1'] as $quien) {
            $usuario = Usuario::where('usuario', $quien)->firstOrFail();

            foreach ($rutas as [$metodo, $uri, $middleware]) {
                $exigidos = $this->permisosDe($middleware);

                if (! $exigidos) {
                    continue;   // rutas sin permiso: se revisan aparte
                }

                $deberiaPasar = false;
                foreach ($exigidos as $p) {
                    if ($usuario->tienePermiso(trim($p))) {
                        $deberiaPasar = true;
                    }
                }

                if ($deberiaPasar) {
                    continue;   // acá interesa el que NO debería pasar
                }

                $revisadas++;
                $r = $this->actingAs($usuario)->call($metodo, $uri, []);

                if ($r->status() === 404) {
                    // El enlace de modelos contesta antes que el portero: si
                    // esto aparece, la combinación NO se midió y hay que
                    // arreglar el dato, no darla por buena.
                    $sinMedir[] = sprintf('%-8s %-6s %s', $quien, $metodo, $uri);
                } elseif ($r->status() !== 403) {
                    $colados[] = sprintf('%-8s %-6s %-42s -> %d (debía ser 403)',
                        $quien, $metodo, $uri, $r->status());
                }
            }
        }

        fwrite(STDERR, sprintf(
            '
  [auditoría] %d rutas de escritura · %d combinaciones rol-ruta prohibidas probadas
',
            count($rutas), $revisadas));

        $this->assertGreaterThan(30, $revisadas, 'se probaron muy pocas combinaciones');
        $this->assertSame([], $sinMedir,
            '
Combinaciones que no llegaron al portero (404 del enlace de modelos):
  '
            .implode('
  ', $sinMedir).'
');
        $this->assertSame([], $colados,
            "\nHay puertas que dejan pasar a quien no debe:\n  ".implode("\n  ", $colados)."\n");
    }

    /** Sin sesión, ninguna ruta de escritura debe hacer nada. */
    public function test_sin_iniciar_sesion_no_se_escribe_nada(): void
    {
        $colados = [];

        foreach ($this->rutasDeEscritura() as [$metodo, $uri, $_]) {
            $r = $this->call($metodo, $uri, []);

            // 302 al login es lo correcto; 401/403 también cortan.
            $corta = in_array($r->status(), [401, 403], true)
                || ($r->status() === 302 && str_contains((string) $r->headers->get('Location'), 'login'));

            if (! $corta) {
                $colados[] = sprintf('%-6s %-42s -> %d %s', $metodo, $uri, $r->status(),
                    $r->headers->get('Location') ?? '');
            }
        }

        $this->assertSame([], $colados,
            "\nRutas de escritura alcanzables sin sesión:\n  ".implode("\n  ", $colados)."\n");
    }
}
