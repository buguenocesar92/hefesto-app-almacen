<?php

declare(strict_types=1);

namespace App\Enums;

/** Tipo de documento de recepcion o despacho. */
enum TipoDocumento: string
{
    case ENTRADA_COMPRA = 'entrada_compra';
    case SALIDA_VENTA = 'salida_venta';
    case TRASPASO = 'traspaso';

    /** Entras por `ubicacion_destino_id` o sales por `ubicacion_origen_id`. */
    public function esIngreso(): bool
    {
        return $this === self::ENTRADA_COMPRA;
    }
}