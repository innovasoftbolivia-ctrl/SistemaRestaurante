<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Usuario;
use App\Services\Compras;
use App\Services\DevolucionesCompra;
use App\Services\TomasInventario;

/**
 * Un registro real detrás de cada parámetro del inventario —proveedor,
 * compra, devolución, toma—: sin ellos el enlace de modelos contesta 404
 * antes de que la pantalla o el portero lleguen a opinar.
 */
trait ConDatosDeInventario
{
    /** @return array{proveedor: int, compra: int, devolucion: int, toma: int, bebida: int} */
    private function datosDeInventario(Usuario $admin): array
    {
        $bebida = Producto::where('codigo', 'P-0005')->firstOrFail();
        $bebida->forceFill([
            'controla_stock' => true, 'contenido_empaque' => 12, 'nombre_empaque' => 'Caja',
            'stock_minimo' => 6, 'costo' => 4, 'stock_actual' => 0,
        ])->save();

        $proveedor = Proveedor::create(['razon_social' => 'Proveedor del recorrido', 'documento' => '7654321']);
        $compra = Compras::registrar($proveedor, $admin, [
            ['producto_id' => $bebida->id, 'cantidad' => 24, 'costo_unitario' => 4],
        ], 'F-001');

        // Pendiente: el proveedor todavía debe, así la pantalla muestra la reposición.
        $devolucion = DevolucionesCompra::registrar($compra, $admin, [
            ['compra_detalle_id' => $compra->detalle()->value('id'), 'cantidad' => 2],
        ], 'DEFECTO', 'PENDIENTE');

        $toma = TomasInventario::abierta() ?? TomasInventario::abrir($admin);

        return [
            'proveedor' => $proveedor->id,
            'compra' => $compra->id,
            'devolucion' => $devolucion->id,
            'toma' => $toma->id,
            'bebida' => $bebida->id,
        ];
    }
}
