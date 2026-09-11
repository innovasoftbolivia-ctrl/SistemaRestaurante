<?php

namespace Tests\Feature;

use App\Models\Compra;
use App\Models\DevolucionCompra;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Usuario;
use App\Services\Compras;
use App\Services\DevolucionesCompra;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

/**
 * La mercadería que se va de vuelta al proveedor.
 *
 * Antes de esto la única salida era un ajuste de inventario con el motivo
 * escrito a mano: bajaba el stock, sí, pero quedaba mezclado con la merma y la
 * rotura, no se sabía de qué factura había salido y nadie podía responder
 * después «cuánto le devolví a este proveedor». Lo que estas pruebas defienden
 * es eso: que la salida quede contada, atada a su compra y separable por motivo.
 *
 * Y que el cambio no sea un módulo aparte. Cambiar lo fallado por lo bueno es
 * esta misma devolución con `con_reposicion`: sale y entra, el stock termina
 * igual que antes —que es lo que pasó en el mostrador— pero queda escrito que
 * hubo un problema, cosa que un ajuste a cero nunca podría contar.
 */
class DevolucionCompraTest extends TestCase
{
    use DatabaseTransactions;

    private function almacenero(): Usuario
    {
        return Usuario::where('usuario', 'almacen')->firstOrFail();
    }

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    private function proveedor(): Proveedor
    {
        return Proveedor::activos()->firstOrFail();
    }

    /** Un producto por unidad entera, sin empaque y sin control de fecha. */
    private function producto(): Producto
    {
        $producto = Producto::activos()
            ->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 0))
            ->firstOrFail();

        $producto->forceFill([
            'contenido_empaque' => null,
            'nombre_empaque' => null,
            'controla_vencimiento' => 0,
        ])->save();

        return $producto->fresh();
    }

    /** El mismo caso, pero llevando tandas con fecha. */
    private function perecedero(): Producto
    {
        $producto = Producto::activos()
            ->whereHas('unidadMedida', fn ($q) => $q->where('permite_decimal', 0))
            ->skip(1)
            ->firstOrFail();

        $producto->forceFill([
            'contenido_empaque' => null,
            'nombre_empaque' => null,
            'controla_vencimiento' => 1,
        ])->save();

        Lote::where('producto_id', $producto->id)->delete();
        $producto->forceFill(['stock_actual' => 0])->save();

        return $producto->fresh();
    }

    /**
     * Una compra de una línea, ya cargada al stock.
     *
     * @param  array<int, array<string, mixed>>|null  $lineas
     */
    private function compra(?array $lineas = null, ?Producto $producto = null): Compra
    {
        $producto ??= $this->producto();

        return Compras::registrar(
            usuario: $this->almacenero(),
            proveedor: $this->proveedor(),
            lineas: $lineas ?? [[
                'producto_id' => $producto->id,
                'cantidad' => 24,
                'costo_unitario' => '4.00',
            ]],
            documentoExterno: 'F001-00999',
        );
    }

    // -------------------------------------------------------------- permisos

    public function test_el_almacenero_devuelve_y_el_cajero_no(): void
    {
        $compra = $this->compra();

        $this->actingAs($this->almacenero())
            ->get(route('devoluciones-compra.create', $compra))
            ->assertOk();

        $this->actingAs($this->cajero())
            ->get(route('devoluciones-compra.create', $compra))
            ->assertForbidden();

        // Lo que importa de verdad: que tampoco pueda registrarla a mano.
        $this->actingAs($this->cajero())
            ->post(route('devoluciones-compra.store', $compra), [
                'motivo' => 'DEFECTO',
                'lineas' => [[
                    'compra_detalle_id' => $compra->detalle->first()->id,
                    'cantidad' => 1,
                ]],
            ])
            ->assertForbidden();

        $this->actingAs($this->cajero())
            ->get(route('devoluciones-compra.index'))
            ->assertForbidden();
    }

    // ------------------------------------------------------ la salida básica

    public function test_devolver_baja_el_stock_y_deja_su_movimiento(): void
    {
        $producto = $this->producto();
        $compra = $this->compra(producto: $producto);
        $antes = (float) $producto->fresh()->stock_actual;

        $devolucion = DevolucionesCompra::registrar(
            usuario: $this->almacenero(),
            compra: $compra,
            lineas: [['compra_detalle_id' => $compra->detalle->first()->id, 'cantidad' => 6]],
            motivo: 'DEFECTO',
            documentoExterno: 'NC-0001',
        );

        $this->assertSame($antes - 6, (float) $producto->fresh()->stock_actual);
        $this->assertSame(24.00, $devolucion->total);

        $movimiento = MovimientoInventario::where('devolucion_compra_id', $devolucion->id)->sole();
        $this->assertSame('SALIDA', $movimiento->tipo);
        $this->assertSame('DEVOLUCION_COMPRA', $movimiento->origen);
        $this->assertSame($this->proveedor()->id, $movimiento->proveedor_id);
        $this->assertSame('NC-0001', $movimiento->documento_externo);
    }

    /** Un ajuste explica un descuadre; esto explica una salida con destinatario. */
    public function test_no_se_confunde_con_un_ajuste(): void
    {
        $compra = $this->compra();

        $devolucion = DevolucionesCompra::registrar(
            usuario: $this->almacenero(),
            compra: $compra,
            lineas: [['compra_detalle_id' => $compra->detalle->first()->id, 'cantidad' => 2]],
            motivo: 'ERROR',
        );

        $this->assertSame(
            0,
            MovimientoInventario::where('devolucion_compra_id', $devolucion->id)
                ->where('origen', 'AJUSTE')
                ->count(),
        );
    }

    // ------------------------------------------------------------- el tope

    public function test_no_se_devuelve_mas_de_lo_que_trajo_la_factura(): void
    {
        $compra = $this->compra();

        $this->expectException(RuntimeException::class);

        DevolucionesCompra::registrar(
            usuario: $this->almacenero(),
            compra: $compra,
            lineas: [['compra_detalle_id' => $compra->detalle->first()->id, 'cantidad' => 25]],
            motivo: 'DEFECTO',
        );
    }

    public function test_lo_ya_devuelto_se_descuenta_del_tope(): void
    {
        $compra = $this->compra();
        $linea = $compra->detalle->first();

        DevolucionesCompra::registrar(
            usuario: $this->almacenero(),
            compra: $compra,
            lineas: [['compra_detalle_id' => $linea->id, 'cantidad' => 20]],
            motivo: 'DEFECTO',
        );

        $this->assertSame(20.0, (float) $linea->fresh()->cantidad_devuelta);
        $this->assertSame(4.0, $linea->fresh()->pendiente_devolucion);

        // Quedaban 4: pedir 5 tiene que fallar, y sin dejar nada a medias.
        try {
            DevolucionesCompra::registrar(
                usuario: $this->almacenero(),
                compra: $compra,
                lineas: [['compra_detalle_id' => $linea->id, 'cantidad' => 5]],
                motivo: 'DEFECTO',
            );
            $this->fail('Se devolvieron más unidades de las que quedaban.');
        } catch (RuntimeException) {
            $this->assertSame(20.0, (float) $linea->fresh()->cantidad_devuelta);
            $this->assertSame(1, DevolucionCompra::where('compra_id', $compra->id)->count());
        }
    }

    /** Una línea de otra factura no entra aquí ni por descuido ni a propósito. */
    public function test_la_linea_tiene_que_ser_de_esta_compra(): void
    {
        $compra = $this->compra();
        $otra = $this->compra();

        $this->expectException(RuntimeException::class);

        DevolucionesCompra::registrar(
            usuario: $this->almacenero(),
            compra: $compra,
            lineas: [['compra_detalle_id' => $otra->detalle->first()->id, 'cantidad' => 1]],
            motivo: 'DEFECTO',
        );
    }

    // -------------------------------------------------------------- el cambio

    public function test_el_cambio_deja_el_stock_igual_pero_registrado(): void
    {
        $producto = $this->producto();
        $compra = $this->compra(producto: $producto);
        $antes = (float) $producto->fresh()->stock_actual;

        $devolucion = DevolucionesCompra::registrar(
            usuario: $this->almacenero(),
            compra: $compra,
            lineas: [['compra_detalle_id' => $compra->detalle->first()->id, 'cantidad' => 10]],
            motivo: 'DEFECTO',
            conReposicion: true,
        );

        // El stock termina como estaba: es lo que pasó en el mostrador.
        $this->assertSame($antes, (float) $producto->fresh()->stock_actual);

        // Pero quedó escrito que hubo un problema, en dos movimientos.
        $movimientos = MovimientoInventario::where('devolucion_compra_id', $devolucion->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $movimientos);
        $this->assertSame('SALIDA', $movimientos[0]->tipo);
        $this->assertSame('ENTRADA', $movimientos[1]->tipo);
        $this->assertSame('DEVOLUCION_COMPRA', $movimientos[1]->origen);
        $this->assertTrue($devolucion->con_reposicion);
    }

    // ----------------------------------------------------------- vencimiento

    public function test_lo_vencido_sale_de_su_tanda_y_no_de_la_que_toca(): void
    {
        $producto = $this->perecedero();

        $compra = $this->compra(lineas: [
            // Dos tandas del mismo producto en la misma factura: una que vence
            // pronto y otra que aguanta.
            ['producto_id' => $producto->id, 'cantidad' => 10, 'costo_unitario' => '2.00',
                'vence' => now()->addDays(5)->toDateString()],
            ['producto_id' => $producto->id, 'cantidad' => 10, 'costo_unitario' => '2.00',
                'vence' => now()->addYear()->toDateString()],
        ], producto: $producto);

        $lejano = Lote::where('producto_id', $producto->id)
            ->orderByDesc('fecha_vencimiento')
            ->firstOrFail();

        // Se devuelve EL lote lejano a propósito: si el servicio despachara por
        // orden de salida, se comería el que vence pronto y la alerta mentiría.
        DevolucionesCompra::registrar(
            usuario: $this->almacenero(),
            compra: $compra,
            lineas: [[
                'compra_detalle_id' => $compra->detalle->last()->id,
                'cantidad' => 4,
                'lote_id' => $lejano->id,
            ]],
            motivo: 'VENCIMIENTO',
        );

        $this->assertSame(6.0, (float) $lejano->fresh()->cantidad_actual);
        $this->assertSame(
            10.0,
            (float) Lote::where('producto_id', $producto->id)
                ->orderBy('fecha_vencimiento')
                ->firstOrFail()
                ->cantidad_actual,
        );
    }

    /** La regla de siempre: los lotes son el stock repartido, nunca otra cuenta. */
    public function test_los_lotes_siguen_cuadrando_con_el_stock(): void
    {
        $producto = $this->perecedero();

        $compra = $this->compra(lineas: [[
            'producto_id' => $producto->id,
            'cantidad' => 12,
            'costo_unitario' => '3.00',
            'vence' => now()->addMonths(2)->toDateString(),
        ]], producto: $producto);

        DevolucionesCompra::registrar(
            usuario: $this->almacenero(),
            compra: $compra,
            lineas: [['compra_detalle_id' => $compra->detalle->first()->id, 'cantidad' => 5]],
            motivo: 'VENCIMIENTO',
        );

        $this->assertSame(
            (float) $producto->fresh()->stock_actual,
            (float) Lote::where('producto_id', $producto->id)->sum('cantidad_actual'),
        );
    }

    /** Lo repuesto entra con SU fecha: el reemplazo de lo vencido no vence igual. */
    public function test_la_reposicion_abre_su_propia_tanda(): void
    {
        $producto = $this->perecedero();
        $vieja = now()->addDays(3)->toDateString();
        $nueva = now()->addMonths(8)->toDateString();

        $compra = $this->compra(lineas: [[
            'producto_id' => $producto->id,
            'cantidad' => 6,
            'costo_unitario' => '3.00',
            'vence' => $vieja,
        ]], producto: $producto);

        DevolucionesCompra::registrar(
            usuario: $this->almacenero(),
            compra: $compra,
            lineas: [[
                'compra_detalle_id' => $compra->detalle->first()->id,
                'cantidad' => 6,
                'vence_repuesto' => $nueva,
            ]],
            motivo: 'VENCIMIENTO',
            conReposicion: true,
        );

        $lotes = Lote::where('producto_id', $producto->id)->get();

        $this->assertCount(2, $lotes);
        $this->assertSame(
            6.0,
            (float) $lotes->first(fn ($l) => $l->fecha_vencimiento?->toDateString() === $nueva)->cantidad_actual,
        );
        $this->assertSame(
            0.0,
            (float) $lotes->first(fn ($l) => $l->fecha_vencimiento?->toDateString() === $vieja)->cantidad_actual,
        );
    }

    // ------------------------------------------------------------- el conteo

    /**
     * Lo que justifica el ENUM de motivos: «Bs 900 por vencimiento» es una
     * conversación con el proveedor distinta de «Bs 900 porque vino fallado».
     */
    public function test_lo_devuelto_se_cuenta_por_motivo(): void
    {
        $compra = $this->compra();
        $linea = $compra->detalle->first();

        DevolucionesCompra::registrar(
            usuario: $this->almacenero(),
            compra: $compra,
            lineas: [['compra_detalle_id' => $linea->id, 'cantidad' => 3]],
            motivo: 'DEFECTO',
        );

        DevolucionesCompra::registrar(
            usuario: $this->almacenero(),
            compra: $compra,
            lineas: [['compra_detalle_id' => $linea->id, 'cantidad' => 5]],
            motivo: 'VENCIMIENTO',
        );

        $porMotivo = DevolucionesCompra::porMotivo(now()->subMinute()->toDateTimeString())
            ->get()
            ->keyBy('motivo');

        $this->assertSame('12.00', (string) $porMotivo['DEFECTO']->total);
        $this->assertSame('20.00', (string) $porMotivo['VENCIMIENTO']->total);
    }

    // ------------------------------------------------------------- pantallas

    public function test_la_compra_muestra_lo_que_se_le_devolvio(): void
    {
        $compra = $this->compra();

        $devolucion = DevolucionesCompra::registrar(
            usuario: $this->almacenero(),
            compra: $compra,
            lineas: [['compra_detalle_id' => $compra->detalle->first()->id, 'cantidad' => 7]],
            motivo: 'DEFECTO',
            documentoExterno: 'NC-0042',
        );

        $this->actingAs($this->almacenero())
            ->get(route('compras.show', $compra))
            ->assertOk()
            ->assertSee('NC-0042')
            ->assertSee('Devuelto al proveedor');

        $this->actingAs($this->almacenero())
            ->get(route('devoluciones-compra.show', $devolucion))
            ->assertOk()
            ->assertSee('Vino fallado');

        $this->actingAs($this->almacenero())
            ->get(route('devoluciones-compra.index'))
            ->assertOk()
            ->assertSee('NC-0042');
    }

    /** Si ya no queda nada por devolver, no se ofrece el botón. */
    public function test_el_boton_desaparece_cuando_no_queda_nada_por_devolver(): void
    {
        $compra = $this->compra();

        $this->actingAs($this->almacenero())
            ->get(route('compras.show', $compra))
            ->assertSee(route('devoluciones-compra.create', $compra), escape: false);

        DevolucionesCompra::registrar(
            usuario: $this->almacenero(),
            compra: $compra,
            lineas: [['compra_detalle_id' => $compra->detalle->first()->id, 'cantidad' => 24]],
            motivo: 'DEFECTO',
        );

        $this->actingAs($this->almacenero())
            ->get(route('compras.show', $compra))
            ->assertDontSee(route('devoluciones-compra.create', $compra), escape: false);
    }

    public function test_el_formulario_pide_un_motivo(): void
    {
        $compra = $this->compra();

        $this->actingAs($this->almacenero())
            ->post(route('devoluciones-compra.store', $compra), [
                'lineas' => [['compra_detalle_id' => $compra->detalle->first()->id, 'cantidad' => 1]],
            ])
            ->assertSessionHasErrors('motivo');

        $this->assertSame(0, DevolucionCompra::where('compra_id', $compra->id)->count());
    }

    public function test_el_formulario_pide_al_menos_una_linea(): void
    {
        $compra = $this->compra();

        $this->actingAs($this->almacenero())
            ->post(route('devoluciones-compra.store', $compra), ['motivo' => 'DEFECTO'])
            ->assertSessionHasErrors('lineas');
    }

    public function test_el_formulario_registra_la_devolucion_entera(): void
    {
        $producto = $this->producto();
        $compra = $this->compra(producto: $producto);
        $antes = (float) $producto->fresh()->stock_actual;

        $this->actingAs($this->almacenero())
            ->post(route('devoluciones-compra.store', $compra), [
                'motivo' => 'ERROR',
                'documento_externo' => 'NC-0100',
                'observacion' => 'Mandaron otro sabor',
                'lineas' => [[
                    'compra_detalle_id' => $compra->detalle->first()->id,
                    'cantidad' => 9,
                ]],
            ])
            ->assertRedirect();

        $devolucion = DevolucionCompra::where('compra_id', $compra->id)->sole();

        $this->assertSame('ERROR', $devolucion->motivo);
        $this->assertSame('Mandaron otro sabor', $devolucion->observacion);
        $this->assertFalse($devolucion->con_reposicion);
        $this->assertSame($antes - 9, (float) $producto->fresh()->stock_actual);
    }
}
