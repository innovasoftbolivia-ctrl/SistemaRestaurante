<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Que toda la base hable el mismo idioma.
 *
 * MySQL permite que cada tabla tenga su propia colación, y mezclarlas no da
 * ningún error al crearlas: la base se arma, las pruebas pasan, todo parece
 * bien. El problema aparece más tarde y en otro lado.
 *
 * Un procedimiento almacenado FIJA su colación en el momento en que se crea.
 * Si la de la base no coincide con la de las columnas que toca, cualquier
 * comparación adentro revienta con «Illegal mix of collations». Y como los
 * procedimientos son los que emiten el comprobante, el error le sale al
 * cajero al cobrar —con el cliente esperando— y no a quien montó la base.
 *
 * Pasó de verdad: un parche declaró `COLLATE=utf8mb4_unicode_ci` en una tabla
 * mientras el resto de la base usaba `utf8mb4_0900_ai_ci`. La convención del
 * esquema es declarar la colación UNA vez, al crear la base, y que todas las
 * tablas la hereden.
 */
class ColacionDeLaBaseTest extends TestCase
{
    private function base(): string
    {
        return DB::connection()->getDatabaseName();
    }

    public function test_todas_las_tablas_comparten_la_colacion_de_la_base(): void
    {
        $base = $this->base();

        $deLaBase = DB::table('information_schema.SCHEMATA')
            ->where('SCHEMA_NAME', $base)
            ->value('DEFAULT_COLLATION_NAME');

        $descolgadas = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $base)
            ->where('TABLE_TYPE', 'BASE TABLE')
            ->where('TABLE_COLLATION', '<>', $deLaBase)
            ->pluck('TABLE_COLLATION', 'TABLE_NAME')
            ->all();

        $this->assertSame([], $descolgadas,
            "La base es «{$deLaBase}» y estas tablas usan otra:\n  "
            .json_encode($descolgadas, JSON_UNESCAPED_UNICODE)
            ."\nUna tabla no debe declarar COLLATE: lo hereda de la base.\n");
    }

    public function test_ninguna_columna_se_sale_de_la_colacion_de_su_tabla(): void
    {
        $base = $this->base();

        $deLaBase = DB::table('information_schema.SCHEMATA')
            ->where('SCHEMA_NAME', $base)
            ->value('DEFAULT_COLLATION_NAME');

        $descolgadas = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $base)
            ->whereNotNull('COLLATION_NAME')
            ->where('COLLATION_NAME', '<>', $deLaBase)
            ->selectRaw("CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS donde, COLLATION_NAME AS cual")
            ->pluck('cual', 'donde')
            ->all();

        $this->assertSame([], $descolgadas,
            "Columnas fuera de «{$deLaBase}»:\n  "
            .json_encode($descolgadas, JSON_UNESCAPED_UNICODE)."\n");
    }

    /**
     * Los procedimientos tienen que haberse creado contra la colación actual.
     *
     * Si la base se recreó con otra colación DESPUÉS de crear los
     * procedimientos, estos se quedan con la vieja y fallan al comparar. No
     * hay forma de verlo salvo preguntándolo.
     */
    public function test_los_procedimientos_se_crearon_con_la_colacion_de_la_base(): void
    {
        $base = $this->base();

        $deLaBase = DB::table('information_schema.SCHEMATA')
            ->where('SCHEMA_NAME', $base)
            ->value('DEFAULT_COLLATION_NAME');

        $rutinas = DB::table('information_schema.ROUTINES')
            ->where('ROUTINE_SCHEMA', $base)
            ->get(['ROUTINE_NAME', 'DATABASE_COLLATION']);

        if ($rutinas->isEmpty()) {
            $this->markTestSkipped('La base no tiene procedimientos: es la vía sin programabilidad.');
        }

        $descolgados = $rutinas
            ->filter(fn ($r) => $r->DATABASE_COLLATION !== $deLaBase)
            ->pluck('DATABASE_COLLATION', 'ROUTINE_NAME')
            ->all();

        $this->assertSame([], $descolgados,
            "La base es «{$deLaBase}» y estos procedimientos se crearon con otra:\n  "
            .json_encode($descolgados, JSON_UNESCAPED_UNICODE)
            ."\nHay que volver a crearlos sobre la base ya con su colación definitiva.\n");
    }
}
