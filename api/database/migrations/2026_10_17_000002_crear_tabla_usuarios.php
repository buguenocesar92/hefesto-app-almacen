<?php

declare(strict_types=1);

use App\Models\RolOperador;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personas que operan el almacen.
 *
 * La identidad (contrasena, segundo factor, recuperacion) la resuelve
 * Authentik. Aqui solo se guarda lo propio del almacen: quien es y que
 * puede hacer. El `email` es la llave con que se cruza con Authentik.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();

            // `restrictOnDelete`: borrar una empresa con stock, pedidos o
            // conteos encima no puede ser un borrado en cascada silencioso.
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('email', 160);
            $tabla->string('nombre', 160);
            $tabla->string('rol', 32)->default(RolOperador::OPERADOR->value);
            $tabla->boolean('activo')->default(true);

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'email']);
            $tabla->index(['tenant_id', 'rol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};