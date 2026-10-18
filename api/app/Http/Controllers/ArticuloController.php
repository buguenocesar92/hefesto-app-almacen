<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Articulo;
use App\Services\Stock\ConsultasDeDisponibilidad;
use App\Services\Unidades\ConversorDeUnidades;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Articulos y el stock que tienen.
 *
 * El endpoint que importa de verdad es `dondeEsta`: es la pregunta que se
 * hace el operario cuando el cliente pide 200 unidades y el sistema solo
 * dice "tenemos 240". Este responde con el rack, el pasillo y el codigo de
 * barras para escanearlo.
 */
class ArticuloController extends Controller
{
    public function __construct(
        private readonly ConsultasDeDisponibilidad $consultas,
        private readonly ConversorDeUnidades $conversor,
    ) {}

    /** Listado de articulos con las tres cifras, en unidad base. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Articulo::class);

        $articulos = Articulo::query()
            ->with(['unidadBase', 'unidadCompra', 'unidadVenta'])
            ->when($request->query('buscar'), function ($consulta, string $termino): void {
                $consulta->where(function ($interna) use ($termino): void {
                    $interna->where('sku', 'ilike', '%' . $termino . '%')
                        ->orWhere('nombre', 'ilike', '%' . $termino . '%');
                });
            })
            ->when($request->boolean('solo_activos'), fn ($consulta) => $consulta->where('activo', true))
            ->orderBy('nombre')
            ->paginate((int) $request->query('por_pagina', 50));

        $articulos->getCollection()->transform(function (Articulo $articulo): array {
            $cifras = $this->consultas->cifrasDeArticulo($articulo);

            return $this->presentar($articulo, $cifras);
        });

        return response()->json($articulos);
    }

    /** Ficha de un articulo con su detalle de unidades y stock. */
    public function show(Articulo $articulo): JsonResponse
    {
        Gate::authorize('view', $articulo);

        $articulo->load(['unidadBase', 'unidadCompra', 'unidadVenta']);

        return response()->json(
            $this->presentar($articulo, $this->consultas->cifrasDeArticulo($articulo))
        );
    }

    /**
     * DONDE ESTA: todas las ubicaciones con stock de este articulo, en
     * orden de recorrido.
     */
    public function dondeEsta(Request $request, Articulo $articulo): JsonResponse
    {
        Gate::authorize('verStock', $articulo);

        $ubicaciones = $this->consultas->dondeEsta(
            $articulo,
            $request->boolean('solo_disponible')
        );

        return response()->json([
            'articulo' => [
                'id' => $articulo->id,
                'sku' => $articulo->sku,
                'nombre' => $articulo->nombre,
            ],
            'cifras' => $this->consultas->cifrasDeArticulo($articulo),
            'ubicaciones' => $ubicaciones,
        ]);
    }

    /** Articulos bajo el minimo declarado. Alerta de reposicion. */
    public function bajoMinimo(): JsonResponse
    {
        Gate::authorize('viewAny', Articulo::class);

        $articulos = $this->consultas->articulosBajoMinimo()->map(function (Articulo $articulo): array {
            $cifras = $this->consultas->cifrasDeArticulo($articulo);

            return [
                'id' => $articulo->id,
                'sku' => $articulo->sku,
                'nombre' => $articulo->nombre,
                'minimo' => $articulo->stock_minimo,
                'disponible' => $cifras['disponible'],
                'en_venta' => $this->conversor->describenEnVenta($articulo, $cifras['disponible']),
            ];
        });

        return response()->json(['articulos' => $articulos]);
    }

    /** Historial de movimientos de un articulo. La historia, no el saldo. */
    public function movimientos(Request $request, Articulo $articulo): JsonResponse
    {
        Gate::authorize('view', $articulo);

        $movimientos = \App\Models\MovimientoStock::query()
            ->with(['ubicacion', 'motivo'])
            ->where('articulo_id', $articulo->id)
            ->when($request->query('ubicacion'), fn ($consulta, string $ubicacionId) => $consulta->where('ubicacion_id', $ubicacionId))
            ->orderByDesc('creado_en')
            ->paginate((int) $request->query('por_pagina', 50));

        return response()->json($movimientos);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Articulo::class);

        $datos = $request->validate([
            'sku' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9\-_.]+$/i'],
            'nombre' => ['required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'unidad_base_id' => ['required', 'uuid', 'exists:unidades_medida,id'],
            'unidad_compra_id' => ['required', 'uuid', 'exists:unidades_medida,id'],
            'unidad_venta_id' => ['required', 'uuid', 'exists:unidades_medida,id'],
            'stock_minimo' => ['nullable', 'numeric', 'min:0'],
            'activo' => ['nullable', 'boolean'],
        ], [], [
            'sku' => 'codigo interno',
            'unidad_base_id' => 'unidad base',
            'unidad_compra_id' => 'unidad de compra',
            'unidad_venta_id' => 'unidad de venta',
        ]);

        $articulo = Articulo::query()->create([
            ...$datos,
            'tenant_id' => $request->user()->tenant_id,
        ]);

        $articulo->load(['unidadBase', 'unidadCompra', 'unidadVenta']);

        // Se valida que las unidades sean coherentes ANTES de devolver
        // exito: un articulo con unidad base en cajas no sirve de nada.
        $this->conversor->verificarArticulo($articulo);

        return response()->json(
            $this->presentar($articulo, $this->consultas->cifrasDeArticulo($articulo)),
            201
        );
    }

    public function update(Request $request, Articulo $articulo): JsonResponse
    {
        Gate::authorize('update', $articulo);

        $datos = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'unidad_venta_id' => ['sometimes', 'uuid', 'exists:unidades_medida,id'],
            'stock_minimo' => ['nullable', 'numeric', 'min:0'],
            'activo' => ['sometimes', 'boolean'],
        ], [], [
            'unidad_venta_id' => 'unidad de venta',
        ]);

        $articulo->fill($datos)->save();

        $articulo->load(['unidadBase', 'unidadCompra', 'unidadVenta']);

        return response()->json(
            $this->presentar($articulo, $this->consultas->cifrasDeArticulo($articulo))
        );
    }

    /**
     * Desactiva un articulo. No se borra: un articulo que ya tiene
     * movimientos queda en la historia, y borrarlo dejaria movimientos
     * apuntando a la nada.
     */
    public function desactivar(Articulo $articulo): JsonResponse
    {
        Gate::authorize('desactivar', $articulo);

        $tieneMovimientos = \App\Models\MovimientoStock::query()
            ->where('articulo_id', $articulo->id)
            ->exists();

        if ($tieneMovimientos && $articulo->activo) {
            // No es un error: es el camino normal. Un articulo con historia
            // se desactiva, no se borra.
            $articulo->activo = false;
            $articulo->save();
        }

        return response()->json([
            'id' => $articulo->id,
            'activo' => $articulo->activo,
            'mensaje' => $articulo->activo
                ? 'El articulo no se puede desactivar mientras tenga movimientos.'
                : 'Articulo desactivado. Su historia sigue intacta.',
        ]);
    }

    /**
     * @param  array{fisico: string, reservado: string, disponible: string}  $cifras
     * @return array<string, mixed>
     */
    private function presentar(Articulo $articulo, array $cifras): array
    {
        return [
            'id' => $articulo->id,
            'sku' => $articulo->sku,
            'nombre' => $articulo->nombre,
            'descripcion' => $articulo->descripcion,
            'activo' => $articulo->activo,
            'unidad_base' => $articulo->unidadBase?->codigo,
            'unidad_compra' => $articulo->unidadCompra?->codigo,
            'unidad_venta' => $articulo->unidadVenta?->codigo,
            // "Una caja de eje son 12 unidades": la respuesta sale de las
            // unidades, no de un campo escrito a mano.
            'factor_compra_venta' => $articulo->factorCompraVenta(),
            'stock' => $cifras,
            'en_venta' => $this->conversor->describenEnVenta($articulo, $cifras['disponible']),
            'stock_minimo' => $articulo->stock_minimo,
        ];
    }
}