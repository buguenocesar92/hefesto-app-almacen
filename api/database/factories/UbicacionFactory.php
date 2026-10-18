<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Ubicacion;
use App\Models\Zona;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ubicacion>
 */
class UbicacionFactory extends Factory
{
    protected $model = Ubicacion::class;

    public function definition(): array
    {
        $pasillo = (string) $this->faker->numberBetween(1, 6);
        $rack = str_pad((string) $this->faker->numberBetween(1, 20), 2, '0', STR_PAD_LEFT);
        $nivel = $this->faker->numberBetween(1, 4);
        $posicion = str_pad((string) $this->faker->numberBetween(1, 9), 2, '0', STR_PAD_LEFT);

        $codigo = sprintf('%s-%s-%s-%s', $pasillo, $rack, $nivel, $posicion);

        return [
            'tenant_id' => Tenant::factory(),
            'zona_id' => null,
            'codigo' => $codigo,
            'pasillo' => $pasillo,
            'rack' => $rack,
            'nivel' => $nivel,
            'posicion' => (int) $posicion,
            'codigo_barras' => 'ALM-' . $codigo . '-' . $this->faker->unique()->numerify('###'),
            'descripcion' => null,
            'activo' => true,
        ];
    }

    public function de(Tenant $tenant, ?Zona $zona = null): static
    {
        return $this->state(fn (): array => [
            'tenant_id' => $tenant->id,
            'zona_id' => $zona?->id,
        ]);
    }

    /** Ubicacion en una direccion exacta, para las pruebas de ruta. */
    public function en(string $pasillo, string $rack, int $nivel, int $posicion): static
    {
        $codigo = sprintf('%s-%s-%02d-%02d', $pasillo, $rack, $nivel, $posicion);

        return $this->state(fn (): array => [
            'codigo' => $codigo,
            'pasillo' => $pasillo,
            'rack' => $rack,
            'nivel' => $nivel,
            'posicion' => $posicion,
            'codigo_barras' => 'ALM-' . $codigo . '-' . $this->faker->unique()->numerify('###'),
        ]);
    }
}