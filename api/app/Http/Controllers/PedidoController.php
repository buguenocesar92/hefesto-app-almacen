<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Documento;
use App\Models\MotivoMovimiento;
use App\Models\Pedido;
use App\Models\PedidoItem;
use App\Models\UnidadMedida;
use App\Services\Reservas\GestorDeReservas;
use App\Services\Unidades\ConversorDeUnidades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Pedidos, reserva y despacho.
 *
 * El flujo normal es: crear pedido -> reservar -> armar oleada -> recoger
 * -> despachar. Reservar compromete stock disponible; despachar lo saca
 * del rack. Lo que este controlador hace es que cada paso quede escrito
 * con su motivo.
 */
class PedidoController extends Controller
{
    public function __construct(
        private readonly GestorDeReservas $gestor,
        private readonly ConversorDeUnidades $conversor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Pedido::class);

        $pedidos = Pedido::query()
            ->withCount('items')
            ->when($request->query('estado'), fn ($consulta, string $estado) => $consulta->where('estado', $estado))
            ->when($request->boolean('abiertos'), fn ($consulta) => $consulta->whereNotIn('estado', ['despachado', 'cancelado']))
            ->orderBy('prioridad')
            ->orderBy('fecha_entrega')
            ->paginate((int) $request->query('por_pagina', 30));

        return response()->json($pedidos);
    }

    public function show(Pedido $pedido): JsonResponse
    {
        Gate::authorize('view', $pedido);

        $pedido->load(['items.articulo', 'items.unidad']);

        $reservas = \App\Models\Reserva::query()
            ->with(['saldo.ubicacion'])
            ->whereIn('pedido_item_id', $pedido->items->pluck('id'))
            ->orderBy('expira_en')
            ->get();

        return response()->json([
            'id' => $pedido->id,
            'numero' => $pedido->numero,
            'referencia_externa' => $pedido->referencia_externa,
            'destinatario' => $pedido->destinatario,
            'estado' => $pedido->estado->value,
            'prioridad' => $pedido->prioridad,
            'fecha_entrega' => $pedido->fecha_entrega?->format('Y-m-d'),
            'notas' => $pedido->notas,
            'items' => $pedido->items->map(fn (PedidoItem $item): array => [
                'id' => $item->id,
                'articulo_id' => $item->articulo_id,
                'sku' => $item->articulo?->sku,
                'nombre' => $item->articulo?->nombre,
                'cantidad' => $item->cantidad,
                'unidad' => $item->unidad?->codigo,
                'cantidad_base' => $item->cantidad_base,
                'estado' => $item->estado->value,
            ]),
            'reservas' => $reservas->map(fn ($reserva): array => [
                'id' => $reserva->id,
                'pedido_item_id' => $reserva->pedido_item_id,
                'cantidad_base' => $reserva->cantidad_base,
                'estado' => $reserva->estado->value,
                'expira_en' => $reserva->expira_en?->toIso8601String(),
                'ubicacion' => $reserva->saldo?->ubicacion?->codigo,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Pedido::class);

        $datos = $request->validate([
            'numero' => ['required', 'string', 'max:32'],
            'referencia_externa' => ['nullable', 'string', 'max:64'],
            'destinatario' => ['nullable', 'string', 'max:160'],
            'prioridad' => ['nullable', 'integer', 'min:1', 'max:9'],
            'fecha_entrega' => ['nullable', 'date'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.articulo_id' => ['required', 'uuid', 'exists:articulos,id'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.unidad_id' => ['nullable', 'uuid', 'exists:unidades_medida,id'],
        ], [], [
            'numero' => 'numero de pedido',
            'items' => 'lineas',
            'items.*.cantidad' => 'cantidad',
        ]);

        $pedido = Pedido::query()->create([
            'tenant_id' => $request->user()->tenant_id,
            'numero' => $datos['numero'],
            'referencia_externa' => $datos['referencia_externa'] ?? null,
            'destinatario' => $datos['destinatario'] ?? null,
            'prioridad' => $datos['prioridad'] ?? 5,
            'fecha_entrega' => isset($datos['fecha_entrega']) ? Carbon::parse($datos['fecha_entrega']) : null,
            'notas' => $datos['notas'] ?? null,
        ]);

        foreach ($datos['items'] as $linea) {
            $articulo = \App\Models\Articulo::query()->findOrFail($linea['articulo_id']);

            // El pedido se digita en la unidad en que se vende el
            // articulo. Si viene otra, se respeta: a veces se pide media
            // caja y se anota como media caja.
            $unidadId = $linea['unidad_id'] ?? $articulo->unidad_venta_id;

            $unidad = UnidadMedida::query()->findOrFail($unidadId);

            PedidoItem::query()->create([
                'tenant_id' => $pedido->tenant_id,
                'pedido_id' => $pedido->id,
                'articulo_id' => $articulo->id,
                'cantidad' => $linea['cantidad'],
                'unidad_id' => $unidad->id,
                'cantidad_base' => $this->conversor->aBase($articulo, $linea['cantidad'], $unidad),
            ]);
        }

        return response()->json([
            'id' => $pedido->id,
            'numero' => $pedido->numero,
            'estado' => $pedido->estado->value,
            'lineas' => $pedido->items()->count(),
        ], 201);
    }

    /**
     * Reserva el pedido: compromete stock disponible con fecha de
     * vencimiento.
     */
    public function reservar(Request $request, Pedido $pedido): JsonResponse
    {
        Gate::authorize('reservar', $pedido);

        $datos = $request->validate([
            'expira_en' => ['nullable', 'date', 'after:now'],
        ], [], ['expira_en' => 'vencimiento de la reserva']);

        $expiraEn = isset($datos['expira_en']) ? Carbon::parse($datos['expira_en']) : null;

        $reservas = $this->gestor->reservar($pedido, $expiraEn, $request->user());

        return response()->json([
            'mensaje' => sprintf('Pedido %s reservado.', $pedido->numero),
            'reservas' => $reservas->map(fn ($reserva): array => [
                'id' => $reserva->id,
                'cantidad_base' => $reserva->cantidad_base,
                'expira_en' => $reserva->expira_en?->toIso8601String(),
            ]),
            'vence' => $reservas->first()?->expira_en?->toIso8601String(),
        ]);
    }

    /** Libera la reserva del pedido y devuelve las unidades a disponible. */
    public function liberar(Pedido $pedido): JsonResponse
    {
        Gate::authorize('liberar', $pedido);

        $liberadas = $this->gestor->liberarPedido($pedido);

        return response()->json([
            'mensaje' => sprintf('Pedido %s liberado. El stock vuelve a estar disponible.', $pedido->numero),
            'reservas_liberadas' => $liberadas,
        ]);
    }

    /**
     * Despacha el pedido: la mercaderia sale del rack.
     *
     * Se pide el motivo de salida porque sin el no hay investigacion
     * posible el dia que falte mercaderia.
     */
    public function despachar(Request $request, Pedido $pedido): JsonResponse
    {
        Gate::authorize('despachar', $pedido);

        $datos = $request->validate([
            'motivo_id' => ['required', 'uuid', 'exists:motivos_movimiento,id'],
        ], [], ['motivo_id' => 'motivo de salida']);

        $motivo = MotivoMovimiento::query()->findOrFail($datos['motivo_id']);

        // El despacho queda registrado tambien como documento de salida,
        // para que la recepcion y el despacho se vean de la misma manera.
        $documento = Documento::query()->create([
            'tenant_id' => $pedido->tenant_id,
            'tipo' => \App\Enums\TipoDocumento::SALIDA_VENTA,
            'numero' => 'DESP-' . $pedido->numero,
            'fecha_documento' => Carbon::now(),
            'referencia_externa' => $pedido->referencia_externa,
            'usuario_creador_id' => $request->user()->id,
        ]);

        foreach ($pedido->items()->with('reservas.saldo')->get() as $item) {
            foreach ($item->reservas as $reserva) {
                \App\Models\DocumentoItem::query()->create([
                    'tenant_id' => $pedido->tenant_id,
                    'documento_id' => $documento->id,
                    'articulo_id' => $item->articulo_id,
                    'ubicacion_origen_id' => $reserva->saldo?->ubicacion_id,
                    'cantidad' => $reserva->cantidad_base,
                    'unidad_id' => $item->unidad_id,
                    'cantidad_base' => $reserva->cantidad_base,
                ]);
            }
        }

        $consumidas = $this->gestor->consumir($pedido, $motivo, $request->user(), $documento->numero);

        $documento->estado = \App\Enums\EstadoDocumento::CONFIRMADO;
        $documento->confirmado_en = Carbon::now();
        $documento->save();

        return response()->json([
            'mensaje' => sprintf('Pedido %s despachado.', $pedido->numero),
            'reservas_consumidas' => $consumidas,
            'documento' => $documento->numero,
        ]);
    }
}