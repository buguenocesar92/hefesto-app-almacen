<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoReserva;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reserva: mercaderia comprometida a una linea de pedido, en un rack.
 *
 * `expira_en` es el campo que evita el error caro del almacen. Un pedido
 * que nadie confirma dejaria la mercaderia reservada para siempre y el
 * almacen creeria que tiene disponible lo que ya salio. Cuando el plazo
 * pasa, `almacen:liberar-reservas-vencidas` devuelve la unidad y deja la
 * reserva en estado `vencida`, que es distinto de `liberada` a mano: asi
 * se puederesponder "por que este pedido perdio la mercaderia".
 *
 * @property string $id
 * @property string $cantidad_base
 * @property EstadoReserva $estado
 * @property \Illuminate\Support\Carbon $expira_en
 */
class Reserva extends Model
{
    use HasUuids;

    protected $table = 'reservas';

    protected $fillable = [
        'tenant_id',
        'pedido_item_id',
        'saldo_id',
        'cantidad_base',
        'estado',
        'expira_en',
        'resuelto_en',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_base' => 'decimal:6',
            'estado' => EstadoReserva::class,
            'expira_en' => 'datetime',
            'resuelto_en' => 'datetime',
        ];
    }

    public function itemPedido(): BelongsTo
    {
        return $this->belongsTo(PedidoItem::class, 'pedido_item_id');
    }

    public function saldo(): BelongsTo
    {
        return $this->belongsTo(Saldo::class);
    }

    public function expiro(): bool
    {
        return $this->estado === EstadoReserva::VIGENTE
            && $this->expira_en !== null
            && $this->expira_en->isPast();
    }
}