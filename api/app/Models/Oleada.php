<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoOleada;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Oleada de picking: varios pedidos en una sola vuelta de recoleccion.
 *
 * @property string $id
 * @property EstadoOleada $estado
 * @property string $nombre
 */
class Oleada extends Model
{
    use HasUuids;

    protected $table = 'oleadas';

    protected $fillable = [
        'tenant_id',
        'zona_id',
        'nombre',
        'fecha',
        'estado',
        'armada_por',
    ];

    protected function casts(): array
    {
        return [
            'estado' => EstadoOleada::class,
            'fecha' => 'date',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OleadaItem::class)->orderBy('secuencia');
    }

    public function zona(): BelongsTo
    {
        return $this->belongsTo(Zona::class);
    }

    /** Paradas por zona, para pintar la ruta como mapa. */
    public function resumenPorZona(): array
    {
        return $this->items()
            ->with(['saldo.ubicacion.zona'])
            ->get()
            ->groupBy(fn (OleadaItem $item): string => (string) ($item->saldo?->ubicacion?->zona?->codigo ?? 'SIN ZONA'))
            ->map(fn ($items): int => $items->count())
            ->all();
    }
}