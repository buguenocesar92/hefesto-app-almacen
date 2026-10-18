<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unidad de medida con su conversion a unidad base.
 *
 * `unidades_por_base` dice cuantas unidades base trae una unidad de esta
 * fila. "UND" vale 1, "CAJA-12" vale 12, "PALLET-48" vale 48.
 *
 * El factor vive aqui y NO en el articulo, a proposito: si cada articulo
 * guardara su propio factor, habria dos verdades para la misma caja y el
 * problema de confundir caja con unidad volveria, con mas pasos. Ver
 * docs/02-MODELO-DE-DATOS.md.
 *
 * @property string $id
 * @property string $codigo
 * @property string $unidades_por_base
 */
class UnidadMedida extends Model
{
    use HasUuids;

    protected $table = 'unidades_medida';

    protected $fillable = [
        'tenant_id',
        'codigo',
        'nombre',
        'unidades_por_base',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'unidades_por_base' => 'decimal:6',
            'activo' => 'boolean',
        ];
    }

    public function articulos(): HasMany
    {
        return $this->hasMany(Articulo::class, 'unidad_base_id');
    }

    public function esBase(): bool
    {
        return \App\Support\Cantidad::esCero(
            \App\Support\Cantidad::restar($this->unidades_por_base, '1')
        );
    }
}