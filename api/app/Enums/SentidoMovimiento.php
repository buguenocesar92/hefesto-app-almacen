<?php

declare(strict_types=1);

namespace App\Enums;

/** Sentido de un motivo de movimiento. */
enum SentidoMovimiento: string
{
    case INGRESO = 'ingreso';
    case SALIDA = 'salida';
    case AJUSTE = 'ajuste';
}