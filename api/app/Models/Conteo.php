<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoConteo;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conteo ciclico de una zona.
 *
 * @property string $id
 * @property EstadoConteo $estado
 * @property string $nombre
 */
class Conteo extends Model
{
    use HasUuids;

    protected $table = 'conteos';

    protected $fillable = [
        'tenant_id',
        'zona_id',
        'nombre',
        'estado',
        'abierto_por',
        'cerrado_por',
        'cerrado_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoConteo::class,
            'abierto_en' => 'datetime',
            'cerrado_en' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ConteoItem::class);
    }

    public function zona(): BelongsTo
    {
        return $this->belongsTo(Zona::class);
    }

    public function abierto(): bool
    {
        return $this->estado === EstadoConteo::ABIERTO;
    }

    /** Diferencias ya calculadas. Sirve para mostrar el resumen del conteo. */
    public function diferencias(): HasMany
    {
        return $this->items()->whereNotNull('diferencia')->where('diferencia', '!=', 0);
    }
}