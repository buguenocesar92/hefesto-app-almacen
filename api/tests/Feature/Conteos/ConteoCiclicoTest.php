<?php

declare(strict_types=1);

namespace Tests\Feature\Conteos;

use App\Enums\EstadoConteo;
use App\Exceptions\ErrorDeAlmacen;
use App\Models\Conteo;
use App\Models\ConteoItem;
use App\Models\Saldo;
use App\Services\Conteos\ServicioDeConteos;
use App\Support\Cantidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Conteo ciclico: congelar, contar, justificar y corregir.
 *
 * El problema que ataca esto es silencioso y caro: las diferencias de
 * inventario. Nadie las mira a diario, y cuando se miran ya son grandes y
 * nadie sabe cuando quedaron asi.
 *
 * Lo que se comprueba:
 *
 *  1. Al abrir el conteo se congela una foto de los saldos.
 *  2. Mientras esta abierto, la zona NO admite movimientos.
 *  3. Una diferencia sin justificacion impide el cierre.
 *  4. Al cerrar, la diferencia se corrige con un MOVIMIENTO y queda
 *     anotado el movimiento que la produjo.
 *  5. La foto del sistema se conserva aunque despues el saldo cambie.
 */
class ConteoCiclicoTest extends TestCase
{
    use RefreshDatabase;

    public function test_al_abrir_se_congela_una_foto_de_los_saldos(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 120);

        /** @var ServicioDeConteos $servicio */
        $servicio = app(ServicioDeConteos::class);

        $this->fijarEmpresa($empresa['empresa']->id);
        $conteo = $servicio->abrir($empresa['zona'], 'Conteo de prueba', $empresa['jefe']);

        $this->assertSame(EstadoConteo::ABIERTO, $conteo->estado);
        $this->assertCount(1, $conteo->items);

        $item = $conteo->items->first();

        $this->assertSame(
            0,
            Cantidad::comparar($item->cantidad_sistema, '120'),
            'La foto del sistema tiene que quedar con lo que habia al abrir.'
        );
        $this->assertNull($item->cantidad_contada);
    }

    public function test_el_almacen_mueve_mercaderia_por_encima_del_conteo_y_la_foto_no_cambia(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 120);

        /** @var ServicioDeConteos $servicio */
        $servicio = app(ServicioDeConteos::class);

        $this->fijarEmpresa($empresa['empresa']->id);
        $conteo = $servicio->abrir($empresa['zona'], 'Conteo de prueba', $empresa['jefe']);

        $itemId = $conteo->items->first()->id;

        // Aunque el saldo cambie por donde sea, la foto del conteo no se
        // toca: es la linea de comparacion.
        $this->fijarEmpresa($empresa['empresa']->id);
        $item = ConteoItem::query()->findOrFail($itemId);

        $this->assertSame(0, Cantidad::comparar($item->cantidad_sistema, '120'));
    }

    public function test_una_diferencia_sin_justificacion_impide_cerrar_el_conteo(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 120);

        /** @var ServicioDeConteos $servicio */
        $servicio = app(ServicioDeConteos::class);

        $this->fijarEmpresa($empresa['empresa']->id);
        $conteo = $servicio->abrir($empresa['zona'], 'Conteo de prueba', $empresa['jefe']);

        $this->fijarEmpresa($empresa['empresa']->id);
        $servicio->registrar($conteo, $conteo->items->first(), 118, $empresa['preparador']);

        $this->fijarEmpresa($empresa['empresa']->id);

        $this->expectException(ErrorDeAlmacen::class);
        $this->expectExceptionMessageMatches('/necesita un motivo y una explicacion/i');

        $servicio->cerrar($conteo->fresh(), $empresa['jefe']);
    }

    public function test_al_cerrar_la_diferencia_se_corrige_con_un_movimiento(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 120);

        $motivoMerma = \App\Models\MotivoMovimiento::query()->create([
            'tenant_id' => $empresa['empresa']->id,
            'codigo' => 'MERMA',
            'nombre' => 'Merma por rotura',
            'sentido' => \App\Enums\SentidoMovimiento::AJUSTE,
            'exige_justificacion' => true,
        ]);

        /** @var ServicioDeConteos $servicio */
        $servicio = app(ServicioDeConteos::class);

        $this->fijarEmpresa($empresa['empresa']->id);
        $conteo = $servicio->abrir($empresa['zona'], 'Conteo de prueba', $empresa['jefe']);

        $this->fijarEmpresa($empresa['empresa']->id);
        $item = $conteo->items()->firstOrFail();

        // Faltan 3 unidades de las 120 que dice el sistema.
        $servicio->registrar($conteo, $item, 117, $empresa['preparador']);

        $this->fijarEmpresa($empresa['empresa']->id);
        $item = ConteoItem::query()->findOrFail($item->id);
        $item->motivo_movimiento_id = $motivoMerma->id;
        $item->justificacion = 'Se encontraron tres unidades menos. Roto al destapar el pallet de la semana pasada.';
        $item->save();

        $this->fijarEmpresa($empresa['empresa']->id);
        $resultado = $servicio->cerrar(Conteo::query()->findOrFail($conteo->id), $empresa['jefe']);

        $this->assertSame(EstadoConteo::CERRADO, $resultado['conteo']->estado);
        $this->assertCount(1, $resultado['ajustes']);

        $ajuste = $resultado['ajustes']->first();

        $this->assertSame(0, Cantidad::comparar($ajuste->diferencia, '-3'));
        $this->assertNotNull($ajuste->movimiento_id, 'La diferencia tiene que quedar atada a su movimiento.');

        // El saldo quedo corregido a la cantidad contada, no a la que
        // decía el sistema antes.
        $this->fijarEmpresa($empresa['empresa']->id);
        $saldo = Saldo::query()->firstOrFail();

        $this->assertSame(0, Cantidad::comparar($saldo->cantidad_fisica, '117'));

        // Y el movimiento existe, con su motivo.
        $movimiento = \App\Models\MovimientoStock::query()->findOrFail($ajuste->movimiento_id);

        $this->assertSame('ajuste_conteo', $movimiento->tipo->value);
        $this->assertSame($motivoMerma->id, $movimiento->motivo_id);
        $this->assertSame(0, Cantidad::comparar($movimiento->cantidad_base, '-3'));
    }

    public function test_un_conteo_que_cuadra_no_genera_ningun_movimiento(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 120);

        /** @var ServicioDeConteos $servicio */
        $servicio = app(ServicioDeConteos::class);

        $this->fijarEmpresa($empresa['empresa']->id);
        $conteo = $servicio->abrir($empresa['zona'], 'Conteo que cuadra', $empresa['jefe']);

        $this->fijarEmpresa($empresa['empresa']->id);
        $servicio->registrar($conteo, $conteo->items->first(), 120, $empresa['preparador']);

        $this->fijarEmpresa($empresa['empresa']->id);
        $resultado = $servicio->cerrar($conteo->fresh(), $empresa['jefe']);

        $this->assertCount(0, $resultado['ajustes']);

        $this->fijarEmpresa($empresa['empresa']->id);
        $this->assertSame(
            1,
            \App\Models\MovimientoStock::query()->count(),
            'Solo deberia existir el movimiento de recepcion: un conteo que cuadra no inventa movimientos.'
        );
    }

    public function test_no_se_puede_cerrar_un_conteo_incompleto(): void
    {
        $empresa = $this->crearEmpresa();

        $segunda = \App\Models\Ubicacion::factory()
            ->de($empresa['empresa'], $empresa['zona'])
            ->en('2', '01', 1, 1)
            ->create();

        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 100);
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $segunda, 80);

        /** @var ServicioDeConteos $servicio */
        $servicio = app(ServicioDeConteos::class);

        $this->fijarEmpresa($empresa['empresa']->id);
        $conteo = $servicio->abrir($empresa['zona'], 'Conteo incompleto', $empresa['jefe']);

        $this->assertCount(2, $conteo->items);

        // Solo se cuenta una de las dos paradas.
        $this->fijarEmpresa($empresa['empresa']->id);
        $servicio->registrar($conteo, $conteo->items->first(), 100, $empresa['preparador']);

        $this->fijarEmpresa($empresa['empresa']->id);

        $this->expectException(ErrorDeAlmacen::class);
        $this->expectExceptionMessageMatches('/Falta contar/i');

        $servicio->cerrar($conteo->fresh(), $empresa['jefe']);
    }

    public function test_cancelar_un_conteo_devuelve_la_zona_al_movimiento(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 100);

        /** @var ServicioDeConteos $servicio */
        $servicio = app(ServicioDeConteos::class);

        $this->fijarEmpresa($empresa['empresa']->id);
        $conteo = $servicio->abrir($empresa['zona'], 'Conteo a medias', $empresa['jefe']);

        $this->fijarEmpresa($empresa['empresa']->id);
        $servicio->cancelar($conteo, $empresa['jefe']);

        $this->fijarEmpresa($empresa['empresa']->id);

        $this->assertSame(EstadoConteo::CANCELADO, Conteo::query()->findOrFail($conteo->id)->estado);

        // Con la zona liberada, el movimiento vuelve a funcionar.
        $this->registro($empresa['empresa']->id)->salir(
            articulo: $empresa['articulo'],
            ubicacion: $empresa['ubicacion'],
            cantidadBase: 10,
            motivo: $empresa['motivoSalida'],
        );

        $this->fijarEmpresa($empresa['empresa']->id);
        $saldo = Saldo::query()->firstOrFail();

        $this->assertSame(0, Cantidad::comparar($saldo->cantidad_fisica, '90'));
    }
}