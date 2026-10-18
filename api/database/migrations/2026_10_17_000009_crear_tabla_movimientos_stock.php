<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Movimientos de stock: el libro que explica cada numero.
 *
 * Esta tabla es de SOLO AGREGADO. Un movimiento no se edita ni se borra,
 * ni siquiera desde la aplicacion: para corregir algo se escribe el
 * movimiento contrario. El gatillo de esta migracion lo hace cumplir en
 * la base, no en el codigo.
 *
 * `cantidad_base` va con signo: positiva suma (ingreso), negativa resta
 * (salida). Asi el saldo de una ubicacion es la suma de sus movimientos, y
 * `almacen:conciliar` puede recomputarlo y comparar (ver docs/08).
 *
 * `grupo_traspaso` es lo que hace que un traspaso entre dos ubicaciones
 * quede registrado como lo que es: DOS movimientos con el mismo grupo. El
 * error tipico es sumar al destino sin descontar del origen; con este
 * campo, esa mitad falta queda a la vista en la primera consulta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos_stock', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('tipo', 24);
            $tabla->foreignUuid('articulo_id')->constrained('articulos')->restrictOnDelete();
            $tabla->foreignUuid('ubicacion_id')->constrained('ubicaciones')->restrictOnDelete();

            // Con signo. Positiva entra, negativa sale.
            $tabla->decimal('cantidad_base', 18, 6);

            $tabla->foreignUuid('motivo_id')->constrained('motivos_movimiento')->restrictOnDelete();

            // De donde viene el movimiento: un documento o un conteo. Ambos
            // opcionales porque un ajuste manual tambien es un movimiento
            // legitimo, siempre con motivo.
            $tabla->foreignUuid('documento_id')->nullable()->constrained('documentos')->nullOnDelete();
            $tabla->foreignUuid('conteo_id')->nullable()->constrained('conteos')->nullOnDelete();

            // Pareja del traspaso: los dos movimientos comparten valor.
            $tabla->uuid('grupo_traspaso')->nullable();

            $tabla->string('referencia', 64)->nullable();
            $tabla->text('notas')->nullable();

            $tabla->foreignUuid('usuario_id')->nullable()->constrained('usuarios')->nullOnDelete();

            $tabla->timestampTz('creado_en')->useCurrent();

            $tabla->index(['tenant_id', 'articulo_id', 'ubicacion_id']);
            $tabla->index(['tenant_id', 'grupo_traspaso']);
            $tabla->index(['tenant_id', 'creado_en']);

            $tabla->check('cantidad_base <> 0');
        });

        // Llave foranea que quedo pendiente en la migracion de conteos:
        // `conteo_items.movimiento_id` apunta al ajuste que se genero al
        // cerrar el conteo.
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.conteo_items
                ADD CONSTRAINT conteo_items_movimiento_id_foreign
                FOREIGN KEY (movimiento_id)
                REFERENCES public.movimientos_stock (id)
                ON DELETE SET NULL;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.almacen_movimiento_inmutable() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION USING
                    ERRCODE = 'insufficient_privilege',
                    MESSAGE = 'Los movimientos de stock no se editan ni se borran. Para corregir el stock se registra un movimiento nuevo.',
                    HINT = 'Un movimiento es la evidencia. Si estaba equivocado, escribalo al reves y deje que los dos se cuenten en la historia.';
            END
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER gatillo_movimientos_inmutables
            BEFORE UPDATE OR DELETE ON public.movimientos_stock
            FOR EACH ROW
            EXECUTE FUNCTION public.almacen_movimiento_inmutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS gatillo_movimientos_inmutables ON public.movimientos_stock');
        DB::unprepared('DROP FUNCTION IF EXISTS public.almacen_movimiento_inmutable()');

        // La llave foranea desde `conteo_items` se va primero: sin esto,
        // PostgreSQL no deja borrar la tabla.
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.conteo_items
                DROP CONSTRAINT IF EXISTS conteo_items_movimiento_id_foreign;
        SQL);

        Schema::dropIfExists('movimientos_stock');
    }
};