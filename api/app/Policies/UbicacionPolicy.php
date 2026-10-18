<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Ubicacion;
use App\Models\Usuario;
use App\Models\Zona;

/**
 * Ubicaciones y zonas.
 *
 * Crear un rack es cambiar el mapa del almacen. Se decide una vez, con el
 * jefe de almacen, y despues el resto lo usa. Por eso la escritura queda
 * en administracion y la lectura es libre.
 */
class UbicacionPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Ubicacion $ubicacion): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }

    public function update(Usuario $usuario, Ubicacion $ubicacion): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }

    /**
     * Desactivar una ubicacion es delicado: si hay stock ahi, ese stock se
     * queda sin direccion. El servicio lo bloquea; aca se deja la
     * peticion pasar para que el error llegue con el mensaje del servicio.
     */
    public function desactivar(Usuario $usuario, Ubicacion $ubicacion): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }
}

/**
 * Zonas: agrupamiento de pasillos.
 *
 * @see UbicacionPolicy para el criterio.
 */
class ZonaPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }

    public function update(Usuario $usuario, Zona $zona): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }
}