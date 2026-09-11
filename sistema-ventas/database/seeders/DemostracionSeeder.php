<?php

namespace Database\Seeders;

use App\Models\Categoria;
use App\Models\Compra;
use App\Models\CompraDetalle;
use App\Models\DevolucionCompra;
use App\Models\DevolucionCompraDetalle;
use App\Models\Lote;
use App\Models\MovimientoInventario;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Usuario;
use App\Services\Compras;
use App\Services\DevolucionesCompra;
use App\Services\Lotes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Datos de demostración para el almacén: vencimientos, compras y devoluciones.
 *
 * El catálogo que trae `docs/sql/02_datos_iniciales.sql` deja los productos con
 * stock pero sin historia: cero compras, cero devoluciones y ningún lote. Con
 * eso las tres pantallas nuevas se ven vacías, que es la peor forma de enseñar
 * un sistema — parece que no hacen nada.
 *
 * Esto no va en `DatabaseSeeder`: se corre a mano y solo sobre una base de
 * demostración.
 *
 *     php artisan db:seed --class=DemostracionSeeder
 *
 * Es reproducible (la semilla del azar es fija) y no duplica: si ya hay
 * compras, deja esa parte como está en vez de inventar una segunda historia
 * encima de la primera.
 */
class DemostracionSeeder extends Seeder
{
    /** Semilla fija: dos corridas sobre la misma base dan lo mismo. */
    private const SEMILLA = 20260911;

    /**
     * Qué categorías llevan fecha de vencimiento.
     *
     * No todas, a propósito: el detergente y el jabón no vencen, y pedirles una
     * fecha cada vez que llegan es la forma más rápida de que alguien escriba
     * cualquier cosa con tal de seguir. Dejarlas fuera también sirve para
     * enseñar que el control es producto por producto y no un interruptor
     * general.
     */
    private const CATEGORIAS_QUE_VENCEN = ['Abarrotes', 'Bebidas', 'Golosinas', 'Cigarrillos'];

    /**
     * Cómo se reparten las fechas, en días desde hoy.
     *
     * Son los tramos que mira la pantalla de vencimientos (7, 15, 30, 60, 90),
     * con peso suficiente en los primeros para que la alerta tenga qué mostrar
     * el día de la demostración. `null` es el stock sin fecha conocida.
     *
     * @var array<int, array{0: ?int, 1: ?int, 2: int}>  [desde, hasta, peso]
     */
    private const TRAMOS = [
        [-60, -2, 6],     // ya vencido: lo que hay que sacar del estante hoy
        [0, 7, 9],        // esta semana
        [8, 15, 9],
        [16, 30, 12],
        [31, 60, 14],
        [61, 90, 14],
        [91, 400, 30],    // el grueso: lo que aguanta
        [null, null, 6],  // sin fecha registrada
    ];

    private Usuario $almacenero;

    public function run(): void
    {
        mt_srand(self::SEMILLA);

        $this->almacenero = Usuario::where('usuario', 'almacen')->firstOrFail();

        // El kardex guarda QUIÉN movió cada cosa, y desde la consola no hay
        // sesión: sin esto los movimientos quedarían sin responsable.
        Auth::login($this->almacenero);

        $this->encenderVencimientos();
        $this->repartirElStockEnTandas();

        if (Compra::exists()) {
            $this->command?->warn('Ya hay compras registradas: no se agregan más.');

            return;
        }

        $compras = $this->comprasDelUltimoMes();
        $this->devolucionesAlProveedor($compras);
    }

    /** Marca qué productos llevan fecha. */
    private function encenderVencimientos(): void
    {
        $ids = Categoria::whereIn('nombre', self::CATEGORIAS_QUE_VENCEN)->pluck('id');

        // Solo los activos: un producto descatalogado no se compra ni se vende,
        // y llenarle el almacén de tandas es ruido que nadie va a mirar.
        $encendidos = Producto::activos()
            ->whereIn('categoria_id', $ids)
            ->where('controla_vencimiento', false)
            ->update(['controla_vencimiento' => true]);

        $this->command?->info("Vencimiento encendido en {$encendidos} producto(s).");
    }

    /**
     * Parte el stock que ya existe en tandas con fecha.
     *
     * Es el caso real de encender el control sobre un catálogo que lleva meses
     * funcionando: las unidades están en el estante y hay que repartirlas. Un
     * producto puede tener dos o tres tandas —la misma gaseosa comprada en
     * semanas distintas vence en días distintos—, y eso es justo lo que la
     * pantalla tiene que saber mostrar.
     */
    private function repartirElStockEnTandas(): void
    {
        $productos = Producto::activos()
            ->where('controla_vencimiento', true)
            ->whereDoesntHave('lotes')
            ->where('stock_actual', '>', 0)
            ->get();

        $tandas = 0;

        foreach ($productos as $producto) {
            $entero = ! $producto->unidadMedida?->permite_decimal;
            $stock = (float) $producto->stock_actual;

            // Entre una y tres tandas, y nunca más de las que el stock permite
            // repartir sin dejar una en cero.
            $cuantas = min(mt_rand(1, 3), $entero ? max(1, (int) floor($stock)) : 3);
            $restante = $stock;

            for ($i = 1; $i <= $cuantas; $i++) {
                // La última se lleva lo que quede: así la suma da el stock
                // exacto, que es la regla que ordena todo el módulo.
                $cantidad = $i === $cuantas
                    ? $restante
                    : $this->partir($restante, $cuantas - $i + 1, $entero);

                if ($cantidad <= 0) {
                    continue;
                }

                Lote::create([
                    'producto_id' => $producto->id,
                    'codigo' => $this->codigoDeLote(),
                    'fecha_vencimiento' => $this->fechaDelTramo(),
                    'cantidad_inicial' => $cantidad,
                    'cantidad_actual' => $cantidad,
                ]);

                $restante = round($restante - $cantidad, 3);
                $tandas++;
            }

            // Red de seguridad: si el redondeo dejó una diferencia, se abre una
            // tanda sin fecha por el resto. `stock_actual` manda siempre.
            Lotes::cuadrarConElStock($producto->fresh());
        }

        $this->command?->info("{$tandas} tanda(s) repartidas en {$productos->count()} producto(s).");
    }

    /**
     * Ocho facturas repartidas en las últimas seis semanas.
     *
     * Se registran por {@see Compras} y no a mano en la tabla: así cada línea
     * entra al stock, abre su tanda y deja su movimiento en el kardex, que es
     * lo que hace que la demostración cuadre cuando alguien se pone a revisar.
     *
     * @return array<int, Compra>
     */
    private function comprasDelUltimoMes(): array
    {
        $proveedores = Proveedor::activos()->get();
        $registradas = [];

        for ($i = 0; $i < 8; $i++) {
            $proveedor = $proveedores[$i % $proveedores->count()];
            // De la más vieja a la más reciente, una cada cuatro o cinco días.
            $fecha = now()->subDays(42 - $i * 5)->setTime(mt_rand(8, 17), mt_rand(0, 59));

            $compra = Compras::registrar(
                usuario: $this->almacenero,
                proveedor: $proveedor,
                lineas: $this->lineasDeCompra(mt_rand(3, 6)),
                documentoExterno: sprintf('F001-%05d', 1240 + $i * 7),
                observacion: $i === 3 ? 'Llegó con un día de retraso.' : null,
            );

            $this->fecharDocumento($compra, $fecha);
            $registradas[] = $compra->fresh('detalle');
        }

        $this->command?->info(count($registradas).' compras registradas.');

        return $registradas;
    }

    /**
     * Las líneas de una factura: productos al azar, sin repetir.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lineasDeCompra(int $cuantas): array
    {
        $productos = Producto::activos()
            ->with('unidadMedida')
            ->inRandomOrder()
            ->limit($cuantas)
            ->get();

        return $productos->map(function (Producto $producto) {
            // Si viene en cajas, se compran cajas enteras: es lo que hace el
            // negocio, y es lo que deja ver el desglose «3 cajas de 24».
            $cantidad = $producto->tieneEmpaque()
                ? (float) $producto->contenido_empaque * mt_rand(2, 6)
                : ($producto->unidadMedida?->permite_decimal ? mt_rand(5, 40) + 0.5 : mt_rand(12, 60));

            // La unidad manda sobre la cantidad, aquí igual que en el
            // formulario: no entran 2,5 gaseosas ni aunque la caja traiga un
            // contenido con decimales.
            if (! $producto->unidadMedida?->permite_decimal) {
                $cantidad = max(1.0, round($cantidad));
            }

            return [
                'producto_id' => $producto->id,
                'cantidad' => $cantidad,
                'costo_unitario' => (float) $producto->precio_compra,
                // La fecha que trae la caja. Para lo que no vence, `Lotes` la
                // ignora, así que se puede pasar siempre.
                //
                // Una de cada cinco viene corta o directamente pasada: el
                // proveedor que despacha lo que tenía más viejo es el caso que
                // origina media devolución por vencimiento, y si todas las
                // compras trajeran fechas lejanas la pantalla de vencimientos
                // nunca podría ofrecer el atajo de devolver contra su factura.
                'vence' => $producto->controla_vencimiento
                    ? now()->addDays(mt_rand(1, 5) === 1 ? mt_rand(-12, 25) : mt_rand(20, 300))->toDateString()
                    : null,
                'lote' => $producto->controla_vencimiento ? $this->codigoDeLote() : null,
            ];
        })->all();
    }

    /**
     * Cuatro devoluciones, una por cada motivo.
     *
     * La de vencimiento elige la tanda a mano —que es de lo que se trata: se
     * devuelve ESE lote y no el que saldría primero— y la de defecto va con
     * reposición, para que en la demostración se vea el caso en que el stock
     * termina igual pero el problema queda escrito.
     *
     * @param  array<int, Compra>  $compras
     */
    private function devolucionesAlProveedor(array $compras): void
    {
        // Un caso por motivo y por final. El número de líneas va a mano: con
        // dos o tres productos en la misma nota de crédito se ve que la
        // devolución es un documento y no una corrección suelta por producto.
        //
        // Hay dos PENDIENTE porque es el caso que la demostración tiene que
        // enseñar —el proveedor que se lleva la mercadería y debe el
        // reemplazo—, y una de ellas se repone a medias más abajo.
        $guion = [
            ['motivo' => 'DEFECTO', 'espera' => 'REPUESTO', 'compra' => 1, 'lineas' => 1,
                'observacion' => 'Tres unidades llegaron con el envase reventado.'],
            ['motivo' => 'VENCIMIENTO', 'espera' => 'PENDIENTE', 'compra' => 3, 'lineas' => 1,
                'observacion' => 'Vino con menos de un mes de vida útil. Queda en traer el cambio.'],
            ['motivo' => 'ERROR', 'espera' => 'REPUESTO', 'compra' => 5, 'lineas' => 1,
                'observacion' => 'Mandaron otro sabor del que se pidió.'],
            ['motivo' => 'OTRO', 'espera' => 'NOTA_CREDITO', 'compra' => 6, 'lineas' => 1,
                'observacion' => null],
            ['motivo' => 'DEFECTO', 'espera' => 'PENDIENTE', 'compra' => 4, 'lineas' => 3,
                'observacion' => 'Media paleta venía golpeada; el distribuidor la cambia el lunes.'],
        ];

        $hechas = 0;

        foreach ($guion as $i => $caso) {
            $compra = $compras[$caso['compra']] ?? null;

            if (! $compra) {
                continue;
            }

            $lineas = $this->lineasADevolver($compra, $caso['motivo'], $caso['lineas']);

            if ($lineas === []) {
                continue;
            }

            $devolucion = DevolucionesCompra::registrar(
                usuario: $this->almacenero,
                compra: $compra,
                lineas: $lineas,
                motivo: $caso['motivo'],
                espera: $caso['espera'],
                documentoExterno: sprintf('NC-%05d', 310 + $i * 3),
                observacion: $caso['observacion'],
            );

            // Un día o dos después de que llegó la mercadería: es cuando se
            // descubre el problema, no el mismo minuto.
            $this->fecharDocumento($devolucion, $compra->fecha->copy()->addDays(mt_rand(1, 3)));

            // A la primera pendiente el proveedor le trae parte: es el caso
            // que hay que poder enseñar —lo que llegó y lo que sigue debiendo—
            // y con una reposición completa no se vería.
            if ($caso['espera'] === 'PENDIENTE' && $i === 1) {
                $this->reposicionAMedias($devolucion);
            }

            $hechas++;
        }

        $this->command?->info("{$hechas} devolución(es) al proveedor registradas.");
    }

    /** El proveedor trae la mitad de lo que debe: el resto queda pendiente. */
    private function reposicionAMedias(DevolucionCompra $devolucion): void
    {
        $lineas = $devolucion->detalle->map(function (DevolucionCompraDetalle $linea) {
            $trae = max(1, (int) floor($linea->pendiente_reposicion / 2));

            return [
                'linea_id' => $linea->id,
                'cantidad' => min($trae, $linea->pendiente_reposicion),
                'vence' => $linea->producto?->controla_vencimiento
                    ? now()->addDays(mt_rand(150, 330))->toDateString()
                    : null,
            ];
        })->all();

        DevolucionesCompra::reponer(
            usuario: $this->almacenero,
            devolucion: $devolucion,
            lineas: $lineas,
            documentoExterno: 'G-'.sprintf('%05d', mt_rand(100, 999)),
        );
    }

    /**
     * Qué se devuelve de una compra: unas pocas líneas, parte de lo que trajeron.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lineasADevolver(Compra $compra, string $motivo, int $cuantas): array
    {
        $lineas = [];

        foreach ($compra->detalle->take($cuantas) as $linea) {
            $producto = $linea->producto;

            // Una fracción pequeña: devolver la factura entera no es el caso
            // habitual y dejaría la compra sin nada que mirar. Se redondea
            // hacia abajo, así que sale entera y sirve para cualquier unidad.
            $cantidad = min(
                max(1, (int) floor((float) $linea->cantidad * 0.15)),
                $linea->pendiente_devolucion,
            );

            if ($cantidad <= 0) {
                continue;
            }

            $lineas[] = [
                'compra_detalle_id' => $linea->id,
                'cantidad' => $cantidad,
                // Lo vencido se devuelve de su tanda: la que caduca antes.
                'lote_id' => $motivo === 'VENCIMIENTO'
                    ? Lote::where('producto_id', $producto->id)->abiertos()->enOrdenDeSalida()->value('id')
                    : null,
                'vence_repuesto' => $producto?->controla_vencimiento
                    ? now()->addDays(mt_rand(120, 330))->toDateString()
                    : null,
            ];
        }

        return $lineas;
    }

    /**
     * Lleva un documento y todo su rastro a la fecha que le toca.
     *
     * Los servicios sellan con `now()` —y así tiene que ser en producción—, así
     * que una historia repartida en el tiempo solo se puede armar corrigiendo
     * después. Se mueven también los movimientos del kardex y las tandas que
     * abrió: un ingreso fechado en agosto cuyo kardex dice hoy no es una
     * demostración, es un descuadre.
     */
    private function fecharDocumento(Compra|DevolucionCompra $documento, Carbon $fecha): void
    {
        $esCompra = $documento instanceof Compra;
        $columna = $esCompra ? 'compra_id' : 'devolucion_compra_id';

        $documento->forceFill(['fecha' => $fecha])->save();
        DB::table($documento->getTable())->where('id', $documento->id)->update(['creado_en' => $fecha]);

        MovimientoInventario::where($columna, $documento->id)->update(['fecha' => $fecha]);

        if ($esCompra) {
            $lineas = CompraDetalle::where('compra_id', $documento->id)->pluck('id');
            Lote::whereIn('compra_detalle_id', $lineas)->update(['creado_en' => $fecha]);
        }
    }

    /** Reparte una cantidad dejando algo para las tandas que faltan. */
    private function partir(float $restante, int $tandasQueFaltan, bool $entero): float
    {
        $maximo = $restante / $tandasQueFaltan * 1.5;
        $parte = $restante * mt_rand(25, 55) / 100;
        $parte = min($parte, $maximo);

        return $entero ? max(1.0, floor($parte)) : round(max(0.1, $parte), 3);
    }

    /** Una fecha del tramo que toque, según los pesos de {@see TRAMOS}. */
    private function fechaDelTramo(): ?string
    {
        $total = array_sum(array_column(self::TRAMOS, 2));
        $dado = mt_rand(1, $total);

        foreach (self::TRAMOS as [$desde, $hasta, $peso]) {
            $dado -= $peso;

            if ($dado <= 0) {
                return $desde === null ? null : now()->addDays(mt_rand($desde, $hasta))->toDateString();
            }
        }

        return null;
    }

    /** El lote impreso por el fabricante, con la pinta que suele tener. */
    private function codigoDeLote(): string
    {
        return sprintf('L%02d%03d', mt_rand(1, 12), mt_rand(1, 999));
    }
}
