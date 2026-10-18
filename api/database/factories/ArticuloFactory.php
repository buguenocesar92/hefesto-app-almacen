<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Articulo;
use App\Models\Tenant;
use App\Models\UnidadMedida;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Articulo>
 */
class ArticuloFactory extends Factory
{
    protected $model = Articulo::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'sku' => Str::upper($this->faker->bothify('ART-####')),
            'nombre' => $this->faker->words(3, true),
            'descripcion' => null,
            'unidad_base_id' => UnidadMedida::factory(),
            'unidad_compra_id' => UnidadMedida::factory(),
            'unidad_venta_id' => UnidadMedida::factory(),
            'stock_minimo' => null,
            'activo' => true,
        ];
    }

    /**
     * Articulo con las tres unidades coherentes: base, compra en cajas y
     * venta en unidades.
     */
    public function completo(
        Tenant $tenant,
        UnidadMedida $base,
        UnidadMedida $compra,
        ?UnidadMedida $venta = null,
    ): static {
        return $this->state(fn (): array => [
            'tenant_id' => $tenant->id,
            'unidad_base_id' => $base->id,
            'unidad_compra_id' => $compra->id,
            'unidad_venta_id' => ($venta ?? $base)->id,
        ]);
    }

    /** Articulo con alerta de reposicion encendida. */
    public function conMinimo(string $stockMinimo): static
    {
        return $this->state(fn (): array => ['stock_minimo' => $stockMinimo]);
    }
}