<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TipoDocumento;
use App\Models\Documento;
use App\Models\DocumentoItem;
use App\Models\MotivoMovimiento;
use App\Models\Ubicacion;
use App\Models\UnidadMedida;
use App\Services\Documentos\ServicioDeDocumentos;
use App\Services\Unidades\ConversorDeUnidades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Recepcion y despacho.
 *
 * Crear un documento NO mueve stock. Lo mueve `confirmar`, y ahi es donde
 * se llama al registro de movimientos. Esa separacion es a proposito: la
 * recepcion se digita mientras la mercaderia sigue bajando del camion.
 */
class DocumentoController extends Controller
{
    public function __construct(
        private readonly ServicioDeDocumentos $servicio,
        private readonly ConversorDeUnidades $conversor,
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Documento::class);

        $documentos = Documento::query()
            ->when($request->query('estado'), fn ($consulta, string $estado) => $consulta->where('estado', $estado))
            ->when($request->query('tipo'), fn ($consulta, string $tipo) => $consulta->where('tipo', $tipo))
            ->orderByDesc('created_at')
            ->paginate((int) $request->query('por_pagina', 30));

        return response()->json($documentos);
    }

    public function show(Documento $documento): JsonResponse
    {
        Gate::authorize('view', $documento);

        $documento->load(['items.articulo', 'items.unidad', 'items.origen', 'items.destino']);

        return response()->json([
            'id' => $documento->id,
            'numero' => $documento->numero,
            'tipo' => $documento->tipo->value,
            'estado' => $documento->estado->value,
            'fecha_documento' => $documento->fecha_documento?->format('Y-m-d'),
            'referencia_externa' => $documento->referencia_externa,
            'notas' => $documento->notas,
            'confirmado_en' => $documento->confirmado_en?->toIso8601String(),
            'anulado_en' => $documento->anulado_en?->toIso8601String(),
            'items' => $documento->items->map(fn (DocumentoItem $item): array => [
                'articulo_id' => $item->articulo_id,
                'sku' => $item->articulo?->sku,
                'nombre' => $item->articulo?->nombre,
                'cantidad' => $item->cantidad,
                'unidad' => $item->unidad?->codigo,
                'cantidad_base' => $item->cantidad_base,
                'origen' => $item->origen?->codigo,
                'destino' => $item->destino?->codigo,
            ]),
            'movimientos' => $documento->movimientos()->with('ubicacion')->get()->map(fn ($movimiento): array => [
                'id' => $movimiento->id,
                'tipo' => $movimiento->tipo->value,
                'ubicacion' => $movimiento->ubicacion?->codigo,
                'cantidad_base' => $movimiento->cantidad_base,
                'grupo_traspaso' => $movimiento->grupo_traspaso,
                'creado_en' => $movimiento->creado_en?->toIso8601String(),
            ]),
        ]);
    }

    /** Crea un documento en borrador. No toca el stock. */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Documento::class);

        $datos = $request->validate([
            'tipo' => ['required', 'string', 'in:' . implode(',', array_column(TipoDocumento::cases(), 'value'))],
            'numero' => ['required', 'string', 'max:32'],
            'fecha_documento' => ['required', 'date'],
            'referencia_externa' => ['nullable', 'string', 'max:64'],
            'notas' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.articulo_id' => ['required', 'uuid', 'exists:articulos,id'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.unidad_id' => ['nullable', 'uuid', 'exists:unidades_medida,id'],
            'items.*.ubicacion_origen_id' => ['nullable', 'uuid', 'exists:ubicaciones,id'],
            'items.*.ubicacion_destino_id' => ['nullable', 'uuid', 'exists:ubicaciones,id'],
        ], [], [
            'tipo' => 'tipo de documento',
            'numero' => 'numero',
            'items' => 'lineas',
            'items.*.cantidad' => 'cantidad',
        ]);

        $documento = Documento::query()->create([
            'tenant_id' => $request->user()->tenant_id,
            'tipo' => $datos['tipo'],
            'numero' => $datos['numero'],
            'fecha_documento' => Carbon::parse($datos['fecha_documento']),
            'referencia_externa' => $datos['referencia_externa'] ?? null,
            'notas' => $datos['notas'] ?? null,
            'usuario_creador_id' => $request->user()->id,
        ]);

        foreach ($datos['items'] as $orden => $linea) {
            $articulo = \App\Models\Articulo::query()->findOrFail($linea['articulo_id']);

            // Si no se dice la unidad, se asume la unidad de compra: es
            // como llega la mercaderia al almacen. Para una salida, lo
            // natural es la de venta. El tipo decide.
            $unidadId = $linea['unidad_id'] ?? (
                $datos['tipo'] === TipoDocumento::ENTRADA_COMPRA->value
                    ? $articulo->unidad_compra_id
                    : $articulo->unidad_venta_id
            );

            $unidad = UnidadMedida::query()->findOrFail($unidadId);

            DocumentoItem::query()->create([
                'tenant_id' => $documento->tenant_id,
                'documento_id' => $documento->id,
                'articulo_id' => $articulo->id,
                'ubicacion_origen_id' => $linea['ubicacion_origen_id'] ?? null,
                'ubicacion_destino_id' => $linea['ubicacion_destino_id'] ?? null,
                'cantidad' => $linea['cantidad'],
                'unidad_id' => $unidad->id,
                // La conversion se hace UNA vez, al crear la linea. Asi el
                // documento queda con las dos cifras y el movimiento no
                // vuelve a dividir nunca.
                'cantidad_base' => $this->conversor->aBase($articulo, $linea['cantidad'], $unidad),
                'orden' => $orden + 1,
            ]);
        }

        return response()->json($this->presentar($documento->fresh(['items'])), 201);
    }

    /** Confirma el documento y ejecuta los movimientos. */
    public function confirmar(Request $request, Documento $documento): JsonResponse
    {
        Gate::authorize('confirmar', $documento);

        $movimientos = $this->servicio->confirmar($documento, $request->user());

        return response()->json([
            'mensaje' => sprintf('Documento %s confirmado.', $documento->numero),
            'movimientos' => $movimientos->map(fn ($movimiento): array => [
                'id' => $movimiento->id,
                'tipo' => $movimiento->tipo->value,
                'cantidad_base' => $movimiento->cantidad_base,
                'ubicacion_id' => $movimiento->ubicacion_id,
                'grupo_traspaso' => $movimiento->grupo_traspaso,
            ]),
        ]);
    }

    /** Anula un documento confirmado con el movimiento contrario. */
    public function anular(Request $request, Documento $documento): JsonResponse
    {
        Gate::authorize('anular', $documento);

        $datos = $request->validate([
            'motivo_id' => ['required', 'uuid', 'exists:motivos_movimiento,id'],
            'explicacion' => ['nullable', 'string', 'max:1000'],
        ], [], ['motivo_id' => 'motivo']);

        $motivo = MotivoMovimiento::query()->findOrFail($datos['motivo_id']);

        $movimientos = $this->servicio->anular(
            $documento,
            $motivo,
            $request->user(),
            $datos['explicacion'] ?? null
        );

        return response()->json([
            'mensaje' => sprintf('Documento %s anulado. El stock quedo devuelto con movimiento.', $documento->numero),
            'movimientos' => $movimientos->count(),
        ]);
    }

    /** Ubicaciones disponibles para elegir al digitar un documento. */
    public function ubicacionesDisponibles(string $tipo): JsonResponse
    {
        Gate::authorize('viewAny', Documento::class);

        $ubicaciones = Ubicacion::query()
            ->where('activo', true)
            ->orderBy('pasillo')
            ->orderBy('rack')
            ->orderBy('nivel')
            ->orderBy('posicion')
            ->get()
            ->map(fn (Ubicacion $ubicacion): array => [
                'id' => $ubicacion->id,
                'codigo' => $ubicacion->codigo,
                'codigo_barras' => $ubicacion->codigo_barras,
                'etiqueta' => $ubicacion->etiqueta(),
            ]);

        return response()->json(['tipo' => $tipo, 'ubicaciones' => $ubicaciones]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Documento $documento): array
    {
        return [
            'id' => $documento->id,
            'numero' => $documento->numero,
            'tipo' => $documento->tipo->value,
            'estado' => $documento->estado->value,
            'fecha_documento' => $documento->fecha_documento?->format('Y-m-d'),
            'lineas' => $documento->items->count(),
        ];
    }
}