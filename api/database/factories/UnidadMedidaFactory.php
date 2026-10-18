<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UnidadMedida>
 */
class UnidadMedidaFactory extends Factory
{
    protected $model = UnidadMedida::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'codigo' => Str::upper(Str::random(5)),
            'nombre' => $this->faker->word(),
            'unidades_por_base' => 1,
            'activo' => true,
        ];
    }

    public function de(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->id]);
    }

    /** La unidad base: piezas. */
    public function base(): static
    {
        return $this->state(fn (): array => [
            'codigo' => 'UND',
            'nombre' => 'Unidad',
            'unidades_por_base' => 1,
        ]);
    }

    /** Caja de N piezas. */
    public function caja(int $piezas, string $codigo = 'CAJA-12'): static
    {
        return $this->state(fn (): array => [
            'codigo' => $codigo,
            'nombre' => 'Caja de ' . $piezas,
            'unidades_por_base' => $piezas,
        ]);
    }
}