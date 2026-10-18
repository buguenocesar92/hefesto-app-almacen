<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ubicaciones fisicas: donde esta la mercaderia de verdad.
 *
 * El problema que resuelve este modulo es el enunciado del producto: saber
 * CUANTO stock hay es facil, saber DONDE esta es lo que cuesta media hora
 * por cliente. Por eso la ubicacion es una direccion con partes
 * (pasillo, rack, nivel, posicion) y no un texto.
 *
 * Un registro de `ubicaciones` es UNA direccion fisica, que puede contener
 * varios articulos distintos: el rack A-03 nivel 2 posicion 1 tiene eje y
 * tiene manual de mantenimiento. El stock de cada articulo en esa direccion
 * vive en `saldos` (migracion siguiente).
 *
 * `codigo_barras` es la etiqueta fisica que se imprime y se escanea. Es
 * unica dentro de la empresa: dos empresas pueden tener las dos la
 * ubicacion "A-03-02-01" sin que esten compitiendo por el mismo sticker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ubicaciones', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $tabla->foreignUuid('zona_id')->nullable()->constrained('zonas')->nullOnDelete();

            // Direccion legible, tal como se escribe en la etiqueta:
            // pasillo-rack-nivel-posicion.
            $tabla->string('codigo', 40);

            $tabla->string('pasillo', 16);
            $tabla->string('rack', 16);
            $tabla->unsignedSmallInteger('nivel');
            $tabla->unsignedSmallInteger('posicion');

            $tabla->string('codigo_barras', 64);
            $tabla->string('descripcion', 160)->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            // El codigo legible y el codigo de barras son unicos POR
            // EMPRESA, no globalmente: la numeracion de pasillos es local
            // de cada bodega.
            $tabla->unique(['tenant_id', 'codigo']);
            $tabla->unique(['tenant_id', 'codigo_barras']);

            // Sirve para ordenar la ruta de picking sin recorrer pasillo y
            // rack a mano en cada consulta.
            $tabla->index(['tenant_id', 'pasillo', 'rack', 'nivel', 'posicion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ubicaciones');
    }
};