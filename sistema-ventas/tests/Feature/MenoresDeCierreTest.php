<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\Cliente;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SesionCaja;
use App\Models\TomaInventarioDetalle;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\Compras;
use App\Services\Inventario;
use App\Services\ReglasEnPhp;
use App\Services\TomasInventario;
use App\Services\Ventas;
use App\Support\Config;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use RuntimeException;
use Tests\TestCase;

/**
 * Los menores de la auditoría de cierre (15/09/2026), cada uno con lo que
 * pasaba antes.
 */
class MenoresDeCierreTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    private function turno(): SesionCaja
    {
        $admin = $this->admin();

        return (Cajas::sesionDe($admin) ?? Cajas::abrir(Caja::firstOrFail(), $admin, 100))->fresh();
    }

    /** @param  array<int, array<string, mixed>>|null  $pagos */
    private function vender(?array $pagos = null, float $cantidad = 1): Venta
    {
        return Ventas::registrar(
            sesion: $this->turno(),
            usuario: $this->admin(),
            lineas: [['producto_id' => Producto::where('codigo', 'P-0004')->value('id'), 'cantidad' => $cantidad]],
            pagos: $pagos ?? [['metodo_pago_id' => $this->efectivo(), 'monto' => null]],
        );
    }

    private function efectivo(): int
    {
        return (int) MetodoPago::where('codigo', 'EFECTIVO')->value('id');
    }

    // ================================================================ conversión de precios

    /** @param  array<string, string>  $cambios */
    private function guardarConfiguracion(array $cambios)
    {
        $valor = fn (string $clave) => (string) DB::table('configuracion')->where('clave', $clave)->value('valor');
        $tasa = (float) $valor('tasa_impuesto');

        return $this->actingAs($this->admin())->put(route('configuracion.update'), array_merge([
            'negocio_nombre' => $valor('negocio_nombre'),
            'negocio_documento' => $valor('negocio_documento'),
            'negocio_direccion' => $valor('negocio_direccion'),
            'negocio_telefono' => $valor('negocio_telefono'),
            'moneda_codigo' => $valor('moneda_codigo'),
            'cobra_impuesto' => $tasa > 0 ? '1' : '0',
            'tasa_impuesto' => $tasa > 0 ? (string) ($tasa * 100) : '13',
            'precios_incluyen_impuesto' => $valor('precios_incluyen_impuesto') === '1' ? '1' : '0',
            'descuento_max_cajero' => $valor('descuento_max_cajero'),
            'egreso_max_cajero' => $valor('egreso_max_cajero'),
            'cliente_generico_nombre' => $valor('cliente_generico_nombre'),
            'dias_max_sustitucion' => $valor('dias_max_sustitucion'),
            'dias_max_devolucion' => $valor('dias_max_devolucion'),
            'exigir_referencia_pago' => $valor('exigir_referencia_pago'),
            'serie_factura' => $valor('serie_factura'),
            'serie_recibo' => $valor('serie_recibo'),
        ], $cambios));
    }

    private function conIvaEncima(): void
    {
        DB::table('configuracion')->where('clave', 'tasa_impuesto')->update(['valor' => '0.1300']);
        DB::table('configuracion')->updateOrInsert(['clave' => 'precios_incluyen_impuesto'], ['valor' => '0']);
        DB::table('productos')->where('codigo', 'P-0004')->update(['afecto_impuesto' => 1, 'precio_venta' => '4.43']);
        DB::table('productos')->where('codigo', 'P-0009')->update(['afecto_impuesto' => 1, 'precio_venta' => '7.15']);
        Config::olvidar();
    }

    /**
     * Ida y vuelta redondeando no siempre deja el precio de antes, y no había
     * forma de volver: ahora la conversión guarda los precios anteriores y se
     * deshace.
     */
    public function test_la_conversion_de_precios_se_puede_deshacer(): void
    {
        $this->conIvaEncima();
        $leche = Producto::where('codigo', 'P-0004')->firstOrFail();
        $otro = Producto::where('codigo', 'P-0009')->firstOrFail();

        $this->guardarConfiguracion(['precios_incluyen_impuesto' => '1', 'convertir_precios' => '1'])->assertSessionHasNoErrors();

        $this->assertSame('5.01', $leche->fresh()->precio_venta);
        $conversion = Auditoria::where('accion', 'PRECIOS_CONVERTIDOS')->latest('id')->firstOrFail();
        $this->assertSame(['4.43', '5.01'], $conversion->detalle['precios'][$leche->id]);

        // Alguien corrige un precio a mano después de convertir: ese se respeta.
        $otro->update(['precio_venta' => '9.99']);

        $this->actingAs($this->admin())->get(route('configuracion.edit'))->assertOk()
            ->assertSee('Deshacer ese ajuste');

        $this->actingAs($this->admin())->post(route('configuracion.deshacer-conversion'))
            ->assertSessionHas('exito', fn ($m) => str_contains($m, 'ya se habían editado a mano'));

        $this->assertSame('4.43', $leche->fresh()->precio_venta);
        $this->assertSame('9.99', $otro->fresh()->precio_venta);
        $this->assertFalse(Config::preciosIncluyenImpuesto());
        $this->assertSame(0.13, Config::tasaImpuesto());

        // Deshecha una vez, no hay nada más que deshacer.
        $this->actingAs($this->admin())->post(route('configuracion.deshacer-conversion'))
            ->assertSessionHas('error');
        $this->assertSame('4.43', $leche->fresh()->precio_venta);
    }

    /** Si el modo cambió otra vez después, deshacer dejaría precios y modo desparejos: no se ofrece. */
    public function test_no_se_deshace_una_conversion_si_el_modo_volvio_a_cambiar(): void
    {
        $this->conIvaEncima();

        $this->guardarConfiguracion(['precios_incluyen_impuesto' => '1', 'convertir_precios' => '1'])->assertSessionHasNoErrors();
        $this->guardarConfiguracion(['precios_incluyen_impuesto' => '0'])->assertSessionHasNoErrors();

        $this->actingAs($this->admin())->get(route('configuracion.edit'))->assertOk()
            ->assertDontSee('Deshacer ese ajuste');
        $this->actingAs($this->admin())->post(route('configuracion.deshacer-conversion'))
            ->assertSessionHas('error');
    }

    // ================================================================ mostrador

    /** El total de cada línea es lo que se cobra por ella, con su impuesto, no precio de estante × cantidad. */
    public function test_la_linea_del_carrito_muestra_lo_que_se_cobra(): void
    {
        $this->turno();

        $this->actingAs($this->admin())->get(route('pos.index'))->assertOk()
            ->assertSee('montos.totalLinea(l.precio, l.cantidad, l.afecto, tasa, incluido)', false)
            ->assertDontSee('montos.importeLinea(l.precio_estante, l.cantidad)', false);
    }

    /** Con más de 50 clientes, los del final se buscan; antes no había forma de elegirlos. */
    public function test_con_muchos_clientes_el_mostrador_ofrece_buscarlos(): void
    {
        $this->turno();

        $this->actingAs($this->admin())->get(route('pos.index'))->assertOk()
            ->assertDontSee('data-buscar-cliente', false);

        $ultimo = null;
        foreach (range(1, 55) as $i) {
            $ultimo = Cliente::create([
                'tipo_persona' => 'NATURAL', 'tipo_documento' => 'CI', 'documento' => (string) (7000000 + $i),
                'nombres' => 'Zulema', 'apellidos' => sprintf('Zapata %02d', $i), 'activo' => 1,
            ]);
        }

        $this->actingAs($this->admin())->get(route('pos.index'))->assertOk()
            ->assertSee('data-buscar-cliente', false)
            // El último por orden alfabético no entra en la lista...
            ->assertDontSee('value="'.$ultimo->id.'"', false);

        // ...pero llegando con él elegido, está.
        $this->actingAs($this->admin())->get(route('pos.index', ['cliente' => $ultimo->id]))->assertOk()
            ->assertSee('value="'.$ultimo->id.'"', false);

        // Y el buscador lo encuentra.
        $this->actingAs($this->admin())->getJson(route('clientes.buscar', ['q' => 'Zapata 55']))->assertOk()
            ->assertJsonFragment(['id' => $ultimo->id]);
    }

    /** Un vuelto del tamaño del billete más grande es un cero de más al teclear. */
    public function test_el_efectivo_recibido_no_puede_dejar_un_vuelto_absurdo(): void
    {
        $total = (float) $this->vender()->total;

        $venta = $this->vender([['metodo_pago_id' => $this->efectivo(), 'monto' => null, 'monto_recibido' => $total + 199.99]]);
        $this->assertSame('199.99', $venta->pagos->first()->vuelto);

        try {
            $this->vender([['metodo_pago_id' => $this->efectivo(), 'monto' => null, 'monto_recibido' => $total + 200]]);
            $rechazo = null;
        } catch (RuntimeException $e) {
            $rechazo = $e->getMessage();
        }

        $this->assertNotNull($rechazo, 'Se aceptó un vuelto de Bs 200');
        $this->assertStringContainsString('revisa lo que tecleaste', $rechazo);
    }

    // ================================================================ toma de inventario

    /**
     * El cierre ajusta de a un producto: si se corta a la mitad, volver a
     * cerrar sigue donde quedó sin aplicar nada dos veces, y lo aplicado ya no
     * se recuenta ni se cancela.
     */
    public function test_el_cierre_de_la_toma_continua_donde_quedo(): void
    {
        $toma = TomasInventario::abrir($this->admin());
        $leche = Producto::where('codigo', 'P-0004')->firstOrFail();
        $otro = Producto::where('codigo', 'P-0009')->firstOrFail();
        $stockLeche = (float) $leche->stock_actual;
        $stockOtro = (float) $otro->stock_actual;

        $lineaLeche = TomaInventarioDetalle::where('toma_id', $toma->id)->where('producto_id', $leche->id)->firstOrFail();
        $lineaOtro = TomaInventarioDetalle::where('toma_id', $toma->id)->where('producto_id', $otro->id)->firstOrFail();
        TomasInventario::contar($lineaLeche, $this->admin(), $stockLeche - 2);
        TomasInventario::contar($lineaOtro, $this->admin(), $stockOtro + 1);

        // Un primer cierre que alcanzó a ajustar solo la leche y se cortó.
        $this->actingAs($this->admin());
        $movimiento = Inventario::corregir($leche, -2, "Toma de inventario #{$toma->id}");
        $lineaLeche->update(['movimiento_id' => $movimiento->id]);

        $this->assertThrows(fn () => TomasInventario::contar($lineaLeche->fresh(), $this->admin(), 1), RuntimeException::class, 'ya se aplicó');
        $this->assertThrows(fn () => TomasInventario::cancelar($toma->fresh(), $this->admin()), RuntimeException::class, 'termina de cerrarla');

        $resumen = TomasInventario::cerrar($toma->fresh(), $this->admin());

        $this->assertSame(round($stockLeche - 2, 3), (float) $leche->fresh()->stock_actual);
        $this->assertSame(round($stockOtro + 1, 3), (float) $otro->fresh()->stock_actual);
        $this->assertSame(2, $resumen['ajustados']);
        $this->assertSame('CERRADA', $toma->fresh()->estado);
    }

    // ================================================================ kardex

    /** La compra anota a quien la registró aunque no haya sesión web (antes quedaba anónima). */
    public function test_el_kardex_anota_al_responsable_de_la_compra(): void
    {
        $almacen = Usuario::where('usuario', 'almacen')->firstOrFail();
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();

        $compra = Compras::registrar($almacen, Proveedor::firstOrFail(), [
            ['producto_id' => $producto->id, 'cantidad' => 3, 'costo_unitario' => 2.5],
        ]);

        $this->assertSame(
            [$almacen->id],
            DB::table('movimientos_inventario')->where('compra_id', $compra->id)->pluck('usuario_id')->unique()->values()->all(),
        );
    }

    /** Sin nadie a quien atribuirlo, el movimiento no entra: solo la carga INICIAL puede ser anónima. */
    public function test_no_hay_movimientos_de_inventario_sin_responsable(): void
    {
        $producto = Producto::where('codigo', 'P-0004')->firstOrFail();
        $antes = (float) $producto->stock_actual;

        // Lo rechaza la aplicación, antes de tocar el stock: no depende del
        // trigger, que en el modo sin triggers no existe.
        try {
            Inventario::corregir($producto, 1, 'Sin nadie detrás');
            $rechazo = null;
        } catch (RuntimeException $e) {
            $rechazo = $e;
        }
        $this->assertNotNull($rechazo, 'Entró un movimiento sin responsable');
        $this->assertNotInstanceOf(QueryException::class, $rechazo);
        $this->assertStringContainsString('responsable', $rechazo->getMessage());
        $this->assertSame($antes, (float) $producto->fresh()->stock_actual);

        if (ReglasEnPhp::activa()) {
            return;
        }

        // Y la base lo rechaza aunque se escriba directo, salvo la carga inicial.
        $fila = [
            'producto_id' => $producto->id, 'usuario_id' => null, 'tipo' => 'AJUSTE', 'origen' => 'AJUSTE',
            'cantidad' => 1, 'stock_anterior' => $antes, 'stock_resultante' => $antes + 1, 'fecha' => now(),
        ];
        $this->assertThrows(fn () => DB::table('movimientos_inventario')->insert($fila), QueryException::class, 'responsable');

        DB::table('movimientos_inventario')->insert(['tipo' => 'ENTRADA', 'origen' => 'INICIAL'] + $fila);
        $this->assertTrue(DB::table('movimientos_inventario')->where('producto_id', $producto->id)->where('origen', 'INICIAL')->whereNull('usuario_id')->exists());
    }

    // ================================================================ reglas en la base

    /** Una venta escrita por fuera de la aplicación en un turno cerrado no entra. */
    public function test_la_base_rechaza_una_venta_en_un_turno_cerrado(): void
    {
        if (ReglasEnPhp::activa()) {
            $this->markTestSkipped('Sin triggers la regla vive en Ventas::registrar, que ya la prueba CajaTest.');
        }

        $cerrado = SesionCaja::where('estado', 'CERRADA')->first()
            ?? tap($this->turno(), fn ($s) => DB::table('sesiones_caja')->where('id', $s->id)->update(['estado' => 'CERRADA', 'fecha_cierre' => now()]));

        $this->assertThrows(fn () => DB::table('ventas')->insert([
            'usuario_id' => $this->admin()->id, 'sesion_caja_id' => $cerrado->id, 'fecha' => now(),
        ]), QueryException::class, 'turno de caja abierto');
    }

    /** Lo pagado tiene que sumar el total, en los dos modos: si no, no hay comprobante. */
    public function test_sin_pagos_que_sumen_el_total_no_se_emite_comprobante(): void
    {
        $venta = $this->vender();
        DB::table('venta_pagos')->where('venta_id', $venta->id)->update(['monto' => DB::raw('monto - 1')]);
        DB::table('comprobantes')->where('venta_id', $venta->id)->update(['estado' => 'ANULADO']);

        $serie = Ventas::seriePara(null);

        $emitir = ReglasEnPhp::activa()
            ? fn () => ReglasEnPhp::emitirComprobante($venta->id, $serie->id)
            : fn () => DB::statement('CALL sp_emitir_comprobante(?, ?, @c, @n)', [$venta->id, $serie->id]);

        $this->assertThrows($emitir, ReglasEnPhp::activa() ? RuntimeException::class : QueryException::class, 'Lo pagado no coincide');
    }

    /** La bitácora se ordena por fecha y se filtra por acción con índice. */
    public function test_la_bitacora_tiene_indices_por_fecha_y_por_accion(): void
    {
        $indices = collect(DB::select('SHOW INDEX FROM auditoria'))->pluck('Key_name')->unique();

        $this->assertTrue($indices->contains('ix_auditoria_fecha'));
        $this->assertTrue($indices->contains('ix_auditoria_accion'));
    }

    // ================================================================ parches

    /** Una base nueva trae registrados los parches que su esquema ya incorpora; los de catálogo quedan pendientes. */
    public function test_el_esquema_registra_los_parches_que_ya_incorpora(): void
    {
        $catalogo = [
            '2026_08_23_abarrotes_catalogo_real.sql', '2026_08_23_bebidas_catalogo_real.sql',
            '2026_08_23_categoria_cigarrillos.sql', '2026_08_23_sin_impuesto.sql',
        ];
        $esquema = (string) file_get_contents(base_path('../docs/sql/01_schema_mysql.sql'));

        foreach (glob(base_path('../docs/sql/parches/*.sql')) as $ruta) {
            $archivo = basename($ruta);
            $registrado = str_contains($esquema, "('{$archivo}')");

            in_array($archivo, $catalogo, true)
                ? $this->assertFalse($registrado, "{$archivo} es de catálogo y no debe nacer registrado")
                : $this->assertTrue($registrado, "{$archivo} falta en el registro de parches de 01_schema_mysql.sql");
        }

        $this->assertSame(
            count(glob(base_path('../docs/sql/parches/*.sql'))) - count($catalogo),
            DB::table('parches_aplicados')->count(),
        );
    }

    // ================================================================ Excel

    private function libroDe(string $ruta): Spreadsheet
    {
        $respuesta = $this->actingAs($this->admin())->get($ruta)->assertOk();
        $fichero = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($fichero, $respuesta->streamedContent());

        try {
            return IOFactory::load($fichero);
        } finally {
            @unlink($fichero);
        }
    }

    /** El nombre del negocio va en la cabecera de cada hoja: tampoco puede entrar como fórmula. */
    public function test_la_cabecera_del_excel_no_admite_formulas(): void
    {
        DB::table('configuracion')->where('clave', 'negocio_nombre')->update(['valor' => '=HYPERLINK("http://x","Clic")']);
        Config::olvidar();

        $libro = $this->libroDe(route('reportes.productos.excel'));

        foreach ($libro->getAllSheets() as $hoja) {
            $this->assertSame(DataType::TYPE_STRING, $hoja->getCell('A1')->getDataType(), "fórmula en la hoja {$hoja->getTitle()}");
        }
    }

    /** Pasado el tope, el Excel no se arma en memoria hasta caerse: se pide un período más corto. */
    public function test_el_excel_tiene_tope_de_filas(): void
    {
        Ventas::registrar(
            sesion: $this->turno(),
            usuario: $this->admin(),
            lineas: [
                ['producto_id' => Producto::where('codigo', 'P-0004')->value('id'), 'cantidad' => 1],
                ['producto_id' => Producto::where('codigo', 'P-0009')->value('id'), 'cantidad' => 1],
            ],
            pagos: [['metodo_pago_id' => $this->efectivo(), 'monto' => null]],
        );
        config(['ventas.excel_max_filas' => 1]);

        $this->actingAs($this->admin())->from(route('reportes.productos'))->get(route('reportes.productos.excel'))
            ->assertRedirect(route('reportes.productos'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'el Excel admite hasta 1'));
    }

    /** La nota del ranking decía que el margen usa el costo de hoy; usa el del día de la venta. */
    public function test_la_nota_del_ranking_dice_con_que_costo_se_calcula(): void
    {
        $this->actingAs($this->admin())->get(route('reportes.productos'))->assertOk()
            ->assertSee('costo que tenía el producto el día de cada venta', false)
            ->assertDontSee('precio de compra <b>actual</b>', false);
    }
}
