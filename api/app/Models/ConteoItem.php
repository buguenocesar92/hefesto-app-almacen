<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parada del conteo: un articulo en una ubicacion.
 *
 * `cantidad_sistema` es la foto congelada al abrir el conteo. `cantidad_contada`
 * es lo que vio la persona. `diferencia` se calcula al cerrar y es la unica
 * forma en que el stock cambia: por un movimiento de ajuste, nunca
 * escribiendo sobre el saldo.
 *
 * @property string $id
 * @property string $cantidad_sistema
 * @property string|null $cantidad_contada
 * @property string|null $diferencia
 */
class ConteoItem extends Model
{
    use HasUuids;

    protected $table = 'conteo_items';

    protected $fillable = [
        'tenant_id',
        'conteo_id',
        'articulo_id',
        'ubicacion_id',
        'cantidad_sistema',
        'cantidad_contada',
        'diferencia',
        'motivo_movimiento_id',
        'justificacion',
        'movimiento_id',
        'contado_por',
        'contado_en',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_sistema' => 'decimal:6',
            'cantidad_contada' => 'decimal:6',
            'diferencia' => 'decimal:6',
            'contado_en' => 'datetime',
        ];
    }

    public function conteo(): BelongsTo
    {
        return $this->belongsTo(Conteo::class);
    }

    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class);
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class);
    }

    public function motivo(): BelongsTo
    {
        return $this->belongsTo(MotivoMovimiento::class, 'motivo_movimiento_id');
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(MovimientoStock::class, 'movimiento_id');
    }

    public function tieneDiferencia(): bool
    {
        return $this->diferencia !== null
            && ! \App\Support\Cantidad::esCero($this->diferencia);
    }

    /** Fila todavia sin la cantidad que conto la persona. */
    public function contado(): bool
    {
        return $this->cantidad_contada !== null;
    }
}