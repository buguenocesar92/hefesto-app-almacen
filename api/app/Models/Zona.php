<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Zona del almacen: el agrupamiento de pasillos.
 *
 * Es la unidad de trabajo del conteo ciclico (se cuenta una zona, no todo
 * el almacen de una vez) y el criterio opcional para agrupar una oleada
 * de picking.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $codigo
 * @property string $nombre
 */
class Zona extends Model
{
    use HasUuids;

    protected $table = 'zonas';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'descripcion',
        'pasillo_desde',
        'pasillo_hasta',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function ubicaciones(): HasMany
    {
        return $this->hasMany(Ubicacion::class);
    }

    public function conteos(): HasMany
    {
        return $this->hasMany(Conteo::class);
    }
}