<?php

declare(strict_types=1);

namespace App\Enums;

/** Ciclo de vida de un documento. */
enum EstadoDocumento: string
{
    case BORRADOR = 'borrador';
    case CONFIRMADO = 'confirmado';
    case ANULADO = 'anulado';

    public function esEditable(): bool
    {
        return $this === self::BORRADOR;
    }
}