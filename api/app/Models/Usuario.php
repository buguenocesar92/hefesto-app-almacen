<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RolOperador;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Persona que opera el almacen.
 *
 * La autenticacion la resuelve Authentik; esta clase guarda lo propio del
 * almacen: el rol con el que se autoriza cada accion.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property RolOperador $rol
 */
class Usuario extends Authenticatable
{
    use HasUuids;

    protected $table = 'usuarios';

    protected $fillable = [
        'tenant_id',
        'email',
        'nombre',
        'rol',
        'activo',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'rol' => RolOperador::class,
            'activo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function esJefeOAdmin(): bool
    {
        return in_array($this->rol, [RolOperador::JEFE_ALMACEN, RolOperador::ADMINISTRADOR], true);
    }
}