<?php

declare(strict_types=1);

use App\Enums\EstadoDocumento;
use App\Enums\TipoDocumento;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos de recepcion y despacho.
 *
 * Un documento es la intencion del usuario ("llego esta compra", "sale
 * esta venta", "traspaso de A a B"). Todavia NO mueve stock: el stock se
 * mueve cuando el documento se CONFIRMA, y se mueve por movimientos, no
 * editando saldos.
 *
 * Estados:
 *   borrador    se puede editar y borrar
 *   confirmado  genero movimientos; ya no se edita
 *   anulado     se revirtio; genero movimientos inversos
 *
 * Anular un documento confirmado NO borra movimientos ni descuenta el
 * stock "a mano": crea movimientos de sentido contrario, para que la
 * historia siga cuadrando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('tipo', 24)->default(TipoDocumento::ENTRADA_COMPRA->value);
            $tabla->string('numero', 32);

            $tabla->string('estado', 16)->default(EstadoDocumento::BORRADOR->value);
            $tabla->date('fecha_documento');
            $tabla->string('referencia_externa', 64)->nullable();

            $tabla->text('notas')->nullable();

            $tabla->foreignUuid('usuario_creador_id')->nullable()->constrained('usuarios')->nullOnDelete();
            $tabla->timestampTz('confirmado_en')->nullable();
            $tabla->timestampTz('anulado_en')->nullable();

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'numero']);
            $tabla->index(['tenant_id', 'estado']);
        });

        Schema::create('documento_items', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $tabla->foreignUuid('documento_id')->constrained('documentos')->cascadeOnDelete();
            $tabla->foreignUuid('articulo_id')->constrained('articulos')->restrictOnDelete();

            // Segun el tipo de documento se usa uno u otro:
            //   entrada_compra -> solo destino
            //   salida_venta   -> solo origen
            //   traspaso       -> ambos, y distintos
            // El servicio `ServicioDeDocumentos` lo valida antes de
            // confirmar; aqui no se puede expresar con una restriccion
            // simple de PostgreSQL.
            $tabla->foreignUuid('ubicacion_origen_id')->nullable()->constrained('ubicaciones')->restrictOnDelete();
            $tabla->foreignUuid('ubicacion_destino_id')->nullable()->constrained('ubicaciones')->restrictOnDelete();

            // Cantidad tal como la escribio el usuario, en su unidad, y la
            // misma cantidad expresada en unidad base. Se guardan las dos
            // para poder mostrar el documento como fue escrito y calcular
            // el movimiento sin volver a convertir.
            $tabla->decimal('cantidad', 18, 6);
            $tabla->foreignUuid('unidad_id')->constrained('unidades_medida')->restrictOnDelete();
            $tabla->decimal('cantidad_base', 18, 6);

            $tabla->unsignedSmallInteger('orden')->default(1);
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'documento_id']);
            $tabla->check('cantidad > 0');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documento_items');
        Schema::dropIfExists('documentos');
    }
};