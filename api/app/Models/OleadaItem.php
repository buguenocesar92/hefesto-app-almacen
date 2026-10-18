<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parada de la ruta de picking.
 *
 * Cada parada dice: de que rack se saca (`saldo_id`), cuanto, y en que
 * orden se pasa (`secuencia`). Como el rack ya viene resuelto desde la
 * reserva, el papel de picking se imprime y el operario no necesita
 * consultar nada mas.
 *
 * @property string $id
 * @property string $cantidad_base
 * @property int $secuencia
 * @property string $estado
 */
class OleadaItem extends Model
{
    use HasUuids;

    public const PENDIENTE = 'pendiente';

    public const RECOGIDO = 'recogido';

    protected $table = 'oleada_items';

    protected $fillable = [
        'tenant_id',
        'oleada_id',
        'pedido_item_id',
        'saldo_id',
        'cantidad_base',
        'secuencia',
        'estado',
        'recogido_por',
        'recogido_en',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_base' => 'decimal:6',
            'secuencia' => 'integer',
            'recogido_en' => 'datetime',
        ];
    }

    public function oleada(): BelongsTo
    {
        return $this->belongsTo(Oleada::class);
    }

    public function itemPedido(): BelongsTo
    {
        return $this->belongsTo(PedidoItem::class, 'pedido_item_id');
    }

    public function saldo(): BelongsTo
    {
        return $this->belongsTo(Saldo::class);
    }

    /**
     * Rack de la parada.
     *
     * No es una relacion directa porque `oleada_items` no guarda la
     * ubicacion: la guarda el saldo reservado. Por eso se llega por ahi y
     * no con un `belongsTo` mentiroso.
     */
    public function ubicacion(): ?Ubicacion
    {
        return $this->saldo?->ubicacion;
    }
}