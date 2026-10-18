<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoItemPedido;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Linea de un pedido.
 *
 * @property string $id
 * @property string $cantidad
 * @property string $cantidad_base
 * @property EstadoItemPedido $estado
 */
class PedidoItem extends Model
{
    use HasUuids;

    protected $table = 'pedido_items';

    protected $fillable = [
        'tenant_id',
        'pedido_id',
        'articulo_id',
        'cantidad',
        'unidad_id',
        'cantidad_base',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:6',
            'cantidad_base' => 'decimal:6',
            'estado' => EstadoItemPedido::class,
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class);
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_id');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class);
    }
}