<?php

declare(strict_types=1);

namespace App\Services\Picking;

use App\Enums\EstadoOleada;
use App\Enums\EstadoPedido;
use App\Enums\EstadoReserva;
use App\Exceptions\ErrorDeAlmacen;
use App\Models\Oleada;
use App\Models\OleadaItem;
use App\Models\Pedido;
use App\Models\Reserva;
use App\Models\Ubicacion;
use App\Models\Usuario;
use App\Models\Zona;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Armando la ruta de recoleccion de una oleada.
 *
 * AQUI ESTA EL VALOR OPERACIONAL DEL PRODUCTO
 * -------------------------------------------
 * El sistema que dice "tiene 400 unidades" es tan util como el papel. El
 * que dice "tiene 400, estan en el pasillo B, racks 4 al 9, nivel 2, y
 * de paso las vas a tener en la mano en este orden" ahorra tiempo real de
 * operarios. La ruta ordenada ES la diferencia.
 *
 * COMO SE ORDENA
 * --------------
 * Dos reglas, en este orden:
 *
 *  1. Por pasillo, de corrido. Esta es la garantia que importa: cada
 *     pasillo se recorre UNA vez, en un solo bloque. Sin esta regla,
 *     "400 unidades" se vuelven veinte viajes al mismo pasillo.
 *  2. Adentro del pasillo, en zigzag. Un pasillo se entra por un extremo
 *     y se sale por el otro; el siguiente se recorre al reves. Es la
 *     forma clasica de recorrer una retícula sin volver sobre los pasos, y
 *     ahorro pasos reales.
 *
 * El zigzag alterna por pasillo: el primero de punta a punta, el segundo
 * al reves, y asi. Es una decision de diseno, no un estandar de la
 * industria: la garantia que se puede comprobar con una prueba es que
 * ningun pasillo se parte en dos, y eso esta verificado en
 * `tests/Feature/Picking/RutaDePickingTest.php`.
 *
 * DE DONDE SALE CADA PARADA
 * -------------------------
 * De las reservas VIGENTES de los pedidos reservados. Como la reserva ya
 * sabe de que rack se toma cada linea, aca no se busca nada: se ordena lo
 * que ya esta resuelto. Una reserva vencida no entra a la ruta, porque su
 * stock ya esta disponible otra vez.
 */
class ArmadorDeRuta
{
    /**
     * Arma la ruta de una oleada y la deja lista para imprimir.
     *
     * @return Collection<int, OleadaItem> Paradas ya ordenadas.
     */
    public function armar(
        Oleada $oleada,
        ?Zona $zona = null,
        ?Usuario $usuario = null,
    ): Collection {
        if ($oleada->estado !== EstadoOleada::ABIERTA) {
            throw new ErrorDeAlmacen(
                sprintf('La oleada %s no está abierta.', $oleada->nombre),
                'oleada_no_abierta'
            );
        }

        $paradas = $this->paradasCandidatas($zona);

        if ($paradas->isEmpty()) {
            throw new ErrorDeAlmacen(
                'No hay pedidos reservados con mercaderia disponible para armar esta oleada.',
                'oleada_sin_reservas'
            );
        }

        $ordenadas = $this->ordenarPorUbicacion($paradas);

        $oleada->items()->delete();

        $creadas = collect();

        foreach ($ordenadas as $secuencia => $parada) {
            $creadas->push(OleadaItem::query()->create([
                'tenant_id' => $oleada->tenant_id,
                'oleada_id' => $oleada->id,
                'pedido_item_id' => $parada['pedido_item_id'],
                'saldo_id' => $parada['saldo_id'],
                'cantidad_base' => $parada['cantidad_base'],
                'secuencia' => $secuencia,
                'estado' => OleadaItem::PENDIENTE,
            ]));
        }

        $oleada->estado = EstadoOleada::EN_CURSO;
        $oleada->armada_por = $usuario?->id;
        $oleada->save();

        return $creadas;
    }

    /**
     * Paradas candidatas: reservas vigentes de pedidos reservados.
     *
     * El filtro por zona es opcional. Cuando la oleada tiene zona, se
     * tome solo lo que esta en esa zona: mezclar zonas en una vuelta
     * obligaria a atravesar el almacen entero.
     *
     * @return Collection<int, array{pedido_item_id: string, saldo_id: string, cantidad_base: string, ubicacion: Ubicacion}>
     */
    private function paradasCandidatas(?Zona $zona): Collection
    {
        $reservas = Reserva::query()
            ->where('estado', EstadoReserva::VIGENTE->value)
            ->where('expira_en', '>', Carbon::now())
            ->whereIn(
                'pedido_item_id',
                Pedido::query()
                    ->whereIn('estado', [
                        EstadoPedido::RESERVADO->value,
                        EstadoPedido::EN_PREPARACION->value,
                    ])
                    ->select('id')
            )
            ->with(['saldo.ubicacion', 'itemPedido.pedido'])
            ->get();

        return $reservas
            ->filter(function (Reserva $reserva) use ($zona): bool {
                $ubicacion = $reserva->saldo?->ubicacion;

                if ($ubicacion === null) {
                    return false;
                }

                if ($zona === null) {
                    return true;
                }

                return $ubicacion->zona_id === $zona->id;
            })
            ->sortBy(function (Reserva $reserva): array {
                $pedido = $reserva->itemPedido?->pedido;

                return [
                    // Primero la prioridad (1 es lo mas urgente), luego la
                    // fecha de entrega, luego el numero de pedido, para que
                    // el orden sea estable entre corridas.
                    (int) ($pedido?->prioridad ?? 99),
                    $pedido?->fecha_entrega?->format('Y-m-d') ?? '9999-12-31',
                    (string) $pedido?->numero,
                ];
            })
            ->map(fn (Reserva $reserva): array => [
                'pedido_item_id' => $reserva->pedido_item_id,
                'saldo_id' => $reserva->saldo_id,
                'cantidad_base' => $reserva->cantidad_base,
                'ubicacion' => $reserva->saldo->ubicacion,
            ])
            ->values();
    }

    /**
     * Ordena las paradas por ubicacion.
     *
     * @param  Collection<int, array{...}>  $paradas
     * @return Collection<int, array{...}>
     */
    private function ordenarPorUbicacion(Collection $paradas): Collection
    {
        $indiceDePasillo = $this->indiceDePasillo($paradas->pluck('ubicacion'));

        $ordenadas = $paradas->sort(function (array $a, array $b) use ($indiceDePasillo): int {
            $ubicacionA = $a['ubicacion'];
            $ubicacionB = $b['ubicacion'];

            // Regla 1: pasillo de corrido, de punta a punta del almacen.
            // Se compara en forma natural para que el pasillo 2 vaya antes
            // que el 10, y no al reves como pasaria con texto plano.
            $porPasillo = strnatcmp($ubicacionA->pasillo, $ubicacionB->pasillo);

            if ($porPasillo !== 0) {
                return $porPasillo;
            }

            // Regla 2: zigzag dentro del pasillo. El indice del pasillo
            // decide la direccion: pares de punta a punta, impares al reves.
            $porRack = strnatcmp((string) $ubicacionA->rack, (string) $ubicacionB->rack);

            if ($indiceDePasillo[$ubicacionA->pasillo] % 2 === 1) {
                $porRack = -$porRack;
            }

            if ($porRack !== 0) {
                return $porRack;
            }

            $porNivel = $ubicacionA->nivel <=> $ubicacionB->nivel;

            if ($porNivel !== 0) {
                return $porNivel;
            }

            return $ubicacionA->posicion <=> $ubicacionB->posicion;
        })->values();

        return $ordenadas;
    }

    /**
     * Numeracion de pasillos en orden de recorrido.
     *
     * El indice es lo que decide el zigzag: los pares van en un sentido y
     * los impares en el otro.
     *
     * @param  Collection<int, Ubicacion>  $ubicaciones
     * @return array<string, int>
     */
    private function indiceDePasillo(Collection $ubicaciones): array
    {
        return $ubicaciones
            ->pluck('pasillo')
            ->unique()
            ->sort()
            ->values()
            ->mapWithKeys(fn (string $pasillo, int $indice): array => [$pasillo => $indice])
            ->all();
    }

    /**
     * Marca una parada como recogida.
     *
     * Recoger NO es mover: la mercaderia sigue en el rack hasta que sale
     * del almacen. Por eso aca no hay movimiento de stock, solo la marca
     * de que el operario ya la llevo al area de despacho.
     */
    public function marcarRecogido(OleadaItem $parada, ?Usuario $usuario = null): OleadaItem
    {
        $parada->estado = OleadaItem::RECOGIDO;
        $parada->recogido_por = $usuario?->id;
        $parada->recogido_en = Carbon::now();
        $parada->save();

        return $parada;
    }

    /**
     * Ruta en texto plano, lista para imprimir y llevar al operario.
     *
     * @return list<string>
     */
    public function rutaImprimible(Oleada $oleada): array
    {
        $lineas = [];

        foreach ($oleada->items()->with(['saldo.ubicacion', 'itemPedido.articulo', 'itemPedido.pedido'])->get() as $parada) {
            $lineas[] = sprintf(
                '%3d. %s | %s | %s und. | pedido %s',
                $parada->secuencia,
                $parada->saldo?->ubicacion?->codigo ?? 'sin ubicacion',
                $parada->itemPedido?->articulo?->sku ?? '-',
                rtrim(rtrim((string) $parada->cantidad_base, '0'), '.') ?: '0',
                $parada->itemPedido?->pedido?->numero ?? '-',
            );
        }

        return $lineas;
    }
}