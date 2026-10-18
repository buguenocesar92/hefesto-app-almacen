<?php

declare(strict_types=1);

namespace App\Enums;

/** Ciclo de vida de una linea de pedido. */
enum EstadoItemPedido: string
{
    case PENDIENTE = 'pendiente';
    case RESERVADO = 'reservado';
    case RECOGIDO = 'recogido';
    case DESPACHADO = 'despachado';
    case CANCELADO = 'cancelado';
}