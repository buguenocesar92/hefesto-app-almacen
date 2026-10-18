<?php

declare(strict_types=1);

namespace App\Enums;

/** Ciclo de vida de un pedido. */
enum EstadoPedido: string
{
    case PENDIENTE = 'pendiente';
    case RESERVADO = 'reservado';
    case EN_PREPARACION = 'en_preparacion';
    case PREPARADO = 'preparado';
    case DESPACHADO = 'despachado';
    case CANCELADO = 'cancelado';
}