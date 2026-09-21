<?php

namespace App\Http\Controllers;

use App\Models\Pedido;
use App\Models\SerieComprobante;
use App\Models\TipoComprobante;
use App\Services\Auditor;
use App\Services\Precios;
use App\Support\Config;
use App\Support\Mensaje;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

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

    /**
     * Los campos de la pantalla que eligen la serie de cada tipo. No viven en
     * `configuracion`: son `tipos_comprobante.serie_por_omision_id`.
     */
    private const SERIES = [
        'serie_factura' => 'FAC',
        'serie_recibo' => 'REC',
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
            'conversion' => Precios::ultimaConversion(),
        ]);
    }

    /** Deshace la última conversión de precios: el menú y el modo vuelven a como estaban. */
    public function deshacerConversion(Request $request): RedirectResponse
    {
        try {
            $resultado = Precios::deshacerUltimaConversion($request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('configuracion.edit')->with('error', Mensaje::de($e));
        }

        $mensaje = "Se restauró el precio de {$resultado['restaurados']} ítem(s) del menú y el modo de precios anterior.";

        if ($resultado['omitidos'] > 0) {
            $mensaje .= " {$resultado['omitidos']} ya se habían editado a mano y se dejaron como están.";
        }

        return redirect()->route('configuracion.edit')->with('exito', $mensaje);
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
            'cobra_impuesto' => ['boolean'],
            // Sin impuesto, la tasa escrita no se usa: no se valida.
            'tasa_impuesto' => ['exclude_unless:cobra_impuesto,1', 'required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'precios_incluyen_impuesto' => ['required', Rule::in(['0', '1'])],
            'convertir_precios' => ['boolean'],
            'descuento_max_cajero' => ['required', 'integer', 'min:0', 'max:100'],
            'egreso_max_cajero' => ['required', 'numeric', 'min:0', 'max:99999999', 'decimal:0,2'],
            'cliente_generico_nombre' => ['required', 'string', 'max:60'],
            'dias_max_sustitucion' => ['required', 'integer', 'min:0', 'max:30'],
            'exigir_referencia_pago' => ['boolean'],
            'cajero_cierra_su_caja' => ['boolean'],
            // Opcional para quien guarda otra parte de la pantalla: sin él, la
            // hora de corte se queda como está.
            'hora_corte_jornada' => ['sometimes', 'required', 'integer', 'min:0', 'max:'.Config::HORA_CORTE_MAXIMA],
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
            'precios_incluyen_impuesto' => 'cómo van los precios',
            'descuento_max_cajero' => 'descuento máximo del cajero',
            'egreso_max_cajero' => 'egreso máximo del cajero',
            'cliente_generico_nombre' => 'nombre del cliente sin registrar',
            'dias_max_sustitucion' => 'días para sustituir un comprobante',
            'hora_corte_jornada' => 'hora en que empieza la jornada',
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
            'tasa_impuesto' => number_format($request->boolean('cobra_impuesto') ? (float) $datos['tasa_impuesto'] / 100 : 0, 4, '.', ''),
            'precios_incluyen_impuesto' => $datos['precios_incluyen_impuesto'],
            'descuento_max_cajero' => (string) (int) $datos['descuento_max_cajero'],
            'egreso_max_cajero' => number_format((float) $datos['egreso_max_cajero'], 2, '.', ''),
            'cliente_generico_nombre' => trim($datos['cliente_generico_nombre']),
            'dias_max_sustitucion' => (string) (int) $datos['dias_max_sustitucion'],
            'exigir_referencia_pago' => $request->boolean('exigir_referencia_pago') ? '1' : '0',
            'cajero_cierra_su_caja' => $request->boolean('cajero_cierra_su_caja') ? '1' : '0',
            'serie_factura' => (string) (int) $datos['serie_factura'],
            'serie_recibo' => (string) (int) $datos['serie_recibo'],
        ];

        // Rige para los pedidos que se abran de ahora en adelante: los ya
        // abiertos conservan su jornada y su número.
        if (array_key_exists('hora_corte_jornada', $datos)) {
            $nuevos['hora_corte_jornada'] = (string) (int) $datos['hora_corte_jornada'];
        }

        // `cajero_cierra_su_caja` llegó después de las primeras instalaciones:
        // sin la fila, vale lo mismo que apagada, y guardar sin tocarla no es
        // un cambio.
        $antes = DB::table('configuracion')->pluck('valor', 'clave')->all() + $this->seriesActuales() + ['cajero_cierra_su_caja' => '0'];
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

        // Un pedido que quedó por volver a cobrar guarda sus precios en el modo
        // en que se pidió: cobrado en el otro modo, el cliente pagaría el
        // impuesto de menos (o de más). Primero se cobran o se cancelan.
        if (isset($cambios['precios_incluyen_impuesto']) && Pedido::abiertos()->exists()) {
            return back()->withInput()->with('error', 'Hay pedidos por volver a cobrar. Cóbralos o cancélalos antes de cambiar cómo van los precios.');
        }

        // Al cambiar de modo, el menú se ajusta para que el cliente siga
        // pagando lo mismo (ver Precios). En la misma transacción: nunca
        // quedan la configuración nueva con los precios viejos.
        $convertir = isset($cambios['precios_incluyen_impuesto']) && $request->boolean('convertir_precios');
        $tasaAnterior = (float) ($antes['tasa_impuesto'] ?? 0);
        $tasaNueva = (float) $nuevos['tasa_impuesto'];

        $convertidos = DB::transaction(function () use ($cambios, $convertir, $nuevos, $tasaAnterior, $tasaNueva) {
            foreach ($cambios as $clave => $valor) {
                if (isset(self::SERIES[$clave])) {
                    TipoComprobante::where('codigo', self::SERIES[$clave])
                        ->update(['serie_por_omision_id' => (int) $valor['despues']]);

                    continue;
                }

                DB::table('configuracion')->updateOrInsert(
                    ['clave' => $clave],
                    ['valor' => $valor['despues']],
                );
            }

            $convertidos = $convertir
                ? Precios::convertirAlModo($nuevos['precios_incluyen_impuesto'] === '1', $tasaAnterior, $tasaNueva)
                : [];

            // La bitácora dentro de la transacción: el registro de la
            // conversión, con el precio anterior de cada producto, es lo único
            // que permite deshacerla (Precios::deshacerUltimaConversion). Si
            // no se pudiera escribir, tampoco quedan los precios cambiados.
            Auditor::registrar('CONFIGURACION_ACTUALIZADA', 'configuracion', null, $cambios);

            if ($convertidos) {
                Auditor::registrar('PRECIOS_CONVERTIDOS', 'productos', null, [
                    'productos' => count($convertidos),
                    'precios_incluyen_impuesto' => $nuevos['precios_incluyen_impuesto'],
                    'tasa_anterior' => $tasaAnterior,
                    'tasa_nueva' => $tasaNueva,
                    'precios' => $convertidos,
                ]);
            }

            return $convertidos;
        });

        Config::olvidar();

        $cuantos = count($cambios);
        $mensaje = $cuantos === 1 ? 'Se guardó 1 cambio.' : "Se guardaron {$cuantos} cambios.";

        if ($convertidos) {
            $mensaje .= ' Se ajustó el precio de '.count($convertidos).' ítem(s) del menú para que el cliente siga pagando lo mismo.';
        }

        return redirect()->route('configuracion.edit')->with('exito', $mensaje);
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
            'cobra_impuesto' => $tasa > 0 ? '1' : '0',
            // Sin impuesto se propone el IVA boliviano para cuando se active.
            'tasa_impuesto' => $tasa > 0 ? rtrim(rtrim(number_format($tasa, 2, '.', ''), '0'), '.') : '13',
            'precios_incluyen_impuesto' => Config::preciosIncluyenImpuesto() ? '1' : '0',
            'descuento_max_cajero' => (string) Config::get('descuento_max_cajero', '0'),
            'egreso_max_cajero' => (string) Config::get('egreso_max_cajero', '0'),
            'cliente_generico_nombre' => (string) Config::get('cliente_generico_nombre', 'Cliente varios'),
            'dias_max_sustitucion' => (string) Config::get('dias_max_sustitucion', '1'),
            'exigir_referencia_pago' => (string) Config::get('exigir_referencia_pago', '1'),
            'cajero_cierra_su_caja' => Config::cajeroCierraSuCaja() ? '1' : '0',
            'hora_corte_jornada' => (string) Config::horaCorteJornada(),
        ] + $this->seriesActuales();
    }

    /**
     * La serie de cada tipo, como la escribe la pantalla.
     *
     * @return array<string, string>
     */
    private function seriesActuales(): array
    {
        $porCodigo = TipoComprobante::whereIn('codigo', self::SERIES)->pluck('serie_por_omision_id', 'codigo');

        return array_map(fn (string $codigo) => (string) ($porCodigo[$codigo] ?? ''), self::SERIES);
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
