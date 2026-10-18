<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RolOperador;
use App\Models\Tenant;
use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Usuario>
 */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'nombre' => $this->faker->name(),
            'rol' => RolOperador::PREPARADOR,
            'activo' => true,
        ];
    }

    public function de(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->id]);
    }

    public function jefe(): static
    {
        return $this->state(fn (): array => ['rol' => RolOperador::JEFE_ALMACEN]);
    }

    public function administrador(): static
    {
        return $this->state(fn (): array => ['rol' => RolOperador::ADMINISTRADOR]);
    }

    public function auditor(): static
    {
        return $this->state(fn (): array => ['rol' => RolOperador::AUDITOR]);
    }

    public function inactivo(): static
    {
        return $this->state(fn (): array => ['activo' => false]);
    }
}