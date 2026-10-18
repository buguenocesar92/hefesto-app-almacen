<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Enums\EstadoPedido;
use App\Enums\EstadoReserva;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Reserva;
use App\Models\Saldo;
use App\Services\Reservas\GestorDeReservas;
use App\Services\Stock\ConsultasDeDisponibilidad;
use App\Support\Cantidad;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PRUEBAS CRITICAS: una reserva vencida DEVUELVE la unidad.
 *
 * El error caro de un almacen no es que falte stock: es prometer stock
 * que ya salio. Si una reserva no expira, el almacen cree que tiene
 * disponible lo que en realidad esta comprometido a un pedido que nadie
 * confirmo, y el dia que aparece la diferencia nadie sabe cuando
 * aparecio.
 *
 * Lo que se comprueba aca:
 *
 *  1. Reservar NO suma stock fisico: sube lo reservado y baja lo
 *     disponible. El fisico sigue donde estaba.
 *  2. La reserva vence sola y la unidad vuelve a estar disponible.
 *  3. La reserva vencida queda como `vencida`, no como `liberada`: la
 *     diferencia entre "la libero una persona" y "se vencio sola" es la
 *     que despues se pregunta.
 *  4. Una reserva vigente NO se libera: expirar es por plazo, no por
 *     turno.
 */
class ReservaVencidaTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservar_no_suma_stock_fisico_sino_que_baja_el_disponible(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 100);

        $pedido = $this->crearPedido($empresa, 40);

        /** @var GestorDeReservas $gestor */
        $gestor = app(GestorDeReservas::class);

        $this->fijarEmpresa($empresa['empresa']->id);
        $gestor->reservar($pedido, Carbon::now()->addHours(24), $empresa['preparador']);

        $this->fijarEmpresa($empresa['empresa']->id);

        $saldo = Saldo::query()->firstOrFail();

        // LAS TRES CIFRAS, SEPARADAS. Este es el punto entero del modulo.
        $this->assertSame(0, Cantidad::comparar($saldo->cantidad_fisica, '100'), 'El fisico no cambia al reservar.');
        $this->assertSame(0, Cantidad::comparar($saldo->cantidad_reservada, '40'));
        $this->assertSame(0, Cantidad::comparar($saldo->disponible, '60'));

        /** @var ConsultasDeDisponibilidad $consultas */
        $consultas = app(ConsultasDeDisponibilidad::class);
        $cifras = $consultas->cifrasDeArticulo($empresa['articulo']);

        $this->assertSame(0, Cantidad::comparar($cifras['disponible'], '60'));
        $this->assertSame(0, Cantidad::comparar($cifras['fisico'], '100'));
    }

    public function test_una_reserva_vencida_devuelve_la_unidad_a_disponible(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 100);

        $pedido = $this->crearPedido($empresa, 40);

        /** @var GestorDeReservas $gestor */
        $gestor = app(GestorDeReservas::class);

        // Reserva con plazo vencido hace una hora.
        $this->fijarEmpresa($empresa['empresa']->id);
        $gestor->reservar($pedido, Carbon::now()->subHour(), $empresa['preparador']);

        $this->fijarEmpresa($empresa['empresa']->id);
        $saldo = Saldo::query()->firstOrFail();
        $this->assertSame(0, Cantidad::comparar($saldo->cantidad_reservada, '40'));

        // Se corre el trabajo de vencimiento, que es lo que corre cada
        // cinco minutos en la aplicacion.
        $liberadas = $gestor->vencer();

        $this->assertSame(1, $liberadas, 'Debería liberarse exactamente una reserva.');

        $this->fijarEmpresa($empresa['empresa']->id);

        $saldo = Saldo::query()->firstOrFail();

        $this->assertSame(0, Cantidad::comparar($saldo->cantidad_reservada, '0'), 'Lo reservado tiene que volver a cero.');
        $this->assertSame(0, Cantidad::comparar($saldo->disponible, '100'), 'La unidad vuelve a estar disponible.');
        $this->assertSame(0, Cantidad::comparar($saldo->cantidad_fisica, '100'), 'El fisico nunca se toco.');

        $reserva = Reserva::query()->firstOrFail();

        $this->assertSame(
            EstadoReserva::VENCIDA,
            $reserva->estado,
            'La reserva queda como vencida: se vencio sola, no la libero una persona.'
        );
        $this->assertNotNull($reserva->resuelto_en);
    }

    public function test_una_reserva_vigente_no_se_libera(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 100);

        $pedido = $this->crearPedido($empresa, 25);

        /** @var GestorDeReservas $gestor */
        $gestor = app(GestorDeReservas::class);

        $this->fijarEmpresa($empresa['empresa']->id);
        $gestor->reservar($pedido, Carbon::now()->addDays(2), $empresa['preparador']);

        $this->fijarEmpresa($empresa['empresa']->id);

        $liberadas = $gestor->vencer();

        $this->assertSame(0, $liberadas, 'Una reserva vigente no se toca.');

        $saldo = Saldo::query()->firstOrFail();

        $this->assertSame(0, Cantidad::comparar($saldo->cantidad_reservada, '25'));
        $this->assertSame(0, Cantidad::comparar($saldo->disponible, '75'));
    }

    public function test_no_se_puede_reservar_mas_de_lo_que_hay_en_el_rack(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 10);

        $pedido = $this->crearPedido($empresa, 30);

        /** @var GestorDeReservas $gestor */
        $gestor = app(GestorDeReservas::class);

        $this->fijarEmpresa($empresa['empresa']->id);

        $this->expectException(\App\Exceptions\ErrorDeAlmacen::class);
        $this->expectExceptionMessageMatches('/No hay stock disponible/i');

        $gestor->reservar($pedido, Carbon::now()->addDay(), $empresa['preparador']);
    }

    public function test_el_despacho_consume_la_reserva_y_saca_la_mercaderia(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 100);

        $pedido = $this->crearPedido($empresa, 40);

        /** @var GestorDeReservas $gestor */
        $gestor = app(GestorDeReservas::class);

        $this->fijarEmpresa($empresa['empresa']->id);
        $gestor->reservar($pedido, Carbon::now()->addDay(), $empresa['preparador']);

        $this->fijarEmpresa($empresa['empresa']->id);
        $gestor->consumir($pedido->fresh(), $empresa['motivoSalida'], $empresa['preparador']);

        $this->fijarEmpresa($empresa['empresa']->id);

        $saldo = Saldo::query()->firstOrFail();

        // El fisico bajo y lo reservado quedo en cero: la mercaderia salio
        // de verdad, no quedo flotando como "disponible".
        $this->assertSame(0, Cantidad::comparar($saldo->cantidad_fisica, '60'));
        $this->assertSame(0, Cantidad::comparar($saldo->cantidad_reservada, '0'));
        $this->assertSame(0, Cantidad::comparar($saldo->disponible, '60'));

        $reserva = Reserva::query()->firstOrFail();
        $this->assertSame(EstadoReserva::CONSUMIDA, $reserva->estado);
    }

    public function test_liberar_un_pedido_devuelve_todas_sus_reservas(): void
    {
        $empresa = $this->crearEmpresa();
        $this->dejarStockEn($empresa['empresa']->id, $empresa['articulo'], $empresa['ubicacion'], 100);

        $pedido = $this->crearPedido($empresa, 70);

        /** @var GestorDeReservas $gestor */
        $gestor = app(GestorDeReservas::class);

        $this->fijarEmpresa($empresa['empresa']->id);
        $gestor->reservar($pedido, Carbon::now()->addDay(), $empresa['preparador']);

        $this->fijarEmpresa($empresa['empresa']->id);
        $liberadas = $gestor->liberarPedido($pedido->fresh());

        $this->assertSame(1, $liberadas);

        $this->fijarEmpresa($empresa['empresa']->id);

        $saldo = Saldo::query()->firstOrFail();

        $this->assertSame(0, Cantidad::comparar($saldo->cantidad_reservada, '0'));
        $this->assertSame(0, Cantidad::comparar($saldo->disponible, '100'));

        $pedido = Pedido::query()->findOrFail($pedido->id);
        $this->assertSame(EstadoPedido::PENDIENTE, $pedido->estado);

        $item = PedidoItem::query()->firstOrFail();
        $this->assertSame('pendiente', $item->estado->value);
    }

    /**
     * @param  array{empresa: \App\Models\Tenant, articulo: \App\Models\Articulo, preparador: \App\Models\Usuario}  $empresa
     */
    private function crearPedido(array $empresa, float $cantidadBase): Pedido
    {
        $pedido = Pedido::query()->create([
            'tenant_id' => $empresa['empresa']->id,
            'numero' => 'PED-PRUEBA-1',
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