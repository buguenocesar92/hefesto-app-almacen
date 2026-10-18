<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Empresa usuaria del almacen.
 *
 * Es la unica tabla sin `tenant_id` y sin RLS: se lee en el acceso, antes
 * de que exista sesion. Ver docs/03.
 *
 * @property string $id
 * @property string $slug
 * @property string $nombre
 * @property bool $activo
 */
class Tenant extends Model
{
    use HasUuids;

    protected $table = 'tenants';

    protected $fillable = [
        'slug',
        'nombre',
        'razon_social',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function usuarios(): BelongsTo
    {
        return $this->hasMany(Usuario::class);
    }

    public function articulos(): BelongsTo
    {
        return $this->hasMany(Articulo::class);
    }
}