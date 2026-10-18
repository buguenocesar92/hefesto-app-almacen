<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Articulo;
use App\Models\Usuario;

/**
 * Articulos: el catalogo maestro del almacen.
 *
 * Un operador puede ver y consultar stock. Crear o desactivar un articulo
 * es decision de administracion: desactivar mal un articulo que tiene
 * pedidos abiertos deja pedidos sin surtir.
 */
class ArticuloPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Articulo $articulo): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }

    public function update(Usuario $usuario, Articulo $articulo): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }

    /**
     * Desactivar un articulo es mas delicado que editarlo: corta la
     * recepcion y la venta sin avisar.
     */
    public function desactivar(Usuario $usuario, Articulo $articulo): bool
    {
        return $usuario->rol->puedeAdministrarCatalogos();
    }

    /** Ver el stock de un articulo es parte del dia a dia, para todos. */
    public function verStock(Usuario $usuario, Articulo $articulo): bool
    {
        return true;
    }
}