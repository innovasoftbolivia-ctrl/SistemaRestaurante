<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * La bitácora de operaciones, para leerla.
 *
 * El sistema ya registraba mucho —ingresos fallidos, bloqueos, altas, bajas,
 * cambios de precio, anulaciones— pero no había pantalla: para saber quién
 * anuló una venta había que entrar a la base. Esto es solo lectura. Nada de lo
 * registrado se puede editar ni borrar desde acá, que es justamente lo que le
 * da valor.
 */
class BitacoraController extends Controller
{
    /**
     * A qué pantalla lleva cada entidad registrada, cuando tiene una.
     *
     * Las mesas ya no existen (se retiraron el 2026-09-18), pero la bitácora de
     * una instalación vieja conserva sus registros: se leen por su nombre y no
     * llevan enlace.
     */
    private const ENLACES = [
        'ventas' => 'ventas.show',
        'empleados' => 'empleados.show',
        'productos' => 'productos.show',
        'usuarios' => 'usuarios.edit',
        'sesiones_caja' => 'caja.show',
        'comprobantes' => 'comprobantes.imprimir',
        // Un pedido lleva a su comanda: la hoja con su número, su destino y
        // sus platos, que no toca nada al abrirse.
        'pedidos' => 'pedidos.comanda',
    ];

    /**
     * El nombre de cada entidad en singular, para leer «Venta #12».
     *
     * Compras, proveedores, tomas y devoluciones ya no existen como módulo,
     * pero sus nombres se quedan aquí: la bitácora de una instalación vieja
     * todavía tiene registros suyos y sin esto saldrían con el nombre crudo de
     * la tabla.
     */
    private const ENTIDADES = [
        'ventas' => 'Venta',
        'compras' => 'Compra',
        'devoluciones' => 'Devolución',
        'devoluciones_compra' => 'Devolución a proveedor',
        'empleados' => 'Empleado',
        'productos' => 'Ítem del menú',
        'usuarios' => 'Usuario',
        'sesiones_caja' => 'Turno de caja',
        'comprobantes' => 'Comprobante',
        'cajas' => 'Caja',
        'cargos' => 'Cargo',
        'categorias' => 'Categoría',
        'clientes' => 'Cliente',
        'proveedores' => 'Proveedor',
        'roles' => 'Rol',
        'cobros_qr' => 'Cobro QR',
        'configuracion' => 'Configuración',
        'tomas_inventario' => 'Toma de inventario',
        'pedidos' => 'Pedido',
        'pedido_detalle' => 'Plato del pedido',
        'mesas' => 'Mesa',
        'movimientos_caja' => 'Movimiento de caja',
    ];

    /**
     * Palabras del código de acción que necesitan acento o cambian de nombre.
     * `strtr` reemplaza primero lo más largo, así «login fallido» gana sobre
     * «login».
     */
    private const PALABRAS = [
        'login fallido' => 'ingreso fallido',
        'login bloqueado' => 'ingreso bloqueado',
        'login' => 'ingreso',
        'logout' => 'salida',
        'password cambiada' => 'contraseña cambiada',
        'anular venta' => 'venta anulada',
        'sustituir comprobante' => 'comprobante sustituido',
        'configuracion' => 'configuración',
        'toma inventario' => 'toma de inventario',
        // El código guardado sigue diciendo PRODUCTO_*: lo que cambia es cómo
        // se lee en pantalla, donde el módulo se llama «Menú».
        'producto descatalogado' => 'ítem retirado del menú',
        'producto creado' => 'ítem agregado al menú',
        'producto eliminado' => 'ítem quitado del menú',
        'producto actualizado' => 'ítem del menú actualizado',
        // Los PEDIDO_*: el pedido y sus platos. «Quitado» y «no reabierto» ya
        // no se registran, pero pueden quedar en bitácoras anteriores.
        'pedido linea agregada' => 'plato agregado al pedido',
        'pedido linea quitada' => 'plato quitado del pedido',
        'pedido linea estado' => 'plato movido en la cocina',
        'pedido no reabierto' => 'cobro anulado: el pedido no se pudo reabrir',
        'pedido reabierto' => 'cobro anulado: el pedido se vuelve a cobrar',
        'devolucion' => 'devolución',
        'categoria' => 'categoría',
        'fisica' => 'física',
        'qr' => 'QR',
    ];

    public function index(Request $request): View
    {
        $filtros = $request->validate([
            'usuario' => ['nullable', 'integer'],
            'accion' => ['nullable', 'string', 'max:50'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'buscar' => ['nullable', 'string', 'max:100'],
        ], [
            'hasta.after_or_equal' => 'La fecha «hasta» no puede ser anterior a «desde».',
        ]);

        $buscar = trim((string) ($filtros['buscar'] ?? ''));

        $registros = Auditoria::query()
            ->with('usuario:id,usuario,empleado_id', 'usuario.empleado:id,nombre_completo')
            ->when($filtros['usuario'] ?? null, fn ($q, $id) => $q->where('usuario_id', $id))
            ->when($filtros['accion'] ?? null, fn ($q, $accion) => $q->where('accion', $accion))
            ->when($filtros['desde'] ?? null, fn ($q, $desde) => $q->where('fecha', '>=', $desde.' 00:00:00'))
            ->when($filtros['hasta'] ?? null, fn ($q, $hasta) => $q->where('fecha', '<=', $hasta.' 23:59:59'))
            ->when($buscar !== '', function ($q) use ($buscar) {
                $q->where(function ($sub) use ($buscar) {
                    if (ctype_digit($buscar)) {
                        $sub->orWhere('entidad_id', (int) $buscar);
                    }
                    $sub->orWhere('ip', 'like', "%{$buscar}%")
                        // El detalle es JSON: se lo compara como texto. Encuentra
                        // un nombre o un precio que alguien cambió.
                        ->orWhereRaw('CAST(detalle AS CHAR) LIKE ?', ["%{$buscar}%"]);
                });
            })
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('bitacora.index', [
            'title' => 'Bitácora',
            'registros' => $registros,
            'filtros' => [
                'usuario' => $filtros['usuario'] ?? null,
                'accion' => $filtros['accion'] ?? null,
                'desde' => $filtros['desde'] ?? null,
                'hasta' => $filtros['hasta'] ?? null,
                'buscar' => $buscar,
            ],
            // Solo lo que de verdad aparece en la bitácora: un desplegable con
            // sesenta acciones de las que la mitad nunca pasó no ayuda a buscar.
            'acciones' => Auditoria::query()->distinct()->orderBy('accion')->pluck('accion')
                ->mapWithKeys(fn (string $a) => [$a => self::accion($a)])->all(),
            'usuarios' => Usuario::query()->orderBy('usuario')->pluck('usuario', 'id')->all(),
        ]);
    }

    /** «CONFIGURACION_ACTUALIZADA» → «Configuración actualizada». */
    public static function accion(string $codigo): string
    {
        $texto = strtr(mb_strtolower(str_replace('_', ' ', $codigo)), self::PALABRAS);

        return mb_strtoupper(mb_substr($texto, 0, 1)).mb_substr($texto, 1);
    }

    /** «Venta #12», o el nombre de la tabla si no se conoce. */
    public static function entidad(?string $entidad, int|string|null $id): ?string
    {
        if (! $entidad) {
            return null;
        }

        $nombre = self::ENTIDADES[$entidad] ?? ucfirst(str_replace('_', ' ', $entidad));

        return $id ? "{$nombre} #{$id}" : $nombre;
    }

    /**
     * La pantalla de la entidad, si tiene una y el registro guarda su id.
     *
     * Un plato no tiene pantalla: lleva al pedido donde está, que su
     * registro guarda en el detalle.
     */
    public static function enlace(?string $entidad, int|string|null $id, ?array $detalle = null): ?string
    {
        if ($entidad === 'pedido_detalle') {
            [$entidad, $id] = ['pedidos', $detalle['pedido_id'] ?? null];
        }

        $ruta = self::ENLACES[$entidad] ?? null;

        return $ruta && $id && Route::has($ruta) ? route($ruta, $id) : null;
    }

    /**
     * El detalle, en filas que se leen: los cambios con su valor anterior
     * («3.98 → 4.20») y el resto tal cual.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function detalle(?array $detalle): array
    {
        $filas = [];

        foreach ($detalle ?? [] as $clave => $valor) {
            // Los registros viejos guardaron la clave `producto`. En pantalla
            // el módulo se llama «Menú», así que se lee como el resto.
            $etiqueta = $clave === 'producto' ? 'plato' : str_replace('_', ' ', (string) $clave);

            if (is_array($valor) && array_key_exists('antes', $valor) && array_key_exists('despues', $valor)) {
                $filas[] = [$etiqueta, self::valor($valor['antes']).' → '.self::valor($valor['despues'])];
            } else {
                $filas[] = [$etiqueta, self::valor($valor)];
            }
        }

        return $filas;
    }

    private static function valor(mixed $valor): string
    {
        return match (true) {
            $valor === null, $valor === '' => '—',
            is_bool($valor) => $valor ? 'sí' : 'no',
            is_array($valor) => json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $valor,
        };
    }
}
