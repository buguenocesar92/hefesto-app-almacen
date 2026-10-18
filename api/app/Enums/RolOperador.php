<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Niveles de autorizacion.
 *
 * La separacion importa mas de lo que parece: `preparador` puede mover
 * mercaderia pero no puede anular un documento ni cerrar un conteo con
 * diferencia. Anular y cerrar son las dos acciones que cambian la
 * historia del stock, asi que quedan en el jefe de almacen.
 *
 * Ver docs/03-MULTITENANCY-Y-ROLES.md.
 */
enum RolOperador: string
{
    /** Ve todo y no cambia nada. Sirve para auditar. */
    case AUDITOR = 'auditor';

    /** Registra ingresos, prepara pedidos, cuenta. No anula ni cierra. */
    case PREPARADOR = 'preparador';

    /** Ademas de lo anterior: anula documentos, cierra conteos, ajusta. */
    case JEFE_ALMACEN = 'jefe_almacen';

    /** Configura zonas, motivos, unidades y usuarios. */
    case ADMINISTRADOR = 'administrador';

    public function puedeAnularDocumentos(): bool
    {
        return in_array($this, [self::JEFE_ALMACEN, self::ADMINISTRADOR], true);
    }

    public function puedeCerrarConteos(): bool
    {
        return in_array($this, [self::JEFE_ALMACEN, self::ADMINISTRADOR], true);
    }

    public function puedeAdministrarCatalogos(): bool
    {
        return $this === self::ADMINISTRADOR;
    }
}