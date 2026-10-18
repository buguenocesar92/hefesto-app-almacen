<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Aritmetica de cantidades.
 *
 * Las cantidades se guardan en PostgreSQL como `decimal(18,6)`: seis
 * decimales alcanzan para la fraction de un pallet que se maneje en un
 * almacen corriente, y evita el error de coma flotante al sumar miles de
 * movimientos.
 *
 * En PHP se comparan como texto con `bcmath` cuando la extension esta
 * disponible (el Dockerfile la instala), y con comparacion de punto
 * flotante cuando no. La diferencia de comportamiento esta anotada en
 * docs/10: con bcmath, comparar es exacto; sin bcmath, hay una tolerancia
 * de 1E-6.
 */
final class Cantidad
{
    /** Decimales con que se guardan las cantidades. */
    public const DECIMALES = 6;

    /** Tolerancia para comparar cuando no hay bcmath. */
    public const TOLERANCIA = 0.000001;

    /**
     * Deja la cantidad en la forma en que la guarda la base: texto con
     * punto decimal y como maximo seis decimales.
     */
    public static function normalizar(float|int|string $valor): string
    {
        return number_format((float) $valor, self::DECIMALES, '.', '');
    }

    public static function esPositivo(float|int|string $valor): bool
    {
        return self::comparar($valor, '0') > 0;
    }

    public static function esCero(float|int|string $valor): bool
    {
        return self::comparar($valor, '0') === 0;
    }

    /**
     * Compara dos cantidades: -1, 0 o 1 segun sea menor, igual o mayor.
     *
     * @throws InvalidArgumentException si el valor no es un numero.
     */
    public static function comparar(float|int|string $a, float|int|string $b): int
    {
        $a = (float) $a;
        $b = (float) $b;

        if (is_nan($a) || is_nan($b)) {
            throw new InvalidArgumentException('La cantidad a comparar no es un numero.');
        }

        if (extension_loaded('bcmath')) {
            return bccomp(self::normalizar($a), self::normalizar($b), self::DECIMALES);
        }

        if (abs($a - $b) < self::TOLERANCIA) {
            return 0;
        }

        return $a < $b ? -1 : 1;
    }

    public static function sumar(float|int|string $a, float|int|string $b): string
    {
        return self::normalizar((float) $a + (float) $b);
    }

    public static function restar(float|int|string $a, float|int|string $b): string
    {
        return self::normalizar((float) $a - (float) $b);
    }
}