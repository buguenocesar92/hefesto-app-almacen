<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Articulo;
use App\Models\Tenant;
use App\Models\Ubicacion;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Models\Zona;
use App\Services\Stock\RegistroDeMovimiento;
use App\Services\Tenancy\ContextoTenant;
use App\Models\MotivoMovimiento;
use App\Enums\SentidoMovimiento;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

/**
 * Base de las pruebas del almacen.
 *
 * Aporta lo que mas se prueba:
 *
 *  - `comoUsuario()`, que autentica a una persona Y fija su contexto de
 *    empresa en la base. IMPORTANTE: `actingAs()` por si solo NO fija el
 *    contexto, porque el middleware `ResolverTenant` corre dentro del
 *    grupo `web`, que estas pruebas no ejecutan. Sin fijar el contexto, la
 *    RLS no tendria contra que filtrar.
 *
 *  - `crearEmpresa()`, que arma una empresa con personas, unidades,
 *    zonas, motivos y ubicaciones listas para operar.
 *
 *  - `dejarStockEn()`, que carga mercaderia POR MOVIMIENTOS, porque no hay
 *    otra forma: el sistema no permite escribir saldos.
 */
abstract class TestCase extends BaseTestCase
{
    /** Empresa fijada para la prueba en curso. */
    protected ?string $empresaDePrueba = null;

    /** @var array<string, RegistroDeMovimiento> */
    private array $registros = [];

    /**
     * Autentica a una persona y fija su contexto de empresa en la base.
     */
    protected function comoUsuario(Usuario $usuario): static
    {
        $this->actingAs($usuario);

        return $this->fijarEmpresa($usuario->tenant_id);
    }

    /**
     * Emula lo que hace `App\Http\Middleware\ResolverTenant`.
     *
     * Se usa `set_config(..., false)`, o sea con alcance de SESION y no de
     * transaccion. En la aplicacion real es al reves (alcance de
     * transaccion, para que el worker de colas no arrastre la empresa de
     * otra peticion). En las pruebas cada caso abre su propia aplicacion y
     * su propia conexion, asi que el valor no sobrevive entre pruebas.
     */
    protected function fijarEmpresa(?string $empresaId): static
    {
        if ($empresaId === null) {
            return $this;
        }

        DB::select('SELECT set_config(?, ?, false)', [ContextoTenant::VARIABLE, $empresaId]);

        $this->empresaDePrueba = $empresaId;

        return $this;
    }

    /**
     * Crea una empresa con todo lo minimo para operar.
     *
     * @return array{
     *     empresa: Tenant,
     *     administrador: Usuario,
     *     jefe: Usuario,
     *     preparador: Usuario,
     *     base: UnidadMedida,
     *     caja: UnidadMedida,
     *     articulo: Articulo,
     *     zona: Zona,
     *     ubicacion: Ubicacion,
     *     motivoIngreso: MotivoMovimiento,
     *     motivoSalida: MotivoMovimiento
     * }
     */
    protected function crearEmpresa(string $dominio = 'ejemplo.cl'): array
    {
        $empresa = Tenant::factory()->create([
            'slug' => $dominio,
            'nombre' => 'Empresa ' . $dominio,
        ]);

        $this->fijarEmpresa($empresa->id);

        $administrador = Usuario::factory()->administrador()->de($empresa)->create([
            'email' => 'admin@' . $dominio,
        ]);

        $jefe = Usuario::factory()->jefe()->de($empresa)->create([
            'email' => 'jefe@' . $dominio,
        ]);

        $preparador = Usuario::factory()->de($empresa)->create([
            'email' => 'preparador@' . $dominio,
        ]);

        $base = UnidadMedida::factory()->de($empresa)->base()->create();
        $caja = UnidadMedida::factory()->de($empresa)->caja(12, 'CAJA-12')->create();

        $articulo = Articulo::factory()->completo($empresa, $base, $caja)->create([
            'sku' => 'ART-0001',
            'nombre' => 'Articulo de prueba',
            'stock_minimo' => '10',
        ]);

        $zona = Zona::query()->create([
            'tenant_id' => $empresa->id,
            'codigo' => 'A',
            'nombre' => 'Zona A',
            'pasillo_desde' => '1',
            'pasillo_hasta' => '6',
        ]);

        $motivoIngreso = MotivoMovimiento::query()->create([
            'tenant_id' => $empresa->id,
            'codigo' => 'RECEPCION',
            'nombre' => 'Recepcion de compra',
            'sentido' => SentidoMovimiento::INGRESO,
        ]);

        $motivoSalida = MotivoMovimiento::query()->create([
            'tenant_id' => $empresa->id,
            'codigo' => 'DESPACHO',
            'nombre' => 'Despacho por venta',
            'sentido' => SentidoMovimiento::SALIDA,
        ]);

        $ubicacion = Ubicacion::factory()->de($empresa, $zona)->en('1', '01', 1, 1)->create();

        return [
            'empresa' => $empresa,
            'administrador' => $administrador,
            'jefe' => $jefe,
            'preparador' => $preparador,
            'base' => $base,
            'caja' => $caja,
            'articulo' => $articulo,
            'zona' => $zona,
            'ubicacion' => $ubicacion,
            'motivoIngreso' => $motivoIngreso,
            'motivoSalida' => $motivoSalida,
        ];
    }

    /**
     * Registra movimiento de una empresa, con su contexto.
     */
    protected function registro(string $empresaId): RegistroDeMovimiento
    {
        if (! isset($this->registros[$empresaId])) {
            $this->registros[$empresaId] = app(RegistroDeMovimiento::class);
        }

        return $this->registros[$empresaId];
    }

    /**
     * Carga mercaderia POR MOVIMIENTOS, que es la unica via.
     *
     * @return array{saldo_id: string, fisico: string}
     */
    protected function dejarStockEn(
        string $empresaId,
        Articulo $articulo,
        Ubicacion $ubicacion,
        float $cantidad,
        ?MotivoMovimiento $motivo = null,
    ): array {
        $this->fijarEmpresa($empresaId);

        $motivo ??= MotivoMovimiento::query()->where('codigo', 'RECEPCION')->firstOrFail();

        $movimiento = $this->registro($empresaId)->ingresar(
            articulo: $articulo,
            ubicacion: $ubicacion,
            cantidadBase: $cantidad,
            motivo: $motivo,
            referencia: 'PRUEBA',
        );

        return [
            'saldo_id' => \App\Models\Saldo::query()
                ->where('articulo_id', $articulo->id)
                ->where('ubicacion_id', $ubicacion->id)
                ->value('id'),
            'fisico' => $movimiento->tipo->value,
        ];
    }

    /**
     * Lanza la excepcion de base que se espera, sin repetir el try/catch.
     */
    protected function esperarFalloDeBase(callable $operacion): void
    {
        try {
            $operacion();
        } catch (QueryException) {
            return;
        }

        $this->fail('Se esperaba un fallo de PostgreSQL y la operacion paso sin problema.');
    }
}