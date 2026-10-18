<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linea de un documento.
 *
 * Guarda la cantidad TAL COMO la escribio el usuario, en su unidad, y la
 * misma cantidad en unidad base. Se guardan las dos para poder mostrar el
 * documento como fue escrito ("3 cajas de eje") y para calcular el
 * movimiento sin volver a convertir.
 *
 * Segun el tipo de documento se usa una ubicacion u otra:
 *   entrada_compra -> `ubicacion_destino_id`
 *   salida_venta   -> `ubicacion_origen_id`
 *   traspaso       -> las dos, y distintas entre si
 *
 * @property string $id
 * @property string $cantidad
 * @property string $cantidad_base
 */
class DocumentoItem extends Model
{
    use HasUuids;

    protected $table = 'documento_items';

    protected $fillable = [
        'tenant_id',
        'documento_id',
        'articulo_id',
        'ubicacion_origen_id',
        'ubicacion_destino_id',
        'cantidad',
        'unidad_id',
        'cantidad_base',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:6',
            'cantidad_base' => 'decimal:6',
            'orden' => 'integer',
        ];
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class);
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_id');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class, 'ubicacion_origen_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class, 'ubicacion_destino_id');
    }
}