<?php

namespace Tests\Feature;

use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\MetodoPago;
use App\Models\Producto;
use App\Models\Usuario;
use App\Services\Cajas;
use App\Services\Respaldos;
use App\Services\Ventas;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
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
        // Sin nube, salvo en las pruebas que la prueban: ninguna prueba
        // llama al rclone de verdad.
        config([
            'ventas.respaldos.ruta' => $this->carpeta,
            'ventas.respaldos.nube' => null,
        ]);
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

    private function cocina(): Usuario
    {
        return Usuario::where('usuario', 'cocina1')->firstOrFail();
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
     * de los datos (y la carga volviera a dispararlos), no coincidiría.
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

    // ================================================================= la nube

    /**
     * No hay pantalla: los respaldos corren por debajo. Para nadie existe la
     * dirección ni la entrada del menú, tampoco para el administrador.
     */
    public function test_los_respaldos_no_tienen_pantalla(): void
    {
        foreach ([$this->admin(), $this->cajero(), $this->cocina()] as $usuario) {
            $this->actingAs($usuario)->get('/respaldos')->assertNotFound();
            $this->actingAs($usuario)->post('/respaldos')->assertNotFound();
            $this->actingAs($usuario)->get('/perfil')->assertDontSee(url('/respaldos'), false);
        }

        $this->assertFalse(Route::has('respaldos.index'));
        $this->assertFalse(DB::table('permisos')->where('codigo', 'like', 'respaldos%')->exists());
    }

    /**
     * Cada archivo se sube por su nombre a la carpeta de Drive, y después se
     * borran allá los de más de DIAS días, solo con nombre de respaldo.
     */
    public function test_cada_respaldo_se_sube_a_la_nube(): void
    {
        config(['ventas.respaldos.nube' => 'drive:']);
        Process::fake();

        $hecho = Respaldos::crear();

        $this->assertSame('drive:', $hecho['nube']);
        $this->assertNull($hecho['error_nube']);

        Process::assertRan(fn ($p) => $p->command === ['rclone', 'copyto', $hecho['base'], 'drive:'.basename($hecho['base'])]);
        Process::assertRan(fn ($p) => $p->command[1] === 'delete'
            && in_array('--min-age', $p->command, true)
            && in_array(Respaldos::DIAS.'d', $p->command, true)
            && in_array('ventas_db_*.sql.gz', $p->command, true));
    }

    /** Con una subcarpeta en el remoto, el archivo va adentro de ella. */
    public function test_se_puede_subir_a_una_subcarpeta(): void
    {
        config(['ventas.respaldos.nube' => 'drive:restaurante/']);
        Process::fake();

        $hecho = Respaldos::crear();

        Process::assertRan(fn ($p) => $p->command === ['rclone', 'copyto', $hecho['base'], 'drive:restaurante/'.basename($hecho['base'])]);
    }

    /**
     * Solo suben los respaldos: en la misma carpeta vive PRIMER-ACCESO.txt,
     * con la contraseña inicial del administrador.
     */
    public function test_solo_se_suben_los_respaldos_y_nada_mas_de_la_carpeta(): void
    {
        file_put_contents(Respaldos::carpeta().DIRECTORY_SEPARATOR.'PRIMER-ACCESO.txt', 'clave');
        config(['ventas.respaldos.nube' => 'drive:']);
        Process::fake();

        Respaldos::crear();

        Process::assertDidntRun(fn ($p) => str_contains(implode(' ', $p->command), 'PRIMER-ACCESO')
            || (($p->command[1] ?? null) === 'copy'));
        Process::assertRanTimes(fn ($p) => $p->command[1] === 'copyto', count(array_filter([
            Respaldos::ultimo(),
            Respaldos::listar()->firstWhere('tipo', 'fotos'),
        ])));
    }

    /**
     * Sin internet, el respaldo queda en el servidor, el comando termina con
     * error y queda en la bitácora. Y allá no se borra nada: nunca se tiran
     * los viejos sin que haya llegado el nuevo.
     */
    public function test_si_no_se_pudo_subir_avisa_y_no_borra_nada_alla(): void
    {
        config(['ventas.respaldos.nube' => 'drive:']);
        Process::fake([
            '*' => Process::result(errorOutput: "NOTICE: algo\nFailed to copyto: couldn't connect", exitCode: 1),
        ]);

        $this->artisan('respaldo:crear')->assertFailed();

        $this->assertNotNull(Respaldos::ultimo(), 'el respaldo local tiene que quedar igual');
        Process::assertDidntRun(fn ($p) => ($p->command[1] ?? null) === 'delete');

        $fallo = Auditoria::where('accion', 'RESPALDO_NUBE_FALLIDA')->latest('id')->first();
        $this->assertNotNull($fallo);
        $this->assertStringContainsString("couldn't connect", json_encode($fallo->detalle));
    }

    /** Sin RESPALDOS_NUBE no se llama a rclone: una instalación sin Drive sigue igual. */
    public function test_sin_nube_configurada_no_se_sube_nada(): void
    {
        config(['ventas.respaldos.nube' => null]);
        Process::fake();

        $hecho = Respaldos::crear();

        $this->assertNull($hecho['nube']);
        $this->assertNull($hecho['error_nube']);
        Process::assertNothingRan();
        $this->artisan('respaldo:crear')->assertSuccessful();
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
        } finally {
            foreach (glob($afuera.DIRECTORY_SEPARATOR.'*') ?: [] as $archivo) {
                @unlink($archivo);
            }
            @rmdir($afuera);
        }
    }
}
