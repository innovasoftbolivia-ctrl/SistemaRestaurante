<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Producto;
use App\Services\Costos;
use App\Support\Config;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Traduce «3 cajas y 5 sueltas» a las 77 unidades que entran al stock.
 *
 * Lo usan las dos pantallas desde las que llega mercadería —la ficha del
 * producto y el almacén— y el alta de producto para el stock inicial. Vive
 * aquí, y no repetido en cada controlador, porque son tres sitios haciendo la
 * misma cuenta sobre el mismo dato: si se separaran, un día el almacén
 * cargaría distinto que la ficha y nadie sabría cuál de los dos tiene razón.
 *
 * El contrato con las pantallas:
 *
 *   - `cantidad`  -> unidades de venta, directo. Es lo que se envía cuando el
 *                    producto no viene en empaque, y lo que sigue funcionando
 *                    para cualquier cliente viejo de estas rutas.
 *   - `empaques` + `sueltas` -> solo si el producto tiene empaque. Manda esto:
 *                    si viene, `cantidad` se ignora y se recalcula, para que
 *                    el total no dependa de que el navegador haya hecho bien
 *                    la multiplicación.
 */
trait IngresaPorEmpaque
{
    /**
     * Reglas de las tres casillas de cantidad. Ninguna es obligatoria por su
     * cuenta: la que manda es que entre las tres salga un total mayor que
     * cero, y eso lo decide {@see self::unidadesQueIngresan()}.
     *
     * @return array<string, array<int, string>>
     */
    protected function reglasDeCantidad(): array
    {
        return [
            'cantidad' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'empaques' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'sueltas' => ['nullable', 'numeric', 'min:0', 'max:999999'],
        ];
    }

    /**
     * Cuántas unidades entran y cómo llegaron escritas.
     *
     * Devuelve el total en unidades de venta y, si se ingresó por empaques, la
     * frase que lo describe. Esa frase va al kardex: si solo se guardara «77»
     * se perdería para siempre el dato de que llegaron 3 cajas y 5 sueltas, y
     * el mes que viene nadie podría contrastar la entrada con la factura del
     * proveedor, que está expresada en cajas.
     *
     * @return array{cantidad: float, detalle: ?string}
     */
    protected function unidadesQueIngresan(
        Request $request,
        Producto $producto,
        string $campo = 'cantidad',
        bool $obligatoria = true,
    ): array {
        return $this->unidadesDeclaradas(
            producto: $producto,
            cantidad: $request->input($campo),
            empaques: $request->filled('empaques') ? (int) $request->input('empaques') : null,
            sueltas: $request->filled('sueltas') ? (float) $request->input('sueltas') : null,
            campo: $campo,
            obligatoria: $obligatoria,
        );
    }

    /**
     * La misma cuenta, sobre valores sueltos en vez de sobre la petición.
     *
     * Existe porque la compra trae muchas líneas y cada una tiene sus propios
     * `empaques` y `sueltas` dentro de un arreglo: leerlos con nombres fijos de
     * la petición no serviría. El cálculo, las reglas y el texto del kardex son
     * exactamente los mismos — que es todo el sentido de que esto esté aquí y
     * no copiado en cada pantalla.
     *
     * @return array{cantidad: float, detalle: ?string}
     */
    protected function unidadesDeclaradas(
        Producto $producto,
        mixed $cantidad = null,
        ?int $empaques = null,
        ?float $sueltas = null,
        string $campo = 'cantidad',
        bool $obligatoria = true,
    ): array {
        $producto->loadMissing('unidadMedida');

        $porEmpaques = $producto->tieneEmpaque() && ($empaques !== null || $sueltas !== null);

        if (! $porEmpaques) {
            $total = (float) ($cantidad ?? 0);

            $this->exigirCantidadEntera($producto, $total, $campo);

            if ($obligatoria) {
                $this->exigirPositivo($total, $campo);
            }

            return ['cantidad' => max($total, 0), 'detalle' => null];
        }

        $empaques ??= 0;
        $sueltas ??= 0;

        // Las sueltas se cuentan en unidades de venta, así que les toca la
        // misma regla de decimales que a una cantidad escrita a mano.
        $this->exigirCantidadEntera($producto, $sueltas, 'sueltas');

        $total = $producto->unidadesDe($empaques, $sueltas);

        if ($obligatoria) {
            $this->exigirPositivo($total, 'empaques');
        }

        return [
            'cantidad' => $total,
            'detalle' => $this->describirEntrada($producto, $empaques, $sueltas),
        ];
    }

    /**
     * «3 cajas de 24 + 5 sueltas», tal como se guardará en el kardex.
     *
     * No se usa `Producto::desglosar()` a propósito: aquel reparte un total
     * en empaques como quien mira el estante, y este cuenta lo que la persona
     * declaró haber recibido. Casi siempre coinciden, pero no tienen por qué:
     * si alguien carga «0 cajas y 30 sueltas» de un producto que viene en
     * cajas de 24, el kardex tiene que decir eso y no «1 caja y 6 sueltas».
     */
    protected function describirEntrada(Producto $producto, int $empaques, float $sueltas): ?string
    {
        $partes = [];

        if ($empaques > 0) {
            $nombre = $empaques === 1
                ? mb_strtolower($producto->nombre_empaque)
                : $producto->empaque_plural;

            $partes[] = "{$empaques} {$nombre} de ".Config::cantidad($producto->contenido_empaque);
        }

        if ($sueltas > 0) {
            $partes[] = Config::cantidad($sueltas).' '.($sueltas === 1.0 ? 'suelta' : 'sueltas');
        }

        return $partes ? implode(' + ', $partes) : null;
    }

    /**
     * El costo que se escribió, llevado a costo por unidad de venta.
     *
     * Quien recibe la mercadería tiene delante la factura del proveedor, y ahí
     * el precio está por caja, no por unidad. Se acepta tal cual y la división
     * la hace el sistema; lo que se guarda en el kardex y en el producto sigue
     * siendo siempre el costo unitario, que es la única forma en que el resto
     * del sistema sabe leerlo.
     */
    protected function costoPorUnidad(Request $request, Producto $producto): ?float
    {
        if (! $request->filled('costo_unitario')) {
            return null;
        }

        $costo = (float) $request->input('costo_unitario');

        if ($request->input('costo_por') === 'EMPAQUE' && $producto->tieneEmpaque()) {
            return round($costo / $producto->contenido_empaque, 2);
        }

        return $costo;
    }

    /**
     * Deja el costo del producto igual al de esta compra, si se pidió.
     *
     * Hasta que existió esto, `costo_unitario` se guardaba solo en el
     * movimiento y el `precio_compra` del producto se quedaba con lo que se
     * escribió el día del alta. El resultado era silencioso y feo: el proveedor
     * sube la caja de 96 a 108, el almacenero lo carga bien, y el sistema sigue
     * diciendo que se gana Bs 2.00 por unidad cuando se ganan 1.50.
     *
     * Lo decide una casilla y no el sistema, porque una compra puntual más cara
     * —una urgencia, un flete— no siempre debe volverse el costo de referencia.
     *
     * @return array{anterior: float, nuevo: float}|null null si no cambió nada
     */
    protected function actualizarCosto(Request $request, Producto $producto, ?float $costo): ?array
    {
        if (! $request->boolean('actualizar_costo')) {
            return null;
        }

        return Costos::aplicar($producto, $costo, 'ingreso de mercadería');
    }

    /**
     * Lo que queda escrito en el kardex.
     *
     * El desglose va delante y la observación de quien recibió, detrás. Si se
     * ingresó por unidades sueltas no hay desglose que contar y el motivo pasa
     * tal cual, como siempre.
     */
    protected function motivoDelIngreso(?string $detalle, ?string $motivo): ?string
    {
        $motivo = trim((string) $motivo);

        if ($detalle === null) {
            return $motivo !== '' ? $motivo : null;
        }

        return mb_substr($motivo !== '' ? "{$detalle} · {$motivo}" : $detalle, 0, 255);
    }

    /**
     * El mensaje de vuelta, contado como lo diría quien recibió la mercadería.
     *
     * Si de paso cambió el costo del producto se dice aquí y no en un aviso
     * aparte: es una consecuencia de lo que se acaba de hacer, y enterarse
     * después —al ver el margen distinto— sería peor.
     *
     * @param  array{anterior: float, nuevo: float}|null  $cambioCosto
     */
    protected function avisoDeIngreso(
        Producto $producto,
        float $cantidad,
        ?string $detalle,
        mixed $stock,
        ?array $cambioCosto = null,
    ): string {
        $unidad = $producto->unidadMedida?->codigo;
        $entraron = $detalle !== null
            ? "{$detalle} = ".Config::cantidad($cantidad)." {$unidad}"
            : Config::cantidad($cantidad)." {$unidad}";

        $aviso = "Ingresaron {$entraron} de «{$producto->nombre}». Stock: ".Config::cantidad($stock).'.';

        if ($cambioCosto) {
            $aviso .= ' El costo pasó de '.Config::importe($cambioCosto['anterior'])
                .' a '.Config::importe($cambioCosto['nuevo']).' por '.$unidad.'.';
        }

        return $aviso;
    }

    private function exigirPositivo(float $cantidad, string $campo): void
    {
        if ($cantidad <= 0) {
            throw ValidationException::withMessages([
                $campo => 'La cantidad que ingresa debe ser mayor que cero.',
            ]);
        }
    }

    /**
     * Una unidad que no admite decimales no puede tener medio artículo.
     *
     * Vale para cualquier cantidad de inventario, no solo para una entrada: el
     * ajuste por conteo la usa igual, y por eso está aquí y no repetida en
     * cada controlador.
     */
    protected function exigirCantidadEntera(Producto $producto, float $cantidad, string $campo): void
    {
        $producto->loadMissing('unidadMedida');

        if (! $producto->unidadMedida?->permite_decimal && fmod($cantidad, 1.0) !== 0.0) {
            throw ValidationException::withMessages([
                $campo => "La unidad «{$producto->unidadMedida?->nombre}» no admite cantidades con decimales.",
            ]);
        }
    }
}
