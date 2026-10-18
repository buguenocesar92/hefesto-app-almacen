<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EstadoPedido;
use App\Models\Pedido;
use App\Models\Usuario;

/**
 * Pedidos: reserva, liberacion y despacho.
 *
 * Reservar compromete stock disponible y por lo tanto cualquiera que opere
 * puede hacerlo: es parte del dia a dia.
 *
 * Liberar una reserva o cancelar un pedido con mercaderia ya reservada es
 * decision del jefe: la reserva la hizo el almacen y liberarla restituye
 * la promesa hecha al cliente.
 */
class PedidoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Pedido $pedido): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return true;
    }

    public function update(Usuario $usuario, Pedido $pedido): bool
    {
        return in_array($pedido->estado, [EstadoPedido::PENDIENTE, EstadoPedido::RESERVADO], true);
    }

    public function reservar(Usuario $usuario, Pedido $pedido): bool
    {
        return $pedido->puedeReservarse();
    }

    public function liberar(Usuario $usuario, Pedido $pedido): bool
    {
        if ($pedido->estado === EstadoPedido::DESPACHADO) {
            return false;
        }

        return $usuario->rol->puedeAnularDocumentos();
    }

    /** Despachar: la mercaderia sale del almacen. Es el punto sin vuelta. */
    public function despachar(Usuario $usuario, Pedido $pedido): bool
    {
        return in_array($pedido->estado, [
            EstadoPedido::RESERVADO,
            EstadoPedido::EN_PREPARACION,
            EstadoPedido::PREPARADO,
        ], true);
    }
}