<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Respaldos de la base y de las fotos, hechos con PHP.
 *
 * Existía `scripts/backup-db.sh`, y está bien, pero necesita Docker, un
 * `mysqldump` y un cron que nadie había agendado. Y la demo corre en un
 * hosting compartido que no tiene ninguna de las tres cosas: allí la única
 * forma de sacar un respaldo era entrar a phpMyAdmin a acordarse de hacerlo.
 * Esto funciona donde corra la aplicación.
 *
 * El formato es el de siempre, para que no haya dos maneras de restaurar:
 *
 *   ventas_db_<fecha>.sql.gz      la base, restaurable con `scripts/restore-db.sh`,
 *                                 con `mysql` o importándolo en phpMyAdmin
 *   ventas_fotos_<fecha>.tar.gz   las fotos de producto (storage/app/public)
 *
 * Cuatro detalles que, hechos mal, dan un respaldo que PARECE bueno y no se
 * puede restaurar —y eso se descubre justo el día que hace falta—:
 *
 *   1. Las columnas GENERADAS no se insertan. MySQL rechaza un valor para
 *      ellas, y el esquema tiene varias (importes, subtotales, vueltos).
 *   2. Los triggers se crean DESPUÉS de cargar los datos. Si se crearan antes,
 *      insertar las ventas del respaldo volvería a descontar el stock y a
 *      escribir el kardex: el respaldo restaurado no sería el original.
 *   3. Las fechas TIMESTAMP se vuelcan en UTC y el archivo lo declara. Si no,
 *      restaurar desde una sesión con otra zona horaria las corre de hora.
 *   4. Se quita el DEFINER de procedimientos, vistas y triggers: apunta a un
 *      usuario de ESTE servidor que en el de destino puede no existir.
 *
 * El archivo no trae `USE` ni `CREATE DATABASE`: se restaura sobre la base que
 * esté elegida. Es a propósito —ver la nota de `01_schema_mysql.sql`, que sí
 * los trae y por eso borró una vez la base de desarrollo—.
 */
class Respaldos
{
    /** Cuántos días se guardan. El más nuevo se guarda siempre, sea de cuándo sea. */
    public const DIAS = 14;

    /** Filas por INSERT: lo bastante para que cargue rápido, lo bastante poco para phpMyAdmin. */
    private const LOTE = 200;

    public static function carpeta(): string
    {
        $ruta = config('ventas.respaldos.ruta') ?: storage_path('app/respaldos');

        if (! is_dir($ruta) && ! mkdir($ruta, 0770, true) && ! is_dir($ruta)) {
            throw new RuntimeException("No se pudo crear la carpeta de respaldos: {$ruta}");
        }

        // En un hosting compartido la aplicación entera vive dentro de la raíz
        // publicada. La redirección a public/ ya impide pedir este archivo por
        // URL, pero un volcado de la base —con los hashes de las contraseñas—
        // merece una segunda cerradura que no dependa de esa regla.
        $candado = $ruta.DIRECTORY_SEPARATOR.'.htaccess';
        if (! is_file($candado)) {
            file_put_contents($candado, "Require all denied\n");
        }

        return $ruta;
    }

    /**
     * Hace un respaldo completo y borra los viejos.
     *
     * @return array{base: string, fotos: ?string}
     */
    public static function crear(): array
    {
        $carpeta = self::carpeta();

        // La parte aleatoria hace que el nombre no se pueda adivinar.
        $sello = now()->format('Y-m-d_His').'_'.Str::lower(Str::random(8));
        $base = $carpeta.DIRECTORY_SEPARATOR."ventas_db_{$sello}.sql.gz";
        $parcial = $base.'.parcial';

        try {
            self::volcar($parcial);
            // Se renombra al terminar: un respaldo a medio escribir —la luz se
            // cortó, se acabó el tiempo del hosting— nunca aparece en la lista
            // como si estuviera bien.
            rename($parcial, $base);
        } catch (Throwable $e) {
            @unlink($parcial);

            throw $e;
        }

        // Las fotos fallan solas: si no se pudieron empaquetar, la base ya quedó
        // bien guardada y no tiene sentido tirar el respaldo entero por eso.
        try {
            $fotos = self::fotos($carpeta, $sello);
        } catch (Throwable $e) {
            report($e);
            $fotos = null;
        }

        self::limpiar();

        return ['base' => $base, 'fotos' => $fotos];
    }

    /**
     * Los respaldos que hay, del más nuevo al más viejo.
     *
     * @return Collection<int, array{nombre: string, ruta: string, tipo: string, bytes: int, fecha: CarbonImmutable}>
     */
    public static function listar(): Collection
    {
        $carpeta = self::carpeta();

        return collect(array_merge(
            glob($carpeta.DIRECTORY_SEPARATOR.'ventas_db_*.sql.gz') ?: [],
            glob($carpeta.DIRECTORY_SEPARATOR.'ventas_fotos_*.tar.gz') ?: [],
        ))
            ->map(fn (string $ruta) => [
                'nombre' => basename($ruta),
                'ruta' => $ruta,
                'tipo' => str_starts_with(basename($ruta), 'ventas_db_') ? 'base' : 'fotos',
                'bytes' => (int) filesize($ruta),
                'fecha' => CarbonImmutable::createFromTimestamp(filemtime($ruta))->setTimezone(config('app.timezone')),
            ])
            ->sortByDesc(fn (array $r) => $r['fecha']->getTimestamp().$r['nombre'])
            ->values();
    }

    /** El respaldo de base más reciente, si hay alguno. */
    public static function ultimo(): ?array
    {
        return self::listar()->firstWhere('tipo', 'base');
    }

    /** La ruta de un respaldo por su nombre, o null si el nombre no es de un respaldo. */
    public static function ruta(string $nombre): ?string
    {
        // Solo nombres que este servicio genera: nada de barras ni puntos de más
        // con los que pedir otro archivo del servidor.
        if (! preg_match('/^ventas_(db_[\w\-]+\.sql|fotos_[\w\-]+\.tar)\.gz$/', $nombre)) {
            return null;
        }

        $ruta = self::carpeta().DIRECTORY_SEPARATOR.$nombre;

        return is_file($ruta) ? $ruta : null;
    }

    /** Borra lo que tenga más de $dias días, pero nunca el respaldo de base más nuevo. */
    public static function limpiar(int $dias = self::DIAS): int
    {
        $limite = now()->subDays($dias)->getTimestamp();
        $conservar = self::ultimo()['nombre'] ?? null;
        $borrados = 0;

        foreach (self::listar() as $respaldo) {
            if ($respaldo['nombre'] !== $conservar && $respaldo['fecha']->getTimestamp() < $limite) {
                @unlink($respaldo['ruta']) && $borrados++;
            }
        }

        return $borrados;
    }

    // ================================================================= la base

    private static function volcar(string $destino): void
    {
        $conexion = DB::connection();
        $pdo = $conexion->getPdo();
        $base = $conexion->getDatabaseName();

        $gz = gzopen($destino, 'wb6');
        if ($gz === false) {
            throw new RuntimeException("No se pudo escribir el respaldo en {$destino}.");
        }
        $escribir = function (string $texto) use ($gz, $destino) {
            if (gzwrite($gz, $texto) === false) {
                throw new RuntimeException("Se cortó la escritura del respaldo en {$destino}.");
            }
        };

        // Las TIMESTAMP en UTC mientras se lee, y se devuelve la zona de la
        // sesión al terminar: la misma conexión la sigue usando la aplicación.
        $zona = $pdo->query('SELECT @@session.time_zone')->fetchColumn();
        $pdo->exec("SET time_zone = '+00:00'");

        try {
            $colacion = $pdo->query(
                'SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '.$pdo->quote($base)
            )->fetch(PDO::FETCH_NUM);

            $escribir(implode("\n", [
                '-- Respaldo del Sistema de Ventas',
                "-- Base de origen: {$base} ({$colacion[1]})",
                '-- Hecho: '.now()->format('d/m/Y H:i:s'),
                '--',
                '-- Se restaura sobre la base que esté elegida: no trae USE ni CREATE DATABASE.',
                '-- Los triggers van al final, después de los datos, a propósito.',
                '',
                'SET NAMES utf8mb4;',
                "SET time_zone = '+00:00';",
                'SET FOREIGN_KEY_CHECKS = 0;',
                'SET UNIQUE_CHECKS = 0;',
                "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';",
                '', '',
            ]));

            $tablas = self::nombres($pdo,
                "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", $base);

            foreach ($tablas as $tabla) {
                $crear = $pdo->query('SHOW CREATE TABLE '.self::id($tabla))->fetch(PDO::FETCH_NUM)[1];
                $escribir('DROP TABLE IF EXISTS '.self::id($tabla).";\n{$crear};\n\n");
            }

            foreach ($tablas as $tabla) {
                self::datos($pdo, $base, $tabla, $escribir);
            }

            $vistas = self::vistasEnOrden($pdo, $base);
            $procedimientos = self::nombres($pdo,
                'SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_TYPE, ROUTINE_NAME', $base);
            $triggers = self::nombres($pdo,
                'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER', $base);

            if ($procedimientos || $triggers) {
                // Los procedimientos y triggers toman la colación de la BASE en
                // la que se crean, no la de las tablas. Restaurados en una base
                // con otra colación, cobrar da «Illegal mix of collations»
                // (error 1267, visto en este proyecto). Se iguala antes de crearlos.
                $escribir("-- La colación de la base, para que las rutinas nazcan con la misma que las tablas.\n");
                $escribir("ALTER DATABASE CHARACTER SET {$colacion[0]} COLLATE {$colacion[1]};\n\n");
            }

            foreach ($vistas as $vista) {
                $crear = $pdo->query('SHOW CREATE VIEW '.self::id($vista))->fetch(PDO::FETCH_NUM)[1];
                $escribir('DROP VIEW IF EXISTS '.self::id($vista).";\n".self::sinDefiner($crear).";\n\n");
            }

            foreach ($procedimientos as $rutina) {
                $tipo = $pdo->query('SELECT ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = '
                    .$pdo->quote($base).' AND ROUTINE_NAME = '.$pdo->quote($rutina))->fetchColumn();
                $fila = $pdo->query("SHOW CREATE {$tipo} ".self::id($rutina))->fetch(PDO::FETCH_ASSOC);
                $cuerpo = $fila['Create Procedure'] ?? $fila['Create Function'] ?? null;

                if ($cuerpo === null) {
                    // Sin privilegio para leer el cuerpo: se avisa en el archivo en
                    // vez de dejar una rutina vacía que parezca completa.
                    $escribir("-- AVISO: no se pudo leer el cuerpo de {$tipo} {$rutina} (falta privilegio).\n\n");

                    continue;
                }

                $escribir("DROP {$tipo} IF EXISTS ".self::id($rutina).";\nDELIMITER ;;\n".self::sinDefiner($cuerpo).";;\nDELIMITER ;\n\n");
            }

            foreach ($triggers as $trigger) {
                $fila = $pdo->query('SHOW CREATE TRIGGER '.self::id($trigger))->fetch(PDO::FETCH_ASSOC);
                $escribir('DROP TRIGGER IF EXISTS '.self::id($trigger).";\nDELIMITER ;;\n"
                    .self::sinDefiner($fila['SQL Original Statement']).";;\nDELIMITER ;\n\n");
            }

            $escribir("SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n");
        } finally {
            $pdo->exec('SET time_zone = '.$pdo->quote((string) $zona));
            gzclose($gz);
        }
    }

    private static function datos(PDO $pdo, string $base, string $tabla, callable $escribir): void
    {
        // Las columnas generadas se calculan solas al restaurar y MySQL no admite
        // que se les pase un valor. Ojo: `DEFAULT_GENERATED` —lo que MySQL 8 pone
        // en una columna con DEFAULT CURRENT_TIMESTAMP— NO es generada y sí va.
        $columnas = [];
        $consulta = $pdo->prepare(
            'SELECT COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $consulta->execute([$base, $tabla]);
        foreach ($consulta->fetchAll(PDO::FETCH_NUM) as [$columna, $extra]) {
            if (! preg_match('/\b(VIRTUAL|STORED|PERSISTENT) GENERATED\b/i', (string) $extra)) {
                $columnas[] = self::id($columna);
            }
        }

        if (! $columnas) {
            return;
        }

        $lista = implode(', ', $columnas);
        $cabecera = 'INSERT INTO '.self::id($tabla)." ({$lista}) VALUES\n";
        $filas = [];

        $lectura = $pdo->query("SELECT {$lista} FROM ".self::id($tabla));

        while (($fila = $lectura->fetch(PDO::FETCH_NUM)) !== false) {
            // `quote` escapa también los saltos de línea: un valor nunca parte
            // una sentencia en dos renglones, y el archivo se puede leer línea
            // por línea igual que lo lee el cliente `mysql`.
            $filas[] = '('.implode(', ', array_map(
                fn ($valor) => $valor === null ? 'NULL' : $pdo->quote((string) $valor),
                $fila,
            )).')';

            if (count($filas) === self::LOTE) {
                $escribir($cabecera.implode(",\n", $filas).";\n");
                $filas = [];
            }
        }

        if ($filas) {
            $escribir($cabecera.implode(",\n", $filas).";\n");
        }

        $escribir("\n");
    }

    /**
     * Las vistas en el orden en que se pueden crear: una vista que usa otra va
     * después. Donde el motor no informa dependencias (MariaDB), por nombre.
     *
     * @return array<int, string>
     */
    private static function vistasEnOrden(PDO $pdo, string $base): array
    {
        $vistas = self::nombres($pdo,
            'SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', $base);

        if (count($vistas) < 2) {
            return $vistas;
        }

        try {
            $consulta = $pdo->prepare(
                'SELECT VIEW_NAME, TABLE_NAME FROM information_schema.VIEW_TABLE_USAGE WHERE VIEW_SCHEMA = ? AND TABLE_SCHEMA = ?'
            );
            $consulta->execute([$base, $base]);
            $usa = [];
            foreach ($consulta->fetchAll(PDO::FETCH_NUM) as [$vista, $objeto]) {
                if (in_array($objeto, $vistas, true) && $objeto !== $vista) {
                    $usa[$vista][] = $objeto;
                }
            }
        } catch (Throwable) {
            return $vistas;
        }

        $orden = [];
        $visitando = [];
        $visitar = function (string $vista) use (&$visitar, &$orden, &$visitando, $usa) {
            if (in_array($vista, $orden, true) || isset($visitando[$vista])) {
                return;
            }
            $visitando[$vista] = true;
            foreach ($usa[$vista] ?? [] as $dependencia) {
                $visitar($dependencia);
            }
            $orden[] = $vista;
        };

        foreach ($vistas as $vista) {
            $visitar($vista);
        }

        return $orden;
    }

    /** @return array<int, string> */
    private static function nombres(PDO $pdo, string $sql, string $base): array
    {
        $consulta = $pdo->prepare($sql);
        $consulta->execute([$base]);

        return $consulta->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function id(string $nombre): string
    {
        return '`'.str_replace('`', '``', $nombre).'`';
    }

    private static function sinDefiner(string $sql): string
    {
        return preg_replace('/\s+DEFINER\s*=\s*(`[^`]*`|\S+)@(`[^`]*`|\S+)/i', '', $sql, 1);
    }

    // ================================================================ las fotos

    /**
     * Las fotos de producto en un .tar.gz con la carpeta `public/` adentro,
     * que es lo que `scripts/restore-db.sh` desempaca en storage/app.
     */
    private static function fotos(string $carpeta, string $sello): ?string
    {
        if (! class_exists(PharData::class)) {
            return null;
        }

        // En Docker las fotos viven en storage/app/public; en el hosting, que no
        // admite enlaces simbólicos, son una carpeta real en public/storage.
        $origen = collect([storage_path('app/public'), public_path('storage')])
            ->map(fn (string $ruta) => realpath($ruta))
            ->filter()
            ->unique()
            ->first(fn (string $ruta) => self::tieneArchivos($ruta));

        if (! $origen) {
            return null;
        }

        $tar = $carpeta.DIRECTORY_SEPARATOR."ventas_fotos_{$sello}.tar";

        try {
            $archivo = new PharData($tar);
            $iterador = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($origen, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterador as $archivoOrigen) {
                if ($archivoOrigen->isFile() && $archivoOrigen->getFilename() !== '.gitignore') {
                    $relativo = substr($archivoOrigen->getPathname(), strlen($origen) + 1);
                    $archivo->addFile($archivoOrigen->getPathname(), 'public/'.str_replace('\\', '/', $relativo));
                }
            }
            $archivo->compress(\Phar::GZ);
            unset($archivo);

            return $tar.'.gz';
        } finally {
            @unlink($tar);
        }
    }

    private static function tieneArchivos(string $ruta): bool
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ruta, FilesystemIterator::SKIP_DOTS)) as $archivo) {
            if ($archivo->isFile() && $archivo->getFilename() !== '.gitignore') {
                return true;
            }
        }

        return false;
    }
}
