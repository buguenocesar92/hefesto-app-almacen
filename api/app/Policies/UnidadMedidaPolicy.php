<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\UnidadMedida;
use App\Models\Usuario;

/**
 * Unidades de medida.
 *
 * El factor de conversion de una unidad cambia el significado de todos los
 * numeros de stock del almacen. No es un dato de la ficha: es el criterio
 * con que se cuenta. Por eso se escribe una vez, con calma, y solo por
 * administracion.
 */
class UnidadMedidaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function alta(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }

    /**
     * Cambiar el factor con el almacen ya en marcha es operacion
     * delicada: obliga a revisar los saldos ya contados. Por eso, aun
     * siendo administracion, se pide una nota que explique por que.
     */
    public function cambiarFactor(Usuario $usuario, UnidadMedida $unidad): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }
}