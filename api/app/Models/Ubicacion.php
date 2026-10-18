<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Direccion fisica dentro del almacen.
 *
 * Este es el corazon del producto: el sistema tiene que poder responder
 * "donde esta" y no solo "cuanto hay". Por eso la direccion tiene partes
 * consultables (pasillo, rack, nivel, posicion) y un codigo de barras que
 * se imprime en la etiqueta del rack.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $codigo
 * @property string $pasillo
 * @property string $rack
 * @property int $nivel
 * @property int $posicion
 * @property string $codigo_barras
 */
class Ubicacion extends Model
{
    use HasUuids;

    protected $table = 'ubicaciones';

    protected $fillable = [
        'tenant_id',
        'zona_id',
        'codigo',
        'pasillo',
        'rack',
        'nivel',
        'posicion',
        'codigo_barras',
        'descripcion',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'nivel' => 'integer',
            'posicion' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function zona(): BelongsTo
    {
        return $this->belongsTo(Zona::class);
    }

    public function saldos(): HasMany
    {
        return $this->hasMany(Saldo::class);
    }

    /** Texto corto para leer en voz alta o para la etiqueta. */
    public function etiqueta(): string
    {
        return sprintf(
            'Pasillo %s, rack %s, nivel %d, posicion %d',
            $this->pasillo,
            $this->rack,
            $this->nivel,
            $this->posicion,
        );
    }
}