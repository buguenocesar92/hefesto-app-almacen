<?php

declare(strict_types=1);

namespace App\Services\Reservas;

use App\Enums\EstadoItemPedido;
use App\Enums\EstadoPedido;
use App\Enums\EstadoReserva;
use App\Exceptions\ErrorDeAlmacen;
use App\Models\MotivoMovimiento;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\Reserva;
use App\Models\Saldo;
use App\Models\Usuario;
use App\Services\Stock\ConsultasDeDisponibilidad;
use App\Services\Stock\RegistroDeMovimiento;
use App\Support\Cantidad;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Reservas: mercaderia comprometida a un pedido, con fecha de vencimiento.
 *
 * POR QUE LA RESERVA TIENE PLAZO
 * -----------------------------
 * El error caro de un almacen no es que falte stock: es prometer stock que
 * ya salio. Un pedido que nadie confirma deja la mercaderia reservada
 * para siempre, el almacen cree que tiene disponible lo que en realidad
 * esta en el camion de otro, y la diferencia aparece semanas despues sin
 * forma de explicarla.
 *
 * Por eso TODA reserva nace con `expira_en`, y `vencer()` devuelve las
 * unidades a disponible. El estado con que queda es `vencida`, no
 * `liberada`: la diferencia entre "la libero una persona" y "se vencio
 * sola" es justo la que despues se pregunta el jefe de almacen.
 *
 * DOnde se toma la mercaderia
 * ---------------------------
 * De los saldos con disponible, en orden de recorrido (pasillo, rack,
 * nivel, posicion). No es arbitrario: si el picking toma lo mas cercano,
 * la ruta que se arma despues ya viene ordenada y no hay que volver a
 * preguntar donde esta nada.
 */
class GestorDeReservas
{
    public function __construct(
        private readonly RegistroDeMovimiento $movimientos,
        private readonly ConsultasDeDisponibilidad $consultas,
    ) {}

    /**
     * Reserva todas las lineas pendientes de un pedido.
     *
     * @return list<Reserva> Las reservas creadas.
     */
    public function reservar(
        Pedido $pedido,
        ?CarbonInterface $expiraEn = null,
        ?Usuario $usuario = null,
    ): array {
        if (! $pedido->puedeReservarse()) {
            throw new ErrorDeAlmacen(
                sprintf('El pedido %s esta en estado %s y no se puede reservar.', $pedido->numero, $pedido->estado->value),
                'pedido_no_reservable'
            );
        }

        $expiraEn ??= Carbon::now()->addHours((int) config('almacen.reservas.horas_validez', 72));

        $creadas = [];

        foreach ($pedido->items()->whereIn('estado', [
            EstadoItemPedido::PENDIENTE,
            EstadoItemPedido::RESERVADO,
        ])->with('articulo')->get() as $item) {
            foreach ($this->reservarItem($item, $expiraEn) as $reserva) {
                $creadas[] = $reserva;
            }

            $item->estado = EstadoItemPedido::RESERVADO;
            $item->save();
        }

        $pedido->estado = EstadoPedido::RESERVADO;
        $pedido->save();

        return $creadas;
    }

    /**
     * Reserva de una linea, repartida en las ubicaciones que alcancen.
     *
     * @return list<Reserva>
     */
    public function reservarItem(PedidoItem $item, CarbonInterface $expiraEn): array
    {
        $faltante = (float) $item->cantidad_base;
        $creadas = [];

        foreach ($this->consultas->saldosDisponibles($item->articulo) as $saldo) {
            if (Cantidad::comparar($faltante, '0') <= 0) {
                break;
            }

            $disponible = (float) $saldo->disponible;
            $tomado = min($faltante, $disponible);

            $this->movimientos->reservar($saldo, $tomado);

            $creadas[] = Reserva::query()->create([
                'tenant_id' => $item->tenant_id,
                'pedido_item_id' => $item->id,
                'saldo_id' => $saldo->id,
                'cantidad_base' => Cantidad::normalizar($tomado),
                'estado' => EstadoReserva::VIGENTE,
                'expira_en' => $expiraEn,
            ]);
        }

        $faltante = Cantidad::normalizar($faltante);

        if (Cantidad::esPositivo($faltante)) {
            throw new ErrorDeAlmacen(
                sprintf(
                    'No hay stock disponible para reservar todo el pedido. Faltan %s unidades base de %s.',
                    $faltante,
                    $item->articulo->sku
                ),
                'stock_insuficiente_para_reservar'
            );
        }

        return $creadas;
    }

    /**
     * Devuelve a disponible todo lo reservado por un pedido.
     *
     * @param  EstadoReserva  $estado  `LIBERADA` cuando fue una persona,
     *                               `VENCIDA` cuando se cumplio el plazo.
     * @return int Cantidad de reservas resueltas.
     */
    public function liberarPedido(Pedido $pedido, EstadoReserva $estado = EstadoReserva::LIBERADA): int
    {
        $reservas = Reserva::query()
            ->where('estado', EstadoReserva::VIGENTE->value)
            ->whereIn('pedido_item_id', $pedido->items()->select('id'))
            ->with('saldo')
            ->get();

        foreach ($reservas as $reserva) {
            $this->resolver($reserva, $estado);
        }

        $pedido->estado = EstadoPedido::PENDIENTE;
        $pedido->save();

        $pedido->items()
            ->where('estado', EstadoItemPedido::RESERVADO)
            ->update(['estado' => EstadoItemPedido::PENDIENTE->value]);

        return $reservas->count();
    }

    /**
     * Vence las reservas cuyo plazo paso y devuelve las unidades.
     *
     * Es lo que corre el trabajo en cola y el comando
     * `almacen:liberar-reservas-vencidas`. Debe invocarse DENTRO del
     * contexto de una empresa: la RLS no dejaria leer las reservas de
     * nadie sin el.
     *
     * @return int Reservas vencidas y liberadas.
     */
    public function vencer(?CarbonInterface $ahora = null): int
    {
        $ahora ??= Carbon::now();

        $vencidas = Reserva::query()
            ->where('estado', EstadoReserva::VIGENTE->value)
            ->where('expira_en', '<', $ahora)
            ->with('saldo', 'itemPedido')
            ->orderBy('expira_en')
            ->limit(500)
            ->get();

        $liberadas = 0;

        foreach ($vencidas as $reserva) {
            $this->resolver($reserva, EstadoReserva::VENCIDA);
            $liberadas++;
        }

        return $liberadas;
    }

    /**
     * Prorroga una reserva. Se usa cuando el cliente aviso que llega a
     * retirar manana: el stock sigue comprometido, pero con plazo nuevo.
     */
    public function extender(Reserva $reserva, CarbonInterface $nuevoVencimiento): Reserva
    {
        if (! $reserva->estado->sigueTomandoStock()) {
            throw new ErrorDeAlmacen(
                sprintf('La reserva esta %s y no se puede prorrogar.', $reserva->estado->value),
                'reserva_no_vigente'
            );
        }

        $reserva->expira_en = $nuevoVencimiento;
        $reserva->save();

        return $reserva;
    }

    /**
     * Consumo de una reserva en el despacho: la unidad sale del rack y la
     * reserva se cierra en la misma operacion.
     *
     * Primero se libera lo reservado y despues sale la mercaderia: si se
     * hiciera al reves, la salida chocaria contra su propia reserva.
     */
    public function consumir(
        Pedido $pedido,
        MotivoMovimiento $motivo,
        ?Usuario $usuario = null,
        ?string $referencia = null,
    ): int {
        $reservas = Reserva::query()
            ->where('estado', EstadoReserva::VIGENTE->value)
            ->whereIn('pedido_item_id', $pedido->items()->select('id'))
            ->with(['saldo', 'itemPedido'])
            ->get();

        foreach ($reservas as $reserva) {
            $item = $reserva->itemPedido;

            $this->movimientos->liberarReserva($reserva->saldo, $reserva->cantidad_base);

            $this->movimientos->salir(
                articulo: $item->articulo,
                ubicacion: $reserva->saldo->ubicacion,
                cantidadBase: $reserva->cantidad_base,
                motivo: $motivo,
                referencia: $referencia ?? $pedido->numero,
                usuario: $usuario,
                notas: 'Salida por despacho del pedido ' . $pedido->numero,
                consumiendoReserva: true,
            );

            $reserva->estado = EstadoReserva::CONSUMIDA;
            $reserva->resuelto_en = Carbon::now();
            $reserva->save();
        }

        $pedido->items()->update(['estado' => EstadoItemPedido::DESPACHADO->value]);
        $pedido->estado = EstadoPedido::DESPACHADO;
        $pedido->save();

        return $reservas->count();
    }

    /** Marca como recogido lo que el operario ya llevo al area de despacho. */
    public function marcarRecogido(PedidoItem $item): void
    {
        $item->estado = EstadoItemPedido::RECOGIDO;
        $item->save();
    }

    /**
     * Resuelve una reserva: baja lo reservado y deja constancia de por
     * que se resolvió.
     */
    private function resolver(Reserva $reserva, EstadoReserva $estado): void
    {
        if ($reserva->saldo !== null) {
            $this->movimientos->liberarReserva($reserva->saldo, $reserva->cantidad_base);
        }

        $reserva->estado = $estado;
        $reserva->resuelto_en = Carbon::now();
        $reserva->save();
    }

    /** Saldo de la reserva, para las consultas de la interfaz. */
    public function saldoDe(Reserva $reserva): ?Saldo
    {
        return $reserva->saldo;
    }
}