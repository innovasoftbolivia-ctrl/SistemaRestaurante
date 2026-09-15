<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\Respaldos;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Los respaldos de la base y de las fotos.
 *
 * Un respaldo que nunca se restauró es una esperanza, no un respaldo. La prueba
 * central de este archivo hace uno, lo restaura en una base de descarte y
 * compara tabla por tabla con la original.
 */
class RespaldosTest extends TestCase
{
    use DatabaseTransactions;

    private string $carpeta;

    /** La base de descarte donde se restaura. Termina en `_test`, como todas. */
    private const DESCARTE = 'ventas_db_restauracion_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'respaldos-prueba-'.bin2hex(random_bytes(4));
        config(['ventas.respaldos.ruta' => $this->carpeta]);
    }

    protected function tearDown(): void
    {
        foreach (is_dir($this->carpeta) ? scandir($this->carpeta) : [] as $archivo) {
            if (is_file($this->carpeta.DIRECTORY_SEPARATOR.$archivo)) {
                @unlink($this->carpeta.DIRECTORY_SEPARATOR.$archivo);
            }
        }
        @rmdir($this->carpeta);

        parent::tearDown();
    }

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

    /** Una conexión aparte, sin base elegida: la de la prueba sigue en su transacción. */
    private function servidor(): PDO
    {
        $c = config('database.connections.'.config('database.default'));

        return new PDO(
            "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4",
            $c['username'],
            $c['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    /**
     * Carga un respaldo como lo carga el cliente `mysql`: sentencia por
     * sentencia, respetando los cambios de DELIMITER.
     */
    private function restaurar(PDO $pdo, string $archivo): void
    {
        $sql = gzdecode(file_get_contents($archivo));
        $delimitador = ';';
        $sentencia = '';

        foreach (preg_split("/\r?\n/", $sql) as $linea) {
            if (preg_match('/^DELIMITER\s+(\S+)\s*$/', $linea, $m)) {
                $delimitador = $m[1];

                continue;
            }
            if ($sentencia === '' && (trim($linea) === '' || str_starts_with(ltrim($linea), '--'))) {
                continue;
            }

            $sentencia .= $linea."\n";

            if (str_ends_with(rtrim($linea), $delimitador)) {
                $pdo->exec(substr(rtrim($sentencia), 0, -strlen($delimitador)));
                $sentencia = '';
            }
        }

        $this->assertSame('', trim($sentencia), 'el respaldo terminó con una sentencia sin cerrar');
    }

    // ============================================================== el volcado

    /**
     * La prueba que importa: el respaldo, restaurado, es la base original.
     *
     * Compara el CHECKSUM de cada tabla —una firma de todas sus filas— y la
     * cantidad de procedimientos, triggers y vistas. Si el volcado perdiera
     * una fila, insertara mal una columna generada o creara los triggers antes
     * de los datos (y la carga volviera a descontar stock), no coincidiría.
     *
     * Antes de respaldar se registra una venta. Sin ella la prueba pasaba
     * igual con los triggers creados ANTES que los datos: la base de pruebas
     * no trae ventas, así que al restaurar no había ninguna fila que los
     * disparara y el error quedaba invisible. Se comprobó cambiando el orden
     * a propósito.
     */
    public function test_el_respaldo_restaurado_es_identico_a_la_base(): void
    {
        $origen = DB::connection()->getDatabaseName();

        $cajero = $this->cajero();
        $sesion = Cajas::sesionDe($cajero) ?? Cajas::abrir(Caja::firstOrFail(), $cajero, 100);
        Ventas::registrar(
            sesion: $sesion->fresh(),
            usuario: $cajero,
            lineas: [
                ['producto_id' => Producto::where('codigo', 'P-0001')->value('id'), 'cantidad' => 3],
                ['producto_id' => Producto::where('codigo', 'P-0005')->value('id'), 'cantidad' => 2],
            ],
            pagos: [['metodo_pago_id' => MetodoPago::where('codigo', 'EFECTIVO')->value('id'), 'monto' => null]],
        );
        $this->assertGreaterThan(0, (int) DB::table('venta_detalle')->count(), 'sin ventas la prueba no mide el orden de los triggers');

        $respaldo = Respaldos::crear();

        $this->assertFileExists($respaldo['base']);

        $servidor = $this->servidor();
        $colacion = $servidor->query(
            "SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$origen}'"
        )->fetchColumn();

        try {
            $servidor->exec('DROP DATABASE IF EXISTS `'.self::DESCARTE.'`');
            // Con otra colación a propósito: el respaldo tiene que igualarla solo
            // antes de crear las rutinas, o cobrar daría el error 1267.
            $otra = str_contains($colacion, '0900') ? 'utf8mb4_unicode_ci' : 'utf8mb4_general_ci';
            $servidor->exec('CREATE DATABASE `'.self::DESCARTE."` CHARACTER SET utf8mb4 COLLATE {$otra}");
            $servidor->exec('USE `'.self::DESCARTE.'`');

            $this->restaurar($servidor, $respaldo['base']);

            $tablas = collect(DB::select(
                "SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'",
                [$origen]
            ))->pluck('t');

            $this->assertGreaterThan(20, $tablas->count(), 'la base de pruebas tiene muy pocas tablas para medir algo');

            foreach ($tablas as $tabla) {
                $antes = DB::selectOne("CHECKSUM TABLE `{$origen}`.`{$tabla}`")->Checksum;
                $despues = $servidor->query('CHECKSUM TABLE `'.self::DESCARTE."`.`{$tabla}`")->fetch(PDO::FETCH_ASSOC)['Checksum'];

                $this->assertSame((string) $antes, (string) $despues, "la tabla «{$tabla}» no quedó igual al restaurarla");
            }

            foreach ([
                'procedimientos' => 'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?',
                'triggers' => 'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?',
                'vistas' => 'SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?',
            ] as $que => $sql) {
                $consulta = $servidor->prepare($sql);
                $consulta->execute([self::DESCARTE]);
                $this->assertSame(
                    (int) DB::selectOne(str_replace('COUNT(*)', 'COUNT(*) AS n', $sql), [$origen])->n,
                    (int) $consulta->fetchColumn(),
                    "no se restauraron todos los {$que}"
                );
            }

            // Las rutinas nacieron con la colación de las tablas, no con la de la
            // base de descarte.
            $mezcladas = $servidor->query(
                "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '".self::DESCARTE."' AND DATABASE_COLLATION <> '{$colacion}'"
            )->fetchColumn();
            $this->assertSame(0, (int) $mezcladas, 'hay rutinas restauradas con otra colación: cobrar daría el error 1267');
        } finally {
            $servidor->exec('DROP DATABASE IF EXISTS `'.self::DESCARTE.'`');
        }
    }

    public function test_el_archivo_no_elige_base_ni_la_crea(): void
    {
        $sql = gzdecode(file_get_contents(Respaldos::crear()['base']));

        $this->assertDoesNotMatchRegularExpression('/^\s*USE\s/mi', $sql);
        $this->assertDoesNotMatchRegularExpression('/^\s*(CREATE|DROP)\s+DATABASE/mi', $sql);
        $this->assertStringNotContainsString('DEFINER=', $sql);
    }

    /** Los triggers van después de los datos, o la carga repetiría su efecto. */
    public function test_los_triggers_van_despues_de_los_datos(): void
    {
        $sql = gzdecode(file_get_contents(Respaldos::crear()['base']));

        // Los INSERT de DATOS empiezan en el borde de la línea; los que viven
        // dentro del cuerpo de un procedimiento o de un trigger van sangrados.
        preg_match_all('/^INSERT INTO `/m', $sql, $inserts, PREG_OFFSET_CAPTURE);
        $ultimoInsert = $inserts[0] ? end($inserts[0])[1] : false;
        $primerTrigger = preg_match('/^CREATE TRIGGER/m', $sql, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;

        $this->assertNotFalse($ultimoInsert);
        if ($primerTrigger !== false) {
            $this->assertGreaterThan($ultimoInsert, $primerTrigger);
        }
    }

    public function test_un_respaldo_a_medio_escribir_no_aparece_en_la_lista(): void
    {
        $bueno = Respaldos::crear()['base'];
        file_put_contents(Respaldos::carpeta().DIRECTORY_SEPARATOR.'ventas_db_2026-01-01_000000_zzzz.sql.gz.parcial', 'x');

        $this->assertSame([basename($bueno)], Respaldos::listar()->where('tipo', 'base')->pluck('nombre')->all());
    }

    /** La carpeta se protege sola aunque quede dentro de la raíz publicada. */
    public function test_la_carpeta_tiene_su_propio_candado(): void
    {
        $this->assertStringContainsString('Require all denied',
            (string) file_get_contents(Respaldos::carpeta().DIRECTORY_SEPARATOR.'.htaccess'));
    }

    /**
     * Las fotos van en un .tar.gz con `public/` adentro, que es lo que
     * `scripts/restore-db.sh` desempaca en storage/app, y con el mismo sello
     * que la base para que el script las encuentre solo.
     */
    public function test_las_fotos_se_guardan_junto_a_la_base(): void
    {
        $carpetaFotos = storage_path('app/public/productos');
        $foto = $carpetaFotos.DIRECTORY_SEPARATOR.'prueba-respaldo-'.bin2hex(random_bytes(4)).'.jpg';
        @mkdir($carpetaFotos, 0775, true);
        file_put_contents($foto, 'no es una foto de verdad, pero ocupa su lugar');

        try {
            $hecho = Respaldos::crear();

            $this->assertNotNull($hecho['fotos'], 'había una foto y no se guardó el archivo de fotos');
            $this->assertSame(
                str_replace('ventas_db_', 'ventas_fotos_', preg_replace('/\.sql\.gz$/', '.tar.gz', basename($hecho['base']))),
                basename($hecho['fotos']),
                'el archivo de fotos no lleva el mismo sello que el de la base'
            );

            $tar = new \PharData($hecho['fotos']);
            $this->assertTrue(isset($tar['public/productos/'.basename($foto)]), 'la foto no está dentro de public/productos');
        } finally {
            @unlink($foto);
        }
    }

    // =============================================================== limpieza

    public function test_se_borran_los_viejos_pero_nunca_el_mas_nuevo(): void
    {
        $carpeta = Respaldos::carpeta();
        $viejo = $carpeta.DIRECTORY_SEPARATOR.'ventas_db_2026-01-01_010000_aaaa.sql.gz';
        $masViejo = $carpeta.DIRECTORY_SEPARATOR.'ventas_db_2025-12-01_010000_bbbb.sql.gz';
        file_put_contents($viejo, 'x');
        file_put_contents($masViejo, 'x');
        touch($viejo, now()->subDays(Respaldos::DIAS + 5)->getTimestamp());
        touch($masViejo, now()->subDays(Respaldos::DIAS + 40)->getTimestamp());

        // Solo hay viejos: el más nuevo de ellos se conserva, aunque tenga semanas.
        Respaldos::limpiar();
        $this->assertFileExists($viejo);
        $this->assertFileDoesNotExist($masViejo);

        // Con uno reciente, el viejo ya puede irse.
        Respaldos::crear();
        $this->assertFileDoesNotExist($viejo);
    }

    // ================================================================ pantalla

    public function test_solo_quien_gestiona_respaldos_entra(): void
    {
        $this->assertTrue($this->admin()->tienePermiso('respaldos.gestionar'));
        $this->actingAs($this->admin())->get(route('respaldos.index'))->assertOk();

        $nombre = basename(Respaldos::crear()['base']);

        foreach ([$this->cajero(), $this->almacenero()] as $usuario) {
            $this->actingAs($usuario)->get(route('respaldos.index'))->assertForbidden();
            $this->actingAs($usuario)->post(route('respaldos.store'))->assertForbidden();
            $this->actingAs($usuario)->get(route('respaldos.descargar', $nombre))->assertForbidden();
        }
    }

    public function test_se_crea_un_respaldo_desde_la_pantalla_y_queda_en_la_bitacora(): void
    {
        $this->actingAs($this->admin())
            ->post(route('respaldos.store'))
            ->assertRedirect(route('respaldos.index'))
            ->assertSessionHas('exito');

        $this->assertCount(1, Respaldos::listar()->where('tipo', 'base'));
        $this->assertDatabaseHas('auditoria', ['accion' => 'RESPALDO_CREADO', 'usuario_id' => $this->admin()->id]);

        $this->actingAs($this->admin())
            ->get(route('respaldos.index'))
            ->assertSee(Respaldos::ultimo()['nombre']);
    }

    /** Descargar la base entera es sensible: queda registrado quién lo hizo. */
    public function test_se_descarga_y_queda_registrado(): void
    {
        $nombre = basename(Respaldos::crear()['base']);

        $this->actingAs($this->admin())
            ->get(route('respaldos.descargar', $nombre))
            ->assertOk()
            ->assertDownload($nombre);

        $this->assertDatabaseHas('auditoria', ['accion' => 'RESPALDO_DESCARGADO', 'usuario_id' => $this->admin()->id]);
    }

    /** Con el nombre no se puede pedir ningún otro archivo del servidor. */
    public function test_no_se_descarga_nada_fuera_de_los_respaldos(): void
    {
        Respaldos::crear();

        foreach (['..%2F..%2F.env', '.htaccess', 'ventas_db_nada.sql.gz', 'otro.sql.gz'] as $nombre) {
            $this->actingAs($this->admin())->get('/respaldos/'.$nombre.'/descargar')->assertNotFound();
        }
    }

    public function test_avisa_si_hace_mucho_que_no_hay_respaldo(): void
    {
        $this->actingAs($this->admin())
            ->get(route('respaldos.index'))
            ->assertSee('Todavía no hay ningún respaldo');

        $viejo = Respaldos::carpeta().DIRECTORY_SEPARATOR.'ventas_db_2026-01-01_010000_cccc.sql.gz';
        file_put_contents($viejo, 'x');
        touch($viejo, now()->subDays(10)->getTimestamp());

        $this->actingAs($this->admin())
            ->get(route('respaldos.index'))
            ->assertSee('El último respaldo tiene más de una semana');
    }

    /** Un respaldo en el mismo disco que la base se pierde con él: se copia afuera. */
    public function test_cada_respaldo_se_copia_a_la_carpeta_de_afuera(): void
    {
        $afuera = sys_get_temp_dir().DIRECTORY_SEPARATOR.'respaldos-afuera-'.bin2hex(random_bytes(4));
        config(['ventas.respaldos.copia' => $afuera]);

        try {
            $hecho = Respaldos::crear();

            $this->assertSame($afuera, $hecho['copia']);
            $this->assertNull($hecho['error_copia']);
            $this->assertFileEquals($hecho['base'], $afuera.DIRECTORY_SEPARATOR.basename($hecho['base']));

            $this->actingAs($this->admin())->get(route('respaldos.index'))
                ->assertOk()->assertSee('data-copia-afuera', false);
        } finally {
            foreach (glob($afuera.DIRECTORY_SEPARATOR.'*') ?: [] as $archivo) {
                @unlink($archivo);
            }
            @rmdir($afuera);
        }
    }

    public function test_sin_carpeta_de_afuera_la_pantalla_lo_advierte(): void
    {
        config(['ventas.respaldos.copia' => null]);

        $this->actingAs($this->admin())->get(route('respaldos.index'))
            ->assertOk()->assertSee('data-sin-copia-afuera', false);
    }
}
