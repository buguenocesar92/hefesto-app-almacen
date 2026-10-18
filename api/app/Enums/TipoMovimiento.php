<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de movimiento de stock.
 *
 * El tipo dice DE DONDE VIENE la variacion, para que la investigacion de
 * una diferencia no tenga que adivinar. `traspaso_salida` y
 * `traspaso_entrada` son casos separados a proposito: un traspaso que solo
 * suma en el destino sin descontar el origen queda visible como un
 * `traspaso_entrada` sin su par.
 */
enum TipoMovimiento: string
{
    case INGRESO = 'ingreso';
    case SALIDA = 'salida';
    case TRASPASO_SALIDA = 'traspaso_salida';
    case TRASPASO_ENTRADA = 'traspaso_entrada';
    case AJUSTE_CONteo = 'ajuste_conteo';
    case AJUSTE_MANUAL = 'ajuste_manual';
    case CONSUMO_RESERVA = 'consumo_reserva';

    public function suma(): bool
    {
        return in_array($this, [
            self::INGRESO,
            self::TRASPASO_ENTRADA,
            self::AJUSTE_CONteo,
        ], true);
    }
}