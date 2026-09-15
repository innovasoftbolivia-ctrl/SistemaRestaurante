<?php

namespace App\Http\Controllers;

use App\Models\SerieComprobante;
use App\Services\Auditor;
use App\Support\Config;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Los datos del negocio y los parámetros del sistema.
 *
 * Hasta que existió esta pantalla, los doce valores de `configuracion` solo se
 * cambiaban por SQL. Y no son detalles: el nombre, el NIT, la dirección y el
 * teléfono salen en CADA comprobante, así que no se le podía instalar el
 * sistema a otro negocio sin entrar a la base.
 *
 * Cambiar un valor afecta lo que se registra DE AHORA EN ADELANTE. Lo ya
 * emitido no se toca: cada línea de venta congela su tasa de impuesto y cada
 * comprobante su moneda, justamente para que un cambio de hoy no reescriba el
 * pasado.
 */
class ConfiguracionController extends Controller
{
    /**
     * Las monedas que el sistema sabe mostrar. El símbolo no se pide: sale del
     * código, así no pueden quedar un código y un símbolo que no se
     * corresponden.
     */
    public const MONEDAS = [
        'BOB' => 'Boliviano — Bs',
        'USD' => 'Dólar estadounidense — $',
    ];

    public function edit(): View
    {
        $actual = $this->actuales();

        // Si una instalación vieja tiene otra moneda, se ofrece igual: si no,
        // el formulario obligaría a cambiarla solo para poder guardar lo demás.
        $monedas = self::MONEDAS;
        if (! isset($monedas[$actual['moneda_codigo']])) {
            $monedas[$actual['moneda_codigo']] = $actual['moneda_codigo'].' — '.Config::simbolo($actual['moneda_codigo']);
        }

        return view('configuracion.edit', [
            'title' => 'Configuración',
            'actual' => $actual,
            'monedas' => $monedas,
            'series' => [
                'FAC' => $this->seriesDe('FAC'),
                'REC' => $this->seriesDe('REC'),
            ],
            'modificado' => DB::table('configuracion')->max('actualizado_en'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'negocio_nombre' => ['required', 'string', 'max:120'],
            'negocio_documento' => ['required', 'regex:/^\d{4,20}$/'],
            'negocio_direccion' => ['nullable', 'string', 'max:200'],
            'negocio_telefono' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'moneda_codigo' => ['required', Rule::in(array_unique([
                ...array_keys(self::MONEDAS),
                Config::get('moneda_codigo', 'BOB'),
            ]))],
            'tasa_impuesto' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'descuento_max_cajero' => ['required', 'integer', 'min:0', 'max:100'],
            'egreso_max_cajero' => ['required', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'cliente_generico_nombre' => ['required', 'string', 'max:60'],
            'dias_max_sustitucion' => ['required', 'integer', 'min:0', 'max:30'],
            'dias_max_devolucion' => ['required', 'integer', 'min:0', 'max:365'],
            'exigir_referencia_pago' => ['boolean'],
            'serie_factura' => ['required', 'integer', $this->serieDeTipo('FAC')],
            'serie_recibo' => ['required', 'integer', $this->serieDeTipo('REC')],
        ], [
            'negocio_documento.regex' => 'El NIT lleva solo dígitos, sin guiones ni espacios.',
            'negocio_telefono.regex' => 'El teléfono lleva dígitos; se admiten espacios, guiones, paréntesis y +.',
            'tasa_impuesto.decimal' => 'La tasa admite hasta dos decimales.',
        ], [
            'negocio_nombre' => 'nombre del negocio',
            'negocio_documento' => 'NIT',
            'negocio_direccion' => 'dirección',
            'negocio_telefono' => 'teléfono',
            'moneda_codigo' => 'moneda',
            'tasa_impuesto' => 'tasa de impuesto',
            'descuento_max_cajero' => 'descuento máximo del cajero',
            'egreso_max_cajero' => 'egreso máximo del cajero',
            'cliente_generico_nombre' => 'nombre del cliente sin registrar',
            'dias_max_sustitucion' => 'días para sustituir un comprobante',
            'dias_max_devolucion' => 'días para aceptar una devolución',
            'serie_factura' => 'serie de facturas',
            'serie_recibo' => 'serie de recibos',
        ]);

        // Lo que se guarda, en el formato que ya leía el resto del sistema:
        // la tasa como fracción con cuatro decimales, los enteros sin decimales.
        $nuevos = [
            'negocio_nombre' => trim($datos['negocio_nombre']),
            'negocio_documento' => $datos['negocio_documento'],
            'negocio_direccion' => trim((string) ($datos['negocio_direccion'] ?? '')),
            'negocio_telefono' => trim((string) ($datos['negocio_telefono'] ?? '')),
            'moneda_codigo' => $datos['moneda_codigo'],
            'moneda_simbolo' => Config::simbolo($datos['moneda_codigo']),
            'tasa_impuesto' => number_format((float) $datos['tasa_impuesto'] / 100, 4, '.', ''),
            'descuento_max_cajero' => (string) (int) $datos['descuento_max_cajero'],
            'egreso_max_cajero' => number_format((float) $datos['egreso_max_cajero'], 2, '.', ''),
            'cliente_generico_nombre' => trim($datos['cliente_generico_nombre']),
            'dias_max_sustitucion' => (string) (int) $datos['dias_max_sustitucion'],
            'dias_max_devolucion' => (string) (int) $datos['dias_max_devolucion'],
            'exigir_referencia_pago' => $request->boolean('exigir_referencia_pago') ? '1' : '0',
            'serie_factura' => (string) (int) $datos['serie_factura'],
            'serie_recibo' => (string) (int) $datos['serie_recibo'],
        ];

        $antes = DB::table('configuracion')->pluck('valor', 'clave')->all();
        $cambios = [];

        foreach ($nuevos as $clave => $valor) {
            if (($antes[$clave] ?? null) !== $valor) {
                $cambios[$clave] = ['antes' => $antes[$clave] ?? null, 'despues' => $valor];
            }
        }

        // Guardar sin haber tocado nada no deja rastro en la bitácora: una
        // entrada vacía cada vez que alguien aprieta Guardar por las dudas
        // tapa las que importan.
        if (! $cambios) {
            return redirect()->route('configuracion.edit')
                ->with('aviso', 'No había nada que cambiar.');
        }

        DB::transaction(function () use ($cambios) {
            foreach ($cambios as $clave => $valor) {
                DB::table('configuracion')->updateOrInsert(
                    ['clave' => $clave],
                    ['valor' => $valor['despues']],
                );
            }
        });

        Config::olvidar();

        Auditor::registrar('CONFIGURACION_ACTUALIZADA', 'configuracion', null, $cambios);

        $cuantos = count($cambios);

        return redirect()->route('configuracion.edit')
            ->with('exito', $cuantos === 1 ? 'Se guardó 1 cambio.' : "Se guardaron {$cuantos} cambios.");
    }

    /**
     * Los valores de hoy, en el formato en que se escriben en la pantalla: la
     * tasa como porcentaje («13») y no como la fracción que se guarda.
     *
     * @return array<string, string>
     */
    private function actuales(): array
    {
        $tasa = (float) Config::get('tasa_impuesto', '0') * 100;

        return [
            'negocio_nombre' => (string) Config::get('negocio_nombre', ''),
            'negocio_documento' => (string) Config::get('negocio_documento', ''),
            'negocio_direccion' => (string) Config::get('negocio_direccion', ''),
            'negocio_telefono' => (string) Config::get('negocio_telefono', ''),
            'moneda_codigo' => (string) Config::get('moneda_codigo', 'BOB'),
            'tasa_impuesto' => rtrim(rtrim(number_format($tasa, 2, '.', ''), '0'), '.'),
            'descuento_max_cajero' => (string) Config::get('descuento_max_cajero', '0'),
            'egreso_max_cajero' => (string) Config::get('egreso_max_cajero', '0'),
            'cliente_generico_nombre' => (string) Config::get('cliente_generico_nombre', 'Cliente varios'),
            'dias_max_sustitucion' => (string) Config::get('dias_max_sustitucion', '1'),
            'dias_max_devolucion' => (string) Config::get('dias_max_devolucion', '7'),
            'exigir_referencia_pago' => (string) Config::get('exigir_referencia_pago', '1'),
            'serie_factura' => (string) Config::get('serie_factura', ''),
            'serie_recibo' => (string) Config::get('serie_recibo', ''),
        ];
    }

    /** @return array<int|string, string> id => «F001 · va por el 000123» */
    private function seriesDe(string $tipo): array
    {
        return SerieComprobante::query()
            ->join('tipos_comprobante as t', 't.id', '=', 'series_comprobante.tipo_comprobante_id')
            ->where('t.codigo', $tipo)
            ->where('series_comprobante.activo', 1)
            ->orderBy('series_comprobante.serie')
            ->get(['series_comprobante.*'])
            ->mapWithKeys(fn (SerieComprobante $s) => [
                $s->id => $s->serie.' · último número '.str_pad((string) $s->correlativo_actual, $s->longitud, '0', STR_PAD_LEFT),
            ])
            ->all();
    }

    /**
     * Una serie activa del tipo que corresponde. Sin esto se podía elegir la
     * serie de recibos como serie de facturas, y las facturas saldrían
     * numeradas como recibos.
     */
    private function serieDeTipo(string $tipo): Closure
    {
        return function (string $atributo, mixed $valor, Closure $falla) use ($tipo) {
            $existe = DB::table('series_comprobante as s')
                ->join('tipos_comprobante as t', 't.id', '=', 's.tipo_comprobante_id')
                ->where('s.id', $valor)
                ->where('s.activo', 1)
                ->where('t.codigo', $tipo)
                ->exists();

            if (! $existe) {
                $falla($tipo === 'FAC'
                    ? 'Elige una serie activa de facturas.'
                    : 'Elige una serie activa de recibos.');
            }
        };
    }
}
