<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Saldos: las TRES cifras del almacen, separadas.
 *
 *     cantidad_fisica    lo que hay en el rack
 *     cantidad_reservada lo que ya esta comprometido a un pedido
 *     disponible         lo que se puede vender, DERIVADO
 *
 * `disponible` NO es una columna. Es `fisica - reservada`, calculado en el
 * modelo. Si fuera una columna, alguien la desactualizaria y el almacen
 * prometeria mercaderia que ya salio. Un solo campo de stock es un error
 * de diseno, no una simplificacion.
 *
 * EL STOCK NO SE EDITA: SE MUEVE
 * ------------------------------
 * Ni la aplicacion ni un `psql` pueden cambiar estas dos columnas
 * libremente. Un gatillo de PostgreSQL lo impide salvo que la transaccion
 * haya puesto `app.movimiento_stock = '1'`, que solo
 * `App\Services\Stock\RegistroDeMovimiento` pone. Es el mismo criterio que
 * la RLS: que la ultima linea sea la base de datos, no el codigo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saldos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $tabla->foreignUuid('articulo_id')->constrained('articulos')->cascadeOnDelete();
            $tabla->foreignUuid('ubicacion_id')->constrained('ubicaciones')->cascadeOnDelete();

            $tabla->decimal('cantidad_fisica', 18, 6)->default(0);
            $tabla->decimal('cantidad_reservada', 18, 6)->default(0);

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'articulo_id', 'ubicacion_id']);
            $tabla->index(['tenant_id', 'articulo_id']);

            // No se puede prometer stock negativo, y no se puede reservar
            // mas de lo que hay en el rack. Ambas reglas son de la base:
            // valen tambien para un UPDATE escrito a mano.
            $tabla->check('cantidad_fisica >= 0');
            $tabla->check('cantidad_reservada >= 0');
            $tabla->check('cantidad_reservada <= cantidad_fisica');
        });

        // ------------------------------------------------------------------
        // Gatillo: los saldos solo cambian por un movimiento.
        // ------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.almacen_saldo_solo_por_movimiento() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF current_setting('app.movimiento_stock', true) IS DISTINCT FROM '1' THEN
                    RAISE EXCEPTION USING
                        ERRCODE = 'insufficient_privilege',
                        MESSAGE = 'Los saldos de stock no se editan: se mueven. Toda variacion de cantidad_fisica o cantidad_reservada debe pasar por un movimiento de stock registrado.',
                        HINT = 'Use App\Services\Stock\RegistroDeMovimiento, o una operacion que lo use (reservar, liberar, consumir, traspasar, cerrar conteo).';
                END IF;

                RETURN NEW;
            END
            $$;

            COMMENT ON FUNCTION public.almacen_saldo_solo_por_movimiento() IS
            'Impide crear o modificar saldos sin el contexto app.movimiento_stock, que solo fija el registro de movimientos.';
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER gatillo_saldos_solo_por_movimiento
            BEFORE INSERT OR UPDATE ON public.saldos
            FOR EACH ROW
            EXECUTE FUNCTION public.almacen_saldo_solo_por_movimiento();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS gatillo_saldos_solo_por_movimiento ON public.saldos');
        DB::unprepared('DROP FUNCTION IF EXISTS public.almacen_saldo_solo_por_movimiento()');

        Schema::dropIfExists('saldos');
    }
};