<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Enums\EstadoConteo;
use App\Exceptions\ErrorDeAlmacen;
use App\Models\Conteo;
use App\Models\MovimientoStock;
use App\Models\Saldo;
use App\Services\Conteos\ServicioDeConteos;
use App\Services\Stock\RegistroDeMovimiento;
use App\Support\Cantidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * PRUEBAS CRITICAS: el stock NO SE PUEDE EDITAR.
 *
 * La regla del almacen es "el stock nunca se edita: se mueve". Estas
 * pruebas comprueban que la regla se sostiene en las tres capas:
 *
 *  1. La aplicacion: guardar un saldo a mano revienta en PHP.
 *  2. La base: un `UPDATE` escrito a mano, tipo `psql`, tambien revienta.
 *     Esta es la prueba que importa, porque un humano con acceso a la base
 *     no pasa por el codigo de la aplicacion.
 *  3. La consecuencia: la unica forma de cambiar una cantidad es dejar un
 *     movimiento con motivo.
 */
class StockInmutableTest extends TestCase
{
    use RefreshDatabase;

    public function test_actualizar_el_saldo_desde_el_modelo_revierte(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 100);

        $saldo = Saldo::query()
            ->where('articulo_id', $empresa['articulo']->id)
            ->firstOrFail();

        $saldo->cantidad_fisica = '5000';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no se editan: se mueven/i');

        $saldo->save();
    }

    public function test_escribir_directamente_en_la_base_tambien_revierte(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 100);

        $saldoId = Saldo::query()
            ->where('articulo_id', $empresa['articulo']->id)
            ->value('id');

        // Esto es lo que haria alguien con un cliente de PostgreSQL en la
        // mano. La aplicacion no se entera y la RLS tampoco lo va a
        // impedir: para eso esta el gatillo.
        $this->esperarFalloDeBase(function () use ($saldoId): void {
            DB::table('saldos')->where('id', $saldoId)->update(['cantidad_fisica' => 5000]);
        });

        $this->fijarEmpresa($empresa['empresa']->id);

        $saldo = Saldo::query()->findOrFail($saldoId);

        $this->assertSame(
            0,
            Cantidad::comparar($saldo->cantidad_fisica, '100'),
            'La cantidad no deberia haber cambiado: la escritura tiene que quedar rechazada.'
        );
    }

    public function test_insertar_un_saldo_a_mano_tambien_revierte(): void
    {
        $empresa = $this->crearEmpresa();

        $this->esperarFalloDeBase(function () use ($empresa): void {
            DB::table('saldos')->insert([
                'id' => '11111111-1111-4111-8111-111111111111',
                'tenant_id' => $empresa['empresa']->id,
                'articulo_id' => $empresa['articulo']->id,
                'ubicacion_id' => $empresa['ubicacion']->id,
                'cantidad_fisica' => 5000,
                'cantidad_reservada' => 0,
            ]);
        });
    }

    public function test_los_movimientos_no_se_pueden_editar_ni_borrar(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 100);

        $movimiento = MovimientoStock::query()->firstOrFail();

        $this->esperarFalloDeBase(function () use ($movimiento): void {
            DB::table('movimientos_stock')
                ->where('id', $movimiento->id)
                ->update(['cantidad_base' => 1]);
        });

        $this->esperarFalloDeBase(function () use ($movimiento): void {
            DB::table('movimientos_stock')->where('id', $movimiento->id)->delete();
        });
    }

    public function test_el_saldo_si_cambia_cuando_pasa_por_un_movimiento(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 100);

        $registro = $this->registro($empresa['empresa']->id);

        $registro->salir(
            articulo: $empresa['articulo'],
            ubicacion: $empresa['ubicacion'],
            cantidadBase: 30,
            motivo: $empresa['motivoSalida'],
            referencia: 'PRUEBA-SALIDA',
        );

        $this->fijarEmpresa($empresa['empresa']->id);

        $saldo = Saldo::query()
            ->where('articulo_id', $empresa['articulo']->id)
            ->firstOrFail();

        $this->assertSame(0, Cantidad::comparar($saldo->cantidad_fisica, '70'));
        $this->assertSame(2, MovimientoStock::query()->count());
    }

    public function test_no_se_puede_sacar_mas_stock_del_que_hay(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 10);

        $registro = $this->registro($empresa['empresa']->id);

        $this->expectException(ErrorDeAlmacen::class);
        $this->expectExceptionMessageMatches('/No hay stock suficiente/i');

        $registro->salir(
            articulo: $empresa['articulo'],
            ubicacion: $empresa['ubicacion'],
            cantidadBase: 11,
            motivo: $empresa['motivoSalida'],
        );
    }

    public function test_el_saldo_es_siempre_la_suma_de_sus_movimientos(): void
    {
        $empresa = $this->crearEmpresa();
        $registro = $this->registro($empresa['empresa']->id);

        $registro->ingresar($empresa['articulo'], $empresa['ubicacion'], 200, $empresa['motivoIngreso']);
        $registro->salir($empresa['articulo'], $empresa['ubicacion'], 50, $empresa['motivoSalida']);
        $registro->ingresar($empresa['articulo'], $empresa['ubicacion'], 25, $empresa['motivoIngreso']);

        $this->fijarEmpresa($empresa['empresa']->id);

        $saldo = Saldo::query()
            ->where('articulo_id', $empresa['articulo']->id)
            ->firstOrFail();

        $suma = (float) MovimientoStock::query()->sum('cantidad_base');

        $this->assertSame(
            0,
            Cantidad::comparar($saldo->cantidad_fisica, (string) $suma),
            'El saldo tiene que ser exactamente la suma de los movimientos.'
        );
        $this->assertSame(0, Cantidad::comparar($saldo->cantidad_fisica, '175'));
    }

    public function test_el_trasparo_es_un_par_de_movimientos_y_no_una_suma_sola(): void
    {
        $empresa = $this->crearEmpresa();

        $destino = \App\Models\Ubicacion::factory()
            ->de($empresa['empresa'], $empresa['zona'])
            ->en('4', '01', 1, 1)
            ->create();

        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 100);

        $registro = $this->registro($empresa['empresa']->id);

        $par = $registro->traspasar(
            articulo: $empresa['articulo'],
            origen: $empresa['ubicacion'],
            destino: $destino,
            cantidadBase: 40,
            motivo: $empresa['motivoIngreso'],
            referencia: 'TRASPASO-1',
        );

        $this->assertNotSame($par['salida']->id, $par['entrada']->id);
        $this->assertNotNull($par['salida']->grupo_traspaso);
        $this->assertSame($par['salida']->grupo_traspaso, $par['entrada']->grupo_traspaso);

        // El error tipico del almacen: sumar al destino sin descontar del
        // origen. Aca los dos ocurrieron en la misma transaccion.
        $this->fijarEmpresa($empresa['empresa']->id);

        $saldoOrigen = Saldo::query()
            ->where('ubicacion_id', $empresa['ubicacion']->id)
            ->firstOrFail();

        $saldoDestino = Saldo::query()
            ->where('ubicacion_id', $destino->id)
            ->firstOrFail();

        $this->assertSame(0, Cantidad::comparar($saldoOrigen->cantidad_fisica, '60'));
        $this->assertSame(0, Cantidad::comparar($saldoDestino->cantidad_fisica, '40'));

        // Y el total del almacen no cambio: un traspaso no crea ni destruye
        // mercaderia.
        $this->assertSame(0, Cantidad::comparar(
            (float) $saldoOrigen->cantidad_fisica + (float) $saldoDestino->cantidad_fisica,
            '100'
        ));
    }

    public function test_no_se_puede_mover_mercaderia_de_una_ubicacion_en_conteo_abierto(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 100);

        /** @var ServicioDeConteos $conteos */
        $conteos = app(ServicioDeConteos::class);
        $conteos->abrir($empresa['zona'], 'Conteo de prueba', $empresa['jefe']);

        $this->fijarEmpresa($empresa['empresa']->id);

        $conteoVigente = Conteo::query()->where('estado', EstadoConteo::ABIERTO->value)->firstOrFail();

        $this->assertSame(
            EstadoConteo::ABIERTO,
            $conteoVigente->estado,
            'El conteo deberia quedar abierto y con la zona congelada.'
        );

        $registro = $this->registro($empresa['empresa']->id);

        $this->expectException(ErrorDeAlmacen::class);
        $this->expectExceptionMessageMatches('/en conteo abierto/i');

        $registro->salir(
            articulo: $empresa['articulo'],
            ubicacion: $empresa['ubicacion'],
            cantidadBase: 10,
            motivo: $empresa['motivoSalida'],
        );
    }
}