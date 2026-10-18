<?php

declare(strict_types=1);

use App\Enums\EstadoPedido;
use App\Enums\EstadoItemPedido;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedidos y reservas.
 *
 * UNA RESERVA TIENE FECHA DE VENCIMIENTO
 * --------------------------------------
 * El error caro de un almacen no es que falte stock: es prometer stock que
 * ya salio. Un pedido que nadie confirma mantiene reservada la mercaderia
 * para siempre, y el almacen cree que tiene disponible lo que en realidad
 * ya esta en el camion de otro. Por eso `reservas.expira_en` existe y no es
 * decorativo: `almacen:liberar-reservas-vencidas` (y el trabajo en cola que
 * lo invoca) devuelven la unidad a disponible cuando el plazo se cumple.
 *
 * La reserva reserva sobre UN SALDO, o sea, sobre una ubicacion concreta.
 * No es "reservar 10 del articulo" a secas: es "estas 10 unidades son las
 * de este rack". Por eso el picking puede armar una ruta sin volver a
 * preguntar donde esta nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();

            $tabla->string('numero', 32);
            $tabla->string('referencia_externa', 64)->nullable();
            $tabla->string('destinatario', 160)->nullable();

            $tabla->string('estado', 16)->default(EstadoPedido::PENDIENTE->value);

            // 1 es la mas urgente. El orden por oleadas usa esto.
            $tabla->unsignedSmallInteger('prioridad')->default(5);

            $tabla->date('fecha_entrega')->nullable();
            $tabla->text('notas')->nullable();

            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'numero']);
            $tabla->index(['tenant_id', 'estado']);
        });

        Schema::create('pedido_items', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $tabla->foreignUuid('pedido_id')->constrained('pedidos')->cascadeOnDelete();
            $tabla->foreignUuid('articulo_id')->constrained('articulos')->restrictOnDelete();

            $tabla->decimal('cantidad', 18, 6);
            $tabla->foreignUuid('unidad_id')->constrained('unidades_medida')->restrictOnDelete();
            $tabla->decimal('cantidad_base', 18, 6);

            $tabla->string('estado', 16)->default(EstadoItemPedido::PENDIENTE->value);

            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'pedido_id']);
            $tabla->check('cantidad > 0');
        });

        Schema::create('reservas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $tabla->foreignUuid('pedido_item_id')->constrained('pedido_items')->cascadeOnDelete();

            // El saldo reservado. Es la fila que se actualiza para liberar
            // o consumir, siempre a traves del registro de movimientos.
            $tabla->foreignUuid('saldo_id')->constrained('saldos')->cascadeOnDelete();

            $tabla->decimal('cantidad_base', 18, 6);

            $tabla->string('estado', 16);
            $tabla->timestampTz('expira_en');
            $tabla->timestampTz('resuelto_en')->nullable();

            $tabla->timestampsTz();

            // Indice del trabajo que vence reservas: filtra por estado y
            // ordena por vencimiento. Sin este indice, el trabajo escanea
            // toda la tabla de reservas en cada pasada.
            $tabla->index(['tenant_id', 'estado', 'expira_en']);

            $tabla->check('cantidad_base > 0');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservas');
        Schema::dropIfExists('pedido_items');
        Schema::dropIfExists('pedidos');
    }
};