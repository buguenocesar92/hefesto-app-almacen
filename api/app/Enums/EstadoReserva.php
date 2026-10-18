<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ciclo de vida de una reserva.
 *
 * `VENCIDA` no es un estado que se ponga a mano: lo pone el trabajo que
 * libera reservas, cuando el plazo paso. Es importante que quede
 * registrado como vencido y no como liberado a mano, porque la pregunta
 * que hace el jefe de almacen despues es "por que este pedido perdio la
 * mercaderia", y la respuesta esta en esta columna.
 */
enum EstadoReserva: string
{
    case VIGENTE = 'vigente';
    case CONSUMIDA = 'consumida';
    case LIBERADA = 'liberada';
    case VENCIDA = 'vencida';

    public function sigueTomandoStock(): bool
    {
        return $this === self::VIGENTE;
    }
}