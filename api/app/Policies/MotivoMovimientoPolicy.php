<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MotivoMovimiento;
use App\Models\Usuario;

/**
 * Motivos de movimiento.
 *
 * Crear un motivo es una decision con consecuencia: el motivo aparece en
 * todos los movimientos de ese tipo, y es lo que despues permite
 * investigar una diferencia. Por eso solo administracion.
 */
class MotivoMovimientoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function alta(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }

    public function actualizar(Usuario $usuario, MotivoMovimiento $motivo): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }
}