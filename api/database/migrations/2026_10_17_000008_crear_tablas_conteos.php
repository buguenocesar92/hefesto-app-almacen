<?php

declare(strict_types=1);

use App\Enums\EstadoConteo;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario fisico: conteo ciclico por zona.
 *
 * El problema que ataca este modulo es silencioso y caro: las diferencias
 * de inventario. Nadie las mira a diario y cuando se miran ya son grandes
 * y nadie sabe cuando quedaron asi. Por eso el conteo se hace por zona y
 * en dos pasos:
 *
 *   1. Al ABRIR el conteo se congela una foto de los saldos de la zona.
 *      Esa foto (`cantidad_sistema`) ya no cambia, aunque el stock siga
 *      moviendose por receipts y despachos durante las horas que el
 *      conteo tarda. La foto es la linea de comparacion.
 *   2. Mientras el conteo esta `abierto`, los movimientos sobre las
 *      ubicaciones de esa zona quedan BLOQUEADOS
 *      (`App\Services\Stock\RegistroDeMovimiento` lo rechaza). Si el
 *      conteo tomara una foto suelta y el almacen siguiera moviendo
 *      mercaderia, la diferencia apareceria sola y nadie podria decir si
 *      fue merma, error de conteo o movimiento real.
 *
 * Al cerrar, cada diferencia necesita justificacion: un motivo y una
 * explicacion escrita. La diferencia se corrige con un MOVIMIENTO, nunca
 * escribiendo sobre el saldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conteos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $tabla->foreignUuid('zona_id')->constrained('zonas')->restrictOnDelete();

            $tabla->string('nombre', 120);
            $tabla->string('estado', 16)->default(EstadoConteo::ABIERTO->value);

            $tabla->foreignUuid('abierto_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->timestampTz('abierto_en')->useCurrent();

            $tabla->foreignUuid('cerrado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->timestampTz('cerrado_en')->nullable();

            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'estado']);
        });

        Schema::create('conteo_items', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $tabla->foreignUuid('conteo_id')->constrained('conteos')->cascadeOnDelete();
            $tabla->foreignUuid('articulo_id')->constrained('articulos')->cascadeOnDelete();
            $tabla->foreignUuid('ubicacion_id')->constrained('ubicaciones')->cascadeOnDelete();

            // La foto congelada al abrir el conteo. Se conserva tal cual
            // aunque despues el ajuste cambie el saldo: es la evidencia de
            // contra que se comparo lo que el contador vio.
            $tabla->decimal('cantidad_sistema', 18, 6);

            // Lo que contó la persona, en unidad base.
            $tabla->decimal('cantidad_contada', 18, 6)->nullable();

            // `cantidad_contada - cantidad_sistema`, calculado al cerrar.
            $tabla->decimal('diferencia', 18, 6)->nullable();

            $tabla->foreignUuid('motivo_movimiento_id')->nullable()->constrained('motivos_movimiento')->restrictOnDelete();
            $tabla->text('justificacion')->nullable();

            // Movimiento de ajuste que se genero al cerrar. Queda anotado
            // para que se pueda seguir la diferencia hasta su origen.
            //
            // La llave foranea se agrega en la migracion 000009, que es la
            // que crea `movimientos_stock`: en este punto la tabla todavia
            // no existe y una restriccion hacia adelante haria fallar la
            // migracion.
            $tabla->uuid('movimiento_id')->nullable();

            $tabla->foreignUuid('contado_por')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->timestampTz('contado_en')->nullable();

            $tabla->timestampsTz();

            $tabla->unique(['conteo_id', 'ubicacion_id', 'articulo_id']);
            $tabla->index(['tenant_id', 'conteo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conteo_items');
        Schema::dropIfExists('conteos');
    }
};