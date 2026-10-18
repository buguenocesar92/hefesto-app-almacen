<?php

declare(strict_types=1);

use App\Enums\EstadoOleada;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Oleadas de picking.
 *
 * Una oleada agrupa varios pedidos en UNA vuelta de recoleccion. El
 * beneficio no es de sistema, es de horas de operario: agrupar permite
 * ordenar la ruta y no hacer el pasillo veinte veces.
 *
 * `oleada_items` es la ruta ya ordenada. `secuencia` es el orden de paso,
 * y `saldo_id` dice de que rack se saca cada linea. Como cada linea sabe
 * su ubicacion, la ruta se puede imprimir para el papel de picking y el
 * operario no necesita consultar nada mas.
 *
 * `secuencia` es UNICA dentro de la oleada: dos paradas con el mismo
 * numero serian dos paradas en el mismo punto, que es un error de
 * planificacion que la base no deja pasar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oleadas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            // Zona opcional: una oleada puede abarcar varias zonas si el
            // pedido es grande. Cuando viene zona, la ruta se ordena
            // dentro de ella.
            $tabla->foreignUuid('zona_id')->nullable()->constrained('zonas')->nullOnDelete();

            $tabla->string('nombre', 120);
            $tabla->date('fecha');
            $tabla->string('estado', 16)->default(EstadoOleada::ABIERTA->value);

            $tabla->foreignUuid('armada_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'estado']);
        });

        Schema::create('oleada_items', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $tabla->foreignUuid('oleada_id')->constrained('oleadas')->cascadeOnDelete();
            $tabla->foreignUuid('pedido_item_id')->constrained('pedido_items')->cascadeOnDelete();

            // De que rack se saca esta linea. Es lo que permite ordenar
            // la ruta sin volver a buscar la mercaderia.
            $tabla->foreignUuid('saldo_id')->constrained('saldos')->restrictOnDelete();

            $tabla->decimal('cantidad_base', 18, 6);

            // Orden de paso, ya ordenado por ubicacion.
            $tabla->unsignedSmallInteger('secuencia');

            $tabla->string('estado', 16)->default('pendiente');
            $tabla->foreignUuid('recogido_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->timestampTz('recogido_en')->nullable();

            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'oleada_id', 'secuencia']);
            $tabla->unique(['oleada_id', 'secuencia']);

            $tabla->check('cantidad_base > 0');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oleada_items');
        Schema::dropIfExists('oleadas');
    }
};