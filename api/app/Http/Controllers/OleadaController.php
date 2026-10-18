<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EstadoOleada;
use App\Models\Oleada;
use App\Models\OleadaItem;
use App\Models\Zona;
use App\Services\Picking\ArmadorDeRuta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Oleadas de picking y la ruta de recoleccion.
 *
 * La ruta es el entregable de este modulo: un listado ordenado por
 * ubicacion, listo para imprimir y llevar al operario. El orden es lo que
 * ahorra el tiempo: el mismo pedido, ordenado al azar, obliga a volver
 * sobre pasos ya hechos.
 */
class OleadaController extends Controller
{
    public function __construct(private readonly ArmadorDeRuta $armador) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Oleada::class);

        $oleadas = Oleada::query()
            ->with('zona')
            ->withCount('items')
            ->when($request->query('estado'), fn ($consulta, string $estado) => $consulta->where('estado', $estado))
            ->orderByDesc('fecha')
            ->paginate((int) $request->query('por_pagina', 20));

        return response()->json($oleadas);
    }

    /** La ruta completa, con el rack de cada parada y su codigo de barras. */
    public function show(Oleada $oleada): JsonResponse
    {
        Gate::authorize('view', $oleada);

        $oleada->load(['zona', 'items.saldo.ubicacion', 'items.itemPedido.articulo', 'items.itemPedido.pedido']);

        return response()->json([
            'id' => $oleada->id,
            'nombre' => $oleada->nombre,
            'fecha' => $oleada->fecha?->format('Y-m-d'),
            'estado' => $oleada->estado->value,
            'zona' => $oleada->zona?->codigo,
            'paradas' => $oleada->items->map(fn (OleadaItem $parada): array => $this->presentarParada($parada)),
            'por_zona' => $oleada->resumenPorZona(),
        ]);
    }

    /**
     * Arma la ruta de la oleada: ordena por ubicacion y guarda el
     * resultado.
     */
    public function armar(Request $request, Oleada $oleada): JsonResponse
    {
        Gate::authorize('update', $oleada);

        $datos = $request->validate([
            'zona_id' => ['nullable', 'uuid', 'exists:zonas,id'],
        ], [], ['zona_id' => 'zona']);

        $zona = isset($datos['zona_id'])
            ? Zona::query()->findOrFail($datos['zona_id'])
            : $oleada->zona;

        $paradas = $this->armador->armar($oleada, $zona, $request->user());

        return response()->json([
            'mensaje' => sprintf('Ruta armada: %d paradas.', $paradas->count()),
            'paradas' => $paradas->map(fn (OleadaItem $parada): array => [
                'secuencia' => $parada->secuencia,
                'pedido_item_id' => $parada->pedido_item_id,
                'cantidad_base' => $parada->cantidad_base,
            ]),
        ]);
    }

    /** La ruta en texto plano, lista para imprimir. */
    public function ruta(Oleada $oleada): JsonResponse
    {
        Gate::authorize('view', $oleada);

        return response()->json([
            'oleada' => $oleada->nombre,
            'fecha' => $oleada->fecha?->format('Y-m-d'),
            'lineas' => $this->armador->rutaImprimible($oleada),
        ]);
    }

    /** Marca una parada como recogida. */
    public function recoger(Request $request, Oleada $oleada, OleadaItem $parada): JsonResponse
    {
        Gate::authorize('update', $oleada);

        // La parada tiene que ser de ESTA oleada. Sin esta comprobacion,
        // con un id de parada de otra oleada se marcaria algo que el
        // operario no recogio.
        if ($parada->oleada_id !== $oleada->id) {
            abort(404, 'La parada no pertenece a esta oleada.');
        }

        $this->armador->marcarRecogido($parada, $request->user());

        return response()->json([
            'id' => $parada->id,
            'secuencia' => $parada->secuencia,
            'estado' => $parada->estado,
        ]);
    }

    /** Cierra la oleada cuando todas sus paradas estan recogidas. */
    public function completar(Oleada $oleada): JsonResponse
    {
        Gate::authorize('update', $oleada);

        if ($oleada->estado !== EstadoOleada::EN_CURSO) {
            return response()->json([
                'mensaje' => 'La oleada no esta en curso.',
                'codigo' => 'oleada_no_en_curso',
            ], 422);
        }

        $pendientes = $oleada->items()
            ->where('estado', OleadaItem::PENDIENTE)
            ->count();

        if ($pendientes > 0) {
            return response()->json([
                'mensaje' => sprintf('Quedan %d paradas sin recoger.', $pendientes),
                'codigo' => 'oleada_con_paradas_pendientes',
            ], 422);
        }

        $oleada->estado = EstadoOleada::COMPLETADA;
        $oleada->save();

        return response()->json([
            'id' => $oleada->id,
            'estado' => $oleada->estado->value,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Oleada::class);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'fecha' => ['required', 'date'],
            'zona_id' => ['nullable', 'uuid', 'exists:zonas,id'],
        ], [], [
            'nombre' => 'nombre de la oleada',
            'fecha' => 'fecha',
            'zona_id' => 'zona',
        ]);

        $oleada = Oleada::query()->create([
            'tenant_id' => $request->user()->tenant_id,
            'nombre' => $datos['nombre'],
            'fecha' => Carbon::parse($datos['fecha']),
            'zona_id' => $datos['zona_id'] ?? null,
        ]);

        return response()->json([
            'id' => $oleada->id,
            'nombre' => $oleada->nombre,
            'estado' => $oleada->estado->value,
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarParada(OleadaItem $parada): array
    {
        $ubicacion = $parada->saldo?->ubicacion;

        return [
            'id' => $parada->id,
            'secuencia' => $parada->secuencia,
            'estado' => $parada->estado,
            'cantidad_base' => $parada->cantidad_base,
            'articulo' => [
                'id' => $parada->itemPedido?->articulo_id,
                'sku' => $parada->itemPedido?->articulo?->sku,
                'nombre' => $parada->itemPedido?->articulo?->nombre,
            ],
            'ubicacion' => $ubicacion === null ? null : [
                'id' => $ubicacion->id,
                'codigo' => $ubicacion->codigo,
                'codigo_barras' => $ubicacion->codigo_barras,
                'etiqueta' => $ubicacion->etiqueta(),
            ],
            'pedido' => [
                'numero' => $parada->itemPedido?->pedido?->numero,
                'prioridad' => $parada->itemPedido?->pedido?->prioridad,
            ],
        ];
    }
}