<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Conteo;
use App\Models\ConteoItem;
use App\Models\Zona;
use App\Services\Conteos\ServicioDeConteos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Inventario fisico y ajustes.
 *
 * Abrir un conteo congela la zona; cerrarlo exige justificar cada
 * diferencia. La interfaz tiene que dejar claro que la zona esta
 * congelada mientras el conteo esta abierto, porque si alguien intenta
 * mover mercaderia ahi se va a encontrar con un rechazo.
 */
class ConteoController extends Controller
{
    public function __construct(private readonly ServicioDeConteos $servicio) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Conteo::class);

        $conteos = Conteo::query()
            ->with('zona')
            ->withCount('items')
            ->when($request->query('estado'), fn ($consulta, string $estado) => $consulta->where('estado', $estado))
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('por_pagina', 20));

        return response()->json($conteos);
    }

    /** Zonas cuya hace mas tiempo que no se cuentan: la rotacion. */
    public function pendientes(): JsonResponse
    {
        Gate::authorize('viewAny', Conteo::class);

        $dias = (int) config('almacen.conteo.dias_minimos_entre_conteos', 30);

        $zonas = $this->servicio->zonasParaContar()->map(fn (Zona $zona): array => [
            'id' => $zona->id,
            'codigo' => $zona->codigo,
            'nombre' => $zona->nombre,
            'ultimo_conteo' => $zona->ultimo_conteo,
            'dias_desde' => $zona->ultimo_conteo === null
                ? null
                : (int) Carbon::parse($zona->ultimo_conteo)->diffInDays(Carbon::now()),
            'vence' => $zona->ultimo_conteo !== null
                && Carbon::parse($zona->ultimo_conteo)->diffInDays(Carbon::now()) >= $dias,
        ]);

        return response()->json([
            'dias_minimos' => $dias,
            'zonas' => $zonas,
        ]);
    }

    /** Ficha del conteo, con el sistema congelado y lo contado. */
    public function show(Conteo $conteo): JsonResponse
    {
        Gate::authorize('view', $conteo);

        $conteo->load(['zona', 'items.articulo', 'items.ubicacion', 'items.motivo']);

        return response()->json([
            'id' => $conteo->id,
            'nombre' => $conteo->nombre,
            'zona' => $conteo->zona?->codigo,
            'estado' => $conteo->estado->value,
            'congelado' => $conteo->abierto(),
            'abierto_en' => $conteo->abierto_en?->toIso8601String(),
            'cerrado_en' => $conteo->cerrado_en?->toIso8601String(),
            'items' => $conteo->items->map(fn (ConteoItem $item): array => [
                'id' => $item->id,
                'articulo' => [
                    'id' => $item->articulo_id,
                    'sku' => $item->articulo?->sku,
                    'nombre' => $item->articulo?->nombre,
                ],
                'ubicacion' => [
                    'id' => $item->ubicacion_id,
                    'codigo' => $item->ubicacion?->codigo,
                    'etiqueta' => $item->ubicacion?->etiqueta(),
                ],
                'cantidad_sistema' => $item->cantidad_sistema,
                'cantidad_contada' => $item->cantidad_contada,
                'diferencia' => $item->diferencia,
                'motivo_id' => $item->motivo_movimiento_id,
                'justificacion' => $item->justificacion,
            ]),
            'resumen' => [
                'paradas' => $conteo->items->count(),
                'contadas' => $conteo->items->filter(fn (ConteoItem $item): bool => $item->contado())->count(),
                'diferencias' => $conteo->items->filter(fn (ConteoItem $item): bool => $item->tieneDiferencia())->count(),
            ],
        ]);
    }

    /**
     * Abre un conteo de una zona.
     *
     * A partir de este momento los movimientos en las ubicaciones de la
     * zona quedan rechazados: el conteo compara contra una foto y la foto
     * tiene que seguir valiendo.
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Conteo::class);

        $datos = $request->validate([
            'zona_id' => ['required', 'uuid', 'exists:zonas,id'],
            'nombre' => ['required', 'string', 'max:120'],
        ], [], [
            'zona_id' => 'zona',
            'nombre' => 'nombre del conteo',
        ]);

        $zona = Zona::query()->findOrFail($datos['zona_id']);

        $conteo = $this->servicio->abrir($zona, $datos['nombre'], $request->user());

        return response()->json([
            'id' => $conteo->id,
            'nombre' => $conteo->nombre,
            'zona' => $zona->codigo,
            'estado' => $conteo->estado->value,
            'paradas_congeladas' => $conteo->items->count(),
            'mensaje' => 'Zona congelada: no se puede mover mercaderia hasta cerrar el conteo.',
        ], 201);
    }

    /** Registra lo que se conto en una parada. */
    public function registrar(Request $request, Conteo $conteo, ConteoItem $item): JsonResponse
    {
        Gate::authorize('registrar', $conteo);

        if ($item->conteo_id !== $conteo->id) {
            abort(404, 'La parada no pertenece a este conteo.');
        }

        $datos = $request->validate([
            'cantidad' => ['required', 'numeric', 'min:0'],
            'motivo_id' => ['nullable', 'uuid', 'exists:motivos_movimiento,id'],
            'justificacion' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'cantidad' => 'cantidad contada',
            'motivo_id' => 'motivo',
            'justificacion' => 'justificacion',
        ]);

        // El motivo y la explicacion se pueden dejar para el cierre: lo que
        // no se puede es contrascribir despues la foto del sistema.
        if (isset($datos['motivo_id'])) {
            $item->motivo_movimiento_id = $datos['motivo_id'];
            $item->justificacion = $datos['justificacion'] ?? null;
        }

        $item = $this->servicio->registrar($conteo, $item, $datos['cantidad'], $request->user());

        return response()->json([
            'id' => $item->id,
            'cantidad_sistema' => $item->cantidad_sistema,
            'cantidad_contada' => $item->cantidad_contada,
        ]);
    }

    /**
     * Justifica una diferencia antes de cerrar.
     *
     * Se separa del cierre para que la persona pueda escribir la
     * explicacion con calma, mirando el papel del conteo al lado.
     */
    public function justificar(Conteo $conteo, ConteoItem $item): JsonResponse
    {
        Gate::authorize('registrar', $conteo);

        if ($item->conteo_id !== $conteo->id) {
            abort(404, 'La parada no pertenece a este conteo.');
        }

        $datos = request()->validate([
            'motivo_id' => ['required', 'uuid', 'exists:motivos_movimiento,id'],
            'justificacion' => ['required', 'string', 'min:10', 'max:1000'],
        ], [], [
            'motivo_id' => 'motivo',
            'justificacion' => 'explicacion',
        ]);

        $item->motivo_movimiento_id = $datos['motivo_id'];
        $item->justificacion = $datos['justificacion'];
        $item->save();

        return response()->json([
            'id' => $item->id,
            'motivo_id' => $item->motivo_movimiento_id,
            'justificacion' => $item->justificacion,
        ]);
    }

    /**
     * Cierra el conteo: calcula diferencias y las corrige con
     * movimientos.
     */
    public function cerrar(Conteo $conteo): JsonResponse
    {
        Gate::authorize('cerrar', $conteo);

        $resultado = $this->servicio->cerrar($conteo, request()->user());

        return response()->json([
            'id' => $conteo->id,
            'estado' => $conteo->estado->value,
            'ajustes' => $resultado['ajustes']->map(fn (ConteoItem $item): array => [
                'articulo_id' => $item->articulo_id,
                'ubicacion_id' => $item->ubicacion_id,
                'diferencia' => $item->diferencia,
                'movimiento_id' => $item->movimiento_id,
            ]),
            'mensaje' => sprintf(
                'Conteo cerrado con %d ajuste(s). Las diferencias quedaron con movimiento y justificacion.',
                $resultado['ajustes']->count()
            ),
        ]);
    }

    /** Cancela un conteo a medias y devuelve la zona al movimiento. */
    public function cancelar(Conteo $conteo): JsonResponse
    {
        Gate::authorize('cancelar', $conteo);

        $this->servicio->cancelar($conteo, request()->user());

        return response()->json([
            'id' => $conteo->id,
            'estado' => $conteo->estado->value,
            'mensaje' => 'Conteo cancelado. La zona vuelve a moverse con normalidad.',
        ]);
    }
}