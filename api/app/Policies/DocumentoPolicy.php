<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\EstadoDocumento;
use App\Enums\TipoDocumento;
use App\Models\Documento;
use App\Models\Usuario;

/**
 * Documentos de recepcion y despacho.
 *
 * La separacion importante:
 *   - crear un borrador: cualquiera que opere
 *   - confirmar (mueve stock): cualquiera que opere
 *   - anular (deshace stock): jefe de almacen
 *
 * Confirmar y anular no son la misma cosa con distinta permisos: anular
 * reescribe la historia economica del almacen, y por eso queda arriba.
 */
class DocumentoPolicy
{
    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Documento $documento): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return true;
    }

    public function update(Usuario $usuario, Documento $documento): bool
    {
        return $documento->estado->esEditable();
    }

    /** Confirmar: mueve stock. Lo hace cualquiera que opere. */
    public function confirmar(Usuario $usuario, Documento $documento): bool
    {
        return $documento->estado === EstadoDocumento::BORRADOR;
    }

    /**
     * Anular: deshace stock. Solo el jefe de almacen.
     *
     * Un traspaso no se anula: se revierte con un traspaso de vuelta. La
     * razon esta en el servicio, no aca; aca se deja pasar la peticion y
     * que el servicio de la razon clara.
     */
    public function anular(Usuario $usuario, Documento $documento): bool
    {
        if ($documento->estado !== EstadoDocumento::CONFIRMADO) {
            return false;
        }

        return $usuario->rol->puedeAnularDocumentos();
    }

    /** Un traspaso se revierte con un traspaso de vuelta, no se anula. */
    public function revertirTraspaso(Usuario $usuario, Documento $documento): bool
    {
        return $documento->tipo === TipoDocumento::TRASPASO
            && $documento->estado === EstadoDocumento::CONFIRMADO
            && $usuario->rol->puedeAnularDocumentos();
    }
}