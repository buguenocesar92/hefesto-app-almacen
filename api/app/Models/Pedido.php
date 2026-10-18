<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoPedido;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pedido por preparar.
 *
 * @property string $id
 * @property EstadoPedido $estado
 * @property string $numero
 * @property int $prioridad
 */
class Pedido extends Model
{
    use HasUuids;

    protected $table = 'pedidos';

    protected $fillable = [
        'tenant_id',
        'numero',
        'referencia_externa',
        'destinatario',
        'estado',
        'prioridad',
        'fecha_entrega',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoPedido::class,
            'prioridad' => 'integer',
            'fecha_entrega' => 'date',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PedidoItem::class);
    }

    /**
     * Las reservas no cuelgan del pedido sino de sus lineas: cada linea
     * puede tener varias reservas si la mercaderia se tomo de mas de un
     * rack. Se llega por `items.reservas`.
     */
    public function puedeReservarse(): bool
    {
        return in_array($this->estado, [EstadoPedido::PENDIENTE, EstadoPedido::RESERVADO], true);
    }
}