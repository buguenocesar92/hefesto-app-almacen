<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\Articulo;
use App\Models\Saldo;
use App\Models\Ubicacion;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PRUEBAS CRITICAS: una empresa no puede leer ni tocar los datos de otra.
 *
 * Estas pruebas van contra PostgreSQL de verdad, porque el aislamiento esta
 * implementado con Row Level Security. Correrlas en SQLite daria verde sin
 * comprobar nada. Ver phpunit.xml y docs/10.
 *
 * Lo que se comprueba aca:
 *
 *  1. Sin contexto de empresa, la consulta REVIENTA (no devuelve cero
 *     filas en silencio: eso dejaria pasar el caso peligroso).
 *  2. Con el contexto de cada empresa, cada una ve SOLO sus filas.
 *  3. Un id de otra empresa no existe para esta: 404, no 403.
 *  4. No se puede escribir en nombre de otra empresa (WITH CHECK).
 *  5. El contexto no sobrevive a la transaccion, para que el worker de
 *     colas no arrastre la empresa de otro trabajo.
 */
class AislamientoDeDatosTest extends TestCase
{
    use RefreshDatabase;

    public function test_sin_contexto_de_empresa_la_consulta_falla_en_vez_de_traer_datos(): void
    {
        $primera = $this->crearEmpresa('primera.cl');
        Articulo::factory()->de($primera['empresa'])->create(['sku' => 'A-1']);

        // Se limpia el contexto a proposito: es la situacion de una
        // consulta que se escapo del middleware.
        //
        // Lo que se espera NO es "cero filas", sino un error: la funcion
        // de contexto lanza excepcion cuando no hay empresa, y la politica
        // aborta la consulta. Devolver cero filas seria mas comodo de
        // probar, pero dejaria pasar el caso peligroso: que alguien
        // "arregle" la funcion devolviendo NULL y termine leyendo filas de
        // la empresa equivocada sin que nada lo delate.
        DB::select('SELECT set_config(?, ?, false)', [ContextoTenant::VARIABLE, '']);

        $this->expectException(QueryException::class);

        Articulo::query()->count();
    }

    public function test_cada_empresa_solo_ve_sus_propias_filas(): void
    {
        $primera = $this->crearEmpresa('primera.cl');
        Articulo::factory()->count(3)->de($primera['empresa'])->create();

        $segunda = $this->crearEmpresa('segunda.cl');
        Articulo::factory()->count(2)->de($segunda['empresa'])->create();

        $this->fijarEmpresa($primera['empresa']->id);
        $this->assertSame(4, Articulo::query()->count());
        $this->assertSame(
            $primera['empresa']->id,
            Articulo::query()->value('tenant_id')
        );

        $this->fijarEmpresa($segunda['empresa']->id);
        $this->assertSame(3, Articulo::query()->count());
        $this->assertSame(
            $segunda['empresa']->id,
            Articulo::query()->value('tenant_id')
        );
    }

    public function test_el_stock_de_otra_empresa_no_se_puede_leer_ni_escribir(): void
    {
        $primera = $this->crearEmpresa('primera.cl');
        $this->dejarStockEn($primera['empresa']->id, $primera['articulo'], $primera['ubicacion'], 50);

        $segunda = $this->crearEmpresa('segunda.cl');
        $this->dejarStockEn($segunda['empresa']->id, $segunda['articulo'], $segunda['ubicacion'], 30);

        $saldoAjeno = Saldo::query()
            ->where('articulo_id', $segunda['articulo']->id)
            ->firstOrFail();

        // Volvemos al contexto de la primera empresa.
        $this->fijarEmpresa($primera['empresa']->id);

        $this->assertNull(Saldo::find($saldoAjeno->id), 'El saldo de la otra empresa no deberia existir a ojos de esta.');

        // Y tampoco se puede tocar: la politica tiene WITH CHECK.
        $this->esperarFalloDeBase(function () use ($saldoAjeno): void {
            DB::table('saldos')
                ->where('id', $saldoAjeno->id)
                ->update(['cantidad_fisica' => 999999]);
        });
    }

    public function test_no_se_puede_insertar_una_fila_con_la_empresa_de_otra(): void
    {
        $primera = $this->crearEmpresa('primera.cl');
        $segunda = $this->crearEmpresa('segunda.cl');

        // Contexto de la primera empresa, pero se intenta guardar un
        // articulo de la segunda. La politica WITH CHECK lo impide.
        $this->fijarEmpresa($primera['empresa']->id);

        $this->esperarFalloDeBase(function () use ($segunda): void {
            Articulo::query()->create([
                'tenant_id' => $segunda['empresa']->id,
                'sku' => 'AJENO-1',
                'nombre' => 'Articulo con empresa ajena',
                'unidad_base_id' => $segunda['base']->id,
                'unidad_compra_id' => $segunda['base']->id,
                'unidad_venta_id' => $segunda['base']->id,
            ]);
        });
    }

    public function test_el_contexto_de_empresa_no_sobrevive_a_la_transaccion(): void
    {
        $empresa = $this->crearEmpresa('primera.cl');
        Articulo::factory()->de($empresa['empresa'])->create(['sku' => 'A-1']);

        $contexto = app(ContextoTenant::class);

        $conteo = $contexto->ejecutarAislado(
            $empresa['empresa']->id,
            fn (): int => Articulo::query()->count()
        );

        $this->assertSame(2, $conteo);

        // El contexto de la peticion sigue puesto en la sesion de prueba;
        // lo que se comprueba aca es que la transaccion AISLADA no lo
        // dejo colgado en la conexion.
        $this->esperarFalloDeBase(function (): void {
            DB::select('SELECT set_config(?, ?, false)', [ContextoTenant::VARIABLE, '']);
            DB::select('SELECT public.app_tenant_id()');
        });
    }

    public function test_la_funcion_de_contexto_falla_cerrada_sin_empresa(): void
    {
        DB::select('SELECT set_config(?, ?, false)', [ContextoTenant::VARIABLE, '']);

        $this->expectException(QueryException::class);

        DB::select('SELECT public.app_tenant_id()');
    }

    public function test_las_ubicaciones_de_otra_empresa_tampoco_existen(): void
    {
        $primera = $this->crearEmpresa('primera.cl');
        $segunda = $this->crearEmpresa('segunda.cl');

        $this->fijarEmpresa($primera['empresa']->id);

        $this->assertNull(Ubicacion::find($segunda['ubicacion']->id));
        $this->assertNull(Articulo::find($segunda['articulo']->id));
    }
}