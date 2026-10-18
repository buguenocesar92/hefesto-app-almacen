<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogos de administracion: zonas y motivos de movimiento.
 *
 * - La zona agrupa pasillos. Es la unidad de trabajo del conteo ciclico y
 *   el criterio para armar una oleada de picking.
 * - El motivo explica POR QUE se movio algo. Los motivos marcados con
 *   `exige_justificacion` obligan a escribir el porque antes de cerrar un
 *   conteo con diferencia, que es donde las diferencias se explican.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zonas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('codigo', 16);
            $tabla->string('nombre', 120);
            $tabla->text('descripcion')->nullable();

            // Rango de pasillos que cubre la zona. Se guarda como texto y
            // no como numero porque un pasillo puede llamarse "A-1" o "12B".
            $tabla->string('pasillo_desde', 16)->nullable();
            $tabla->string('pasillo_hasta', 16)->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo']);
        });

        Schema::create('motivos_movimiento', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('codigo', 24);
            $tabla->string('nombre', 120);

            // `ingreso`, `salida` o `ajuste`: define en que sentido mueve
            // el motivo y por eso se valida al crear el movimiento.
            $tabla->string('sentido', 16);

            $tabla->boolean('exige_justificacion')->default(false);
            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motivos_movimiento');
        Schema::dropIfExists('zonas');
    }
};