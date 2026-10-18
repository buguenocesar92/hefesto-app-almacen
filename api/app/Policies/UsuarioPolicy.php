<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Usuario;

/**
 * Personas que operan el almacen.
 *
 * El rol es lo que autoriza cada accion. Darlo o quitarlo es una decision
 * de administracion, y por eso queda registrada en la auditoria: la
 * pregunta "quien podia hacer esto" tiene que tener respuesta.
 *
 * No hay una policy separada para `Usuario` sobre si mismo: no se permite
 * que una persona cambie su propio rol desde la interfaz.
 */
class UsuarioPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }

    public function alta(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }

    public function cambiarRol(Usuario $usuario, Usuario $objetivo): bool
    {
        // Nadie se sube su propio rol: la separacion de poderes tambien
        // aplica adentro de la aplicacion.
        if ($usuario->id === $objetivo->id) {
            return false;
        }

        return $usuario->rol->puedeAdministrarCatalogos();
    }
}