<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Conteo;
use App\Models\Usuario;

/**
 * Conteos ciclicos.
 *
 * Abrir y contar es trabajo de piso: cualquiera que opere.
 * Cerrar con diferencia cambia el stock, asi que queda en el jefe de
 * almacen. Es la misma razon por la que anular un documento esta arriba:
 * las dos acciones escriben en la historia del stock.
 */
class ConteoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Conteo $conteo): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return true;
    }

    /** Registrar lo que se conto en una parada. */
    public function registrar(Usuario $usuario, Conteo $conteo): bool
    {
        return $conteo->abierto();
    }

    /** Cerrar el conteo: genera movimientos de ajuste. */
    public function cerrar(Usuario $usuario, Conteo $conteo): bool
    {
        if (! $conteo->abierto()) {
            return false;
        }

        return $usuario->rol->puedeCerrarConteos();
    }

    /** Cancelar un conteo a medias y devolver la zona al movimiento. */
    public function cancelar(Usuario $usuario, Conteo $conteo): bool
    {
        if (! $conteo->abierto()) {
            return false;
        }

        return $usuario->rol->puedeCerrarConteos();
    }
}