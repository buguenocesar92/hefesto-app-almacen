<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ciclo de vida del conteo ciclico.
 *
 * `ABIERTO` es el estado en que el conteo congela la zona: los saldos de
 * sus ubicaciones quedan bloqueados a movimientos hasta que cierre.
 */
enum EstadoConteo: string
{
    case ABIERTO = 'abierto';
    case CERRADO = 'cerrado';
    case CANCELADO = 'cancelado';

    public function bloqueaMovimientos(): bool
    {
        return $this === self::ABIERTO;
    }
}