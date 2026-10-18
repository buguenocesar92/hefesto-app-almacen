<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empresas usuarias del sistema.
 *
 * Esta es la UNICA tabla sin `tenant_id` y sin RLS, a proposito: se consulta
 * durante el acceso, cuando todavia no hay sesion y por lo tanto no hay
 * `app.tenant_id` que poner. Se compensa con la vista `vw_empresas_publicas`
 * (migracion 000013), que expone solo tres columnas y solo de empresas
 * activas. Ver docs/03-MULTITENANCY-Y-ROLES.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->string('slug', 64)->unique();
            $tabla->string('nombre', 160);
            $tabla->string('razon_social', 160)->nullable();
            $tabla->boolean('activo')->default(true);

            $tabla->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};