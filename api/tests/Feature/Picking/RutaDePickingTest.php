<?php

declare(strict_types=1);

namespace Tests\Feature\Picking;

use App\Models\Oleada;
use App\Models\OleadaItem;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Ubicacion;
use App\Services\Picking\ArmadorDeRuta;
use App\Services\Reservas\GestorDeReservas;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La ruta de picking ordenado por ubicacion.
 *
 * Aqui esta el valor operacional del producto. El mismo pedido, ordenado
 * al azar, obliga al operario a volver sobre pasos ya hechos; ordenado,
 * cada pasillo se recorre una vez.
 *
 * LA GARANTIA QUE SE PUEDE COMPROBAR
 * ---------------------------------
 *  1. Los pasillos aparecen en orden creciente.
 *  2. Cada pasillo ocupa UN SOLO bloque: no se parte en dos. Si se partiera,
 *     el operario volveria a ese pasillo y habria hecho el viaje dos
 *     veces.
 *  3. Dentro del pasillo el zigzag no rompe esas dos garantias.
 */
class RutaDePickingTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_ruta_ordena_por_pasillo_y_no_lo_parte_en_dos(): void
    {
        $empresa = $this->crearEmpresa();

        // Un articulo en muchas ubicaciones, en pasillos desordenados a
        // proposito. Si la ruta no ordena, el papel sale en el orden en
        // que se creo el stock y el operario camina en circulos.
        $ubicaciones = [];

        foreach ([['3', '02'], ['1', '05'], ['2', '01'], ['1', '01'], ['3', '01']] as [$pasillo, $rack]) {
            $ubicaciones[] = Ubicacion::factory()
                ->de($empresa['empresa'], $empresa['zona'])
                ->en($pasillo, $rack, 1, 1)
                ->create();
        }

        $this->fijarEmpresa($empresa['empresa']->id);

        foreach ($ubicaciones as $indice => $ubicacion) {
            $this->registro($empresa['empresa']->id)->ingresar(
                articulo: $empresa['articulo'],
                ubicacion: $ubicacion,
                cantidadBase: 50,
                motivo: $empresa['motivoIngreso'],
                referencia: 'PRUEBA-RUTA-' . $indice,
            );
        }

        // Un pedido que pide 250: obliga a tomar de las cinco
        // ubicaciones, asi que la ruta tiene cinco paradas.
        $pedido = $this->crearPedido($empresa, 250);

        /** @var GestorDeReservas $gestor */
        $gestor = app(GestorDeReservas::class);

        $this->fijarEmpresa($empresa['empresa']->id);
        $gestor->reservar($pedido, Carbon::now()->addDay(), $empresa['preparador']);

        $oleada = Oleada::query()->create([
            'tenant_id' => $empresa['empresa']->id,
            'zona_id' => $empresa['zona']->id,
            'nombre' => 'Ruta de prueba',
            'fecha' => Carbon::now()->addDay()->format('Y-m-d'),
        ]);

        /** @var ArmadorDeRuta $armador */
        $armador = app(ArmadorDeRuta::class);

        $this->fijarEmpresa($empresa['empresa']->id);
        $armador->armar($oleada, $empresa['zona'], $empresa['jefe']);

        $this->fijarEmpresa($empresa['empresa']->id);

        $paradas = OleadaItem::query()
            ->with('saldo.ubicacion')
            ->orderBy('secuencia')
            ->get();

        $this->assertCount(5, $paradas);

        $pasillos = $paradas->map(fn (OleadaItem $parada): string => (string) $parada->saldo->ubicacion->pasillo)->all();

        // 1. Orden creciente de pasillos.
        $ordenados = $pasillos;
        sort($ordenados, SORT_NATURAL);
        $this->assertSame($ordenados, $pasillos, 'La ruta tiene que ir de un pasillo al siguiente sin volver atras.');

        // 2. Cada pasillo en un solo bloque.
        $bloques = array_values(array_unique($pasillos));
        $this->assertSame(
            $bloques,
            $pasillos,
            'Ningun pasillo puede aparecer en dos tramos distintos de la ruta.'
        );

        // 3. Las secuencias son 1..N sin huecos ni repeticiones.
        $this->assertSame(
            range(1, 5),
            $paradas->map(fn (OleadaItem $parada): int => $parada->secuencia)->all()
        );
    }

    public function test_el_zigzag_alterna_el_sentido_del_pasillo_sin_romper_el_orden(): void
    {
        $empresa = $this->crearEmpresa();

        // Tres pasillos, cuatro racks cada uno. El recorrido tiene que
        // entrar y salir por extremos distintos en cada pasillo.
        foreach (['1', '2', '3'] as $pasillo) {
            foreach (['01', '02', '03', '04'] as $rack) {
                $this->fijarEmpresa($empresa['empresa']->id);

                $ubicacion = Ubicacion::factory()
                    ->de($empresa['empresa'], $empresa['zona'])
                    ->en($pasillo, $rack, 1, 1)
                    ->create();

                $this->registro($empresa['empresa']->id)->ingresar(
                    articulo: $empresa['articulo'],
                    ubicacion: $ubicacion,
                    cantidadBase: 10,
                    motivo: $empresa['motivoIngreso'],
                );
            }
        }

        $pedido = $this->crearPedido($empresa, 120);

        /** @var GestorDeReservas $gestor */
        $gestor = app(GestorDeReservas::class);

        $this->fijarEmpresa($empresa['empresa']->id);
        $gestor->reservar($pedido, Carbon::now()->addDay(), $empresa['preparador']);

        $oleada = Oleada::query()->create([
            'tenant_id' => $empresa['empresa']->id,
            'nombre' => 'Zigzag',
            'fecha' => Carbon::now()->addDay()->format('Y-m-d'),
        ]);

        /** @var ArmadorDeRuta $armador */
        $armador = app(ArmadorDeRuta::class);

        $this->fijarEmpresa($empresa['empresa']->id);
        $armador->armar($oleada, $empresa['zona'], $empresa['jefe']);

        $this->fijarEmpresa($empresa['empresa']->id);

        $paradas = OleadaItem::query()
            ->with('saldo.ubicacion')
            ->orderBy('secuencia')
            ->get();

        $porPasillo = $paradas
            ->groupBy(fn (OleadaItem $parada): string => (string) $parada->saldo->ubicacion->pasillo)
            ->map(fn ($grupo) => $grupo->map(fn (OleadaItem $parada): string => (string) $parada->saldo->ubicacion->rack)->values()->all());

        // Pasillo 1 (indice 0): de punta a punta.
        $this->assertSame(['01', '02', '03', '04'], $porPasillo['1']->all());

        // Pasillo 2 (indice 1): al reves, para salir por el otro extremo.
        $this->assertSame(['04', '03', '02', '01'], $porPasillo['2']->all());

        // Pasillo 3 (indice 2): vuelve a punta a punta.
        $this->assertSame(['01', '02', '03', '04'], $porPasillo['3']->all());
    }

    public function test_una_reserva_vencida_no_entra_a_la_ruta(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 50);

        $pedido = $this->crearPedido($empresa, 20);

        /** @var GestorDeReservas $gestor */
        $gestor = app(GestorDeReservas::class);

        // Reserva vencida: el stock ya esta disponible otra vez, asi que
        // no puede aparecer en una ruta como si siguiera comprometido.
        $this->fijarEmpresa($empresa['empresa']->id);
        $gestor->reservar($pedido, Carbon::now()->subHour(), $empresa['preparador']);

        $this->fijarEmpresa($empresa['empresa']->id);
        $gestor->vencer();

        $oleada = Oleada::query()->create([
            'tenant_id' => $empresa['empresa']->id,
            'nombre' => 'Sin reservas vigentes',
            'fecha' => Carbon::now()->addDay()->format('Y-m-d'),
        ]);

        /** @var ArmadorDeRuta $armador */
        $armador = app(ArmadorDeRuta::class);

        $this->fijarEmpresa($empresa['empresa']->id);

        $this->expectException(\App\Exceptions\ErrorDeAlmacen::class);
        $this->expectExceptionMessageMatches('/No hay pedidos reservados/i');

        $armador->armar($oleada, $empresa['zona'], $empresa['jefe']);
    }

    /**
     * @param  array{empresa: \App\Models\Tenant, articulo: \App\Models\Articulo}  $empresa
     */
    private function crearPedido(array $empresa, float $cantidadBase): Pedido
    {
        $pedido = Pedido::query()->create([
            'tenant_id' => $empresa['empresa']->id,
            'numero' => 'PED-RUTA-' . $cantidadBase,
            'prioridad' => 3,
        ]);

        PedidoItem::query()->create([
            'tenant_id' => $empresa['empresa']->id,
            'pedido_id' => $pedido->id,
            'articulo_id' => $empresa['articulo']->id,
            'cantidad' => $cantidadBase,
            'unidad_id' => $empresa['articulo']->unidad_venta_id,
            'cantidad_base' => (string) $cantidadBase,
        ]);

        return $pedido->fresh(['items']);
    }
}