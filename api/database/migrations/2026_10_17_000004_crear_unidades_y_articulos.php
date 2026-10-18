<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unidades de medida y articulos.
 *
 * DECISION DE MODELO QUE RESUELVE EL ERROR CLASICO DEL ALMACEN
 * -----------------------------------------------------------
 * Confundir caja con unidad no se resuelve con un campo de texto libre,
 * porque el texto libre deja que el sistema acepte "12" queriendo decir
 * cajas y que nadie sepa si son 12 unidades o 12 cajas. Aqui la unidad es
 * una fila de `unidades_medida` y la conversion es aritmetica:
 *
 *     cantidad_base = cantidad / unidades_por_base
 *
 * `unidades_por_base` dice cuantas unidades base trae una unidad de la
 * fila: la unidad "UND" vale 1, la caja "CAJA-12" vale 12, el pallet
 * "PALLET-48" vale 48.
 *
 * El factor vive en la UNIDAD, no en el articulo, a proposito: si cada
 * articulo guardara su propio factor, habria dos verdades para la misma
 * caja y volveriamos al problema original con mas pasos. Dos articulos
 * que se venden en cajas de 12 y de 24 usan dos unidades distintas
 * (CAJA-12 y CAJA-24), y cada una dice la verdad una sola vez.
 *
 * El articulo elige tres unidades de ese catalogo:
 *   - `unidad_base_id`: en que unidad se lleva TODO el stock. Es la que
 *     entiende el sistema y la unica en la que se guardan saldos.
 *   - `unidad_compra_id`: en que unidad llega al almacen.
 *   - `unidad_venta_id`: en que unidad se vende.
 *
 * El stock NUNCA se guarda en cajas ni en pallets: siempre en unidad base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unidades_medida', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('codigo', 24);
            $tabla->string('nombre', 80);

            // Cuantas unidades base trae una unidad de esta fila.
            // Es 1 exactamente en la unidad base y mayor que 1 en las
            // derivadas (caja, pallet). La restriction lo hace explicito.
            $tabla->decimal('unidades_por_base', 18, 6);

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo']);

            $tabla->check('unidades_por_base > 0');
        });

        Schema::create('articulos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('sku', 40);
            $tabla->string('nombre', 160);
            $tabla->text('descripcion')->nullable();

            $tabla->foreignUuid('unidad_base_id')->constrained('unidades_medida')->restrictOnDelete();
            $tabla->foreignUuid('unidad_compra_id')->constrained('unidades_medida')->restrictOnDelete();
            $tabla->foreignUuid('unidad_venta_id')->constrained('unidades_medida')->restrictOnDelete();

            // Solo de apoyo para la interfaz (repuesto minimo). No interviene
            // en los calculos de stock: es una pista, no una regla.
            $tabla->decimal('stock_minimo', 18, 6)->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'sku']);
            $tabla->index(['tenant_id', 'nombre']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articulos');
        Schema::dropIfExists('unidades_medida');
    }
};