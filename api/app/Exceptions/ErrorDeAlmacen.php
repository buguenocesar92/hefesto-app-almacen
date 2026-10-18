<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Error de regla de almacen.
 *
 * Son las situaciones en que la operacion es valida en forma pero invalida
 * en fondo: no hay stock disponible, la ubicacion esta congelada por un
 * conteo abierto, la reserva ya expiro, el saldo quedaria negativo.
 *
 * Se distinguen de los errores de validacion (422 de Laravel) y de los
 * de autorizacion (404) porque la interfaz necesita reaccionar a cada uno
 * de forma distinta. Todas llevan un `codigo` estable.
 */
class ErrorDeAlmacen extends RuntimeException
{
    /**
     * @param  string  $codigo  Identificador estable para la interfaz.
     *                          Ver la lista en docs/09-API.md.
     */
    public function __construct(
        string $mensaje,
        private readonly string $codigo = 'regla_de_almacen',
    ) {
        parent::__construct($mensaje);
    }

    public function codigo(): string
    {
        return $this->codigo;
    }
}