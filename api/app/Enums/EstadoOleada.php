<?php

declare(strict_types=1);

namespace App\Enums;

/** Ciclo de vida de una oleada de picking. */
enum EstadoOleada: string
{
    case ABIERTA = 'abierta';
    case EN_CURSO = 'en_curso';
    case COMPLETADA = 'completada';
}