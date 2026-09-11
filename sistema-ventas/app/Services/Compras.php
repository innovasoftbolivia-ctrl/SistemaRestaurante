<?php

namespace App\Services;

use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Usuario;
use App\Support\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registro de una compra a proveedor: la factura completa, no línea por línea.
 *
 * El almacén ya podía cargar mercadería de a un producto, y eso se queda: llega
 * una caja suelta y se carga sin papeleo. Lo que faltaba era el otro caso, que
 * es el habitual — el distribuidor deja una factura con treinta líneas. Cargarla
 * producto por producto son treinta idas y vueltas, y al terminar no queda
 * ningún sitio donde abrir esa factura y comprobar que suma lo mismo que el
 * papel: las treinta entradas quedan sueltas en el kardex, unidas apenas por un
 * texto escrito a mano.
 *
 * Lo importante de cómo está hecho: esto NO es una segunda puerta al stock. Cada
 * línea pasa por {@see Inventario::ingreso()}, el mismo servicio de siempre, con
 * el `compra_id` de la cabecera. El kardex sigue siendo la única verdad sobre
 * las existencias; lo único que cambia es que ahora cada movimiento sabe de qué
 * documento vino.
 *
 * Todo ocurre dentro de una transacción: o entra la factura entera, o no entra
 * nada. Una compra a medias —diez líneas cargadas y veinte no— sería peor que
 * no haberla cargado, porque nadie sabría dónde se cortó.
 */
class Compras
{
    /** Reintentos ante un deadlock; mismo criterio que {@see Ventas}. */
    private const REINTENTOS = 3;

    /**
     * @param  array<int, array{
     *     producto_id: int,
     *     cantidad: float,
     *     costo_unitario: float,
     *     detalle?: ?string,
     *     actualizar_costo?: bool,
     *     vence?: ?string,
     *     lote?: ?string
     * }>  $lineas
     */
    public static function registrar(
        Usuario $usuario,
        Proveedor $proveedor,
        array $lineas,
        ?string $documentoExterno = null,
        ?string $observacion = null,
    ): Compra {
        if ($lineas === []) {
            throw new RuntimeException('La compra no tiene ninguna línea.');
        }

        return DB::transaction(function () use ($usuario, $proveedor, $lineas, $documentoExterno, $observacion) {
            $compra = Compra::create([
                'proveedor_id' => $proveedor->id,
                'usuario_id' => $usuario->id,
                'documento_externo' => $documentoExterno,
                'fecha' => now(),
                'observacion' => $observacion,
            ]);

            foreach ($lineas as $linea) {
                self::agregarLinea($compra, $linea);
            }

            Auditor::registrar('COMPRA_REGISTRADA', 'compras', $compra->id, [
                'proveedor' => $proveedor->razon_social,
                'documento' => $documentoExterno,
                'lineas' => count($lineas),
                'total' => $compra->fresh('detalle')->total,
            ], $usuario->id);

            return $compra->fresh(['detalle.producto', 'proveedor']);
        }, self::REINTENTOS);
    }

    /**
     * Una línea: el renglón del documento y la entrada al kardex que provoca.
     *
     * Los dos registros son la misma cosa vista de dos maneras —lo que dice el
     * papel y lo que pasó en el estante— y por eso se escriben juntos y dentro
     * de la misma transacción. `compra_detalle` guarda lo comprado aunque
     * mañana se corrija el stock por un conteo; el kardex guarda el efecto.
     *
     * @param  array<string, mixed>  $linea
     */
    private static function agregarLinea(Compra $compra, array $linea): void
    {
        $producto = Producto::with('unidadMedida')->findOrFail($linea['producto_id']);

        if (! $producto->activo) {
            throw new RuntimeException("«{$producto->nombre}» está descatalogado: no se puede comprar.");
        }

        $cantidad = round((float) $linea['cantidad'], 3);

        if ($cantidad <= 0) {
            throw new RuntimeException("La cantidad de «{$producto->nombre}» debe ser mayor que cero.");
        }

        // La misma regla que el mostrador y que el ingreso suelto: no entran
        // 2,5 gaseosas. Se comprueba aquí y no solo en el formulario porque el
        // servicio también lo llaman las pruebas y cualquier script interno.
        if (! $producto->unidadMedida?->permite_decimal && fmod($cantidad, 1.0) !== 0.0) {
            throw new RuntimeException("«{$producto->nombre}» se compra por unidad entera.");
        }

        $costo = round((float) $linea['costo_unitario'], 2);

        if ($costo < 0) {
            throw new RuntimeException("El costo de «{$producto->nombre}» no puede ser negativo.");
        }

        $detalle = CompraDetalle::create([
            'compra_id' => $compra->id,
            'producto_id' => $producto->id,
            'cantidad' => $cantidad,
            'costo_unitario' => $costo,
        ]);

        Inventario::ingreso(
            producto: $producto,
            cantidad: $cantidad,
            proveedorId: $compra->proveedor_id,
            documentoExterno: $compra->documento_externo,
            costoUnitario: $costo,
            motivo: self::motivo($linea['detalle'] ?? null),
            compraId: $compra->id,
            // La fecha que trae la caja abre la tanda. Se pasa siempre: si el
            // producto no lleva control de vencimiento, `Lotes` la ignora.
            vence: $linea['vence'] ?? null,
            lote: $linea['lote'] ?? null,
            compraDetalleId: $detalle->id,
        );

        if ($linea['actualizar_costo'] ?? false) {
            Costos::aplicar($producto, $costo, 'compra '.($compra->documento_externo ?: "#{$compra->id}"));
        }
    }

    /**
     * Lo que se lee en el kardex de esa línea.
     *
     * El proveedor y el documento ya viajan en columnas propias del movimiento,
     * así que aquí solo queda el desglose —«3 cajas de 24 + 5 sueltas»—, que es
     * lo único que el número por sí solo no puede contar.
     */
    private static function motivo(?string $detalle): ?string
    {
        return $detalle !== null ? mb_substr($detalle, 0, 255) : null;
    }

    /**
     * El total de una compra, formateado como importe.
     *
     * Vive aquí y no en la vista porque el listado y la ficha lo muestran igual,
     * y porque el total sale de `compra_detalle.importe`, que es una columna
     * generada por la base: sumarla a mano en dos sitios distintos sería la
     * forma más fácil de que un día no coincidieran.
     */
    public static function total(Compra $compra): string
    {
        return Config::importe($compra->total);
    }
}
