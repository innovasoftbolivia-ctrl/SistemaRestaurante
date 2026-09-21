<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Los identificadores de cajas, roles y cargos no tienen el tope de 255.
 *
 * Eran TINYINT. El AUTO_INCREMENT no vuelve atrás cuando una transacción se
 * deshace, así que cada corrida de las pruebas gastaba decenas de números sin
 * dejar ninguna fila: la base de pruebas llegó al 255 y las pruebas que crean
 * una caja empezaron a fallar solas.
 */
class IdentificadoresTest extends TestCase
{
    use DatabaseTransactions;

    public function test_cajas_roles_y_cargos_admiten_identificadores_mayores_que_255(): void
    {
        DB::table('cajas')->insert(['id' => 300, 'nombre' => 'Caja 300 (prueba)']);
        DB::table('roles')->insert(['id' => 300, 'nombre' => 'Rol 300 (prueba)']);
        DB::table('cargos')->insert(['id' => 300, 'nombre' => 'Cargo 300 (prueba)']);

        foreach (['cajas', 'roles', 'cargos'] as $tabla) {
            $this->assertTrue(DB::table($tabla)->where('id', 300)->exists(), "{$tabla} no guardó el id 300");
        }
    }

    /**
     * Cada columna que apunta a uno de ellos tiene su mismo tipo: si mañana
     * alguien amplía uno y olvida la columna que lo referencia, la clave
     * foránea o la columna generada vuelven a poner el tope.
     */
    public function test_lo_que_los_referencia_tiene_su_mismo_tipo(): void
    {
        $tipos = collect(DB::select(
            'SELECT CONCAT(TABLE_NAME, ".", COLUMN_NAME) AS columna, COLUMN_TYPE AS tipo
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND ((TABLE_NAME IN ("cajas", "roles", "cargos") AND COLUMN_NAME = "id")
                  OR (TABLE_NAME, COLUMN_NAME) IN (("empleados", "cargo_id"), ("usuarios", "rol_id"),
                     ("rol_permiso", "rol_id"), ("sesiones_caja", "caja_id"), ("sesiones_caja", "caja_abierta_uk")))'
        ))->pluck('tipo', 'columna');

        $this->assertCount(8, $tipos);

        foreach ($tipos as $columna => $tipo) {
            $this->assertSame('int unsigned', $tipo, "{$columna} es {$tipo}");
        }
    }
}
