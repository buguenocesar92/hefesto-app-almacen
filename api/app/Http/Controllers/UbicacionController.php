<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Saldo;
use App\Models\Ubicacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Ubicaciones: el mapa del almacen.
 *
 * Cada fila es una direccion (pasillo, rack, nivel, posicion) con su
 * codigo de barras. Ver tambien `ArticuloController::dondeEsta`, que es
 * el otro lado de la misma pregunta.
 */
class UbicacionController extends Controller
{
    /** Todas las ubicaciones, con lo que hay en cada una. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Ubicacion::class);

        $ubicaciones = Ubicacion::query()
            ->with('zona')
            ->when($request->query('pasillo'), fn ($consulta, string $pasillo) => $consulta->where('pasillo', $pasillo))
            ->when($request->query('rack'), fn ($consulta, string $rack) => $consulta->where('rack', $rack))
            ->when($request->query('zona'), fn ($consulta, string $zonaId) => $consulta->where('zona_id', $zonaId))
            ->orderBy('pasillo')
            ->orderBy('rack')
            ->orderBy('nivel')
            ->orderBy('posicion')
            ->get()
            ->map(fn (Ubicacion $ubicacion): array => $this->presentar($ubicacion));

        return response()->json(['ubicaciones' => $ubicaciones]);
    }

    /** Ficha de una ubicacion con su contenido. */
    public function show(Ubicacion $ubicacion): JsonResponse
    {
        Gate::authorize('view', $ubicacion);

        $ubicacion->load('zona');

        $saldos = Saldo::query()
            ->with('articulo')
            ->where('ubicacion_id', $ubicacion->id)
            ->where('cantidad_fisica', '>', 0)
            ->orderBy('articulo_id')
            ->get()
            ->map(fn (Saldo $saldo): array => [
                'articulo_id' => $saldo->articulo_id,
                'sku' => $saldo->articulo?->sku,
                'nombre' => $saldo->articulo?->nombre,
                'fisico' => $saldo->cantidad_fisica,
                'reservado' => $saldo->cantidad_reservada,
                'disponible' => $saldo->disponible,
            ]);

        return response()->json([
            ...$this->presentar($ubicacion),
            'contenido' => $saldos,
        ]);
    }

    /** Busqueda por codigo de barras: lo que hace el scanner. */
    public function porCodigoDeBarras(string $codigo): JsonResponse
    {
        Gate::authorize('viewAny', Ubicacion::class);

        $ubicacion = Ubicacion::query()
            ->where('codigo_barras', $codigo)
            ->first();

        // 404 y no 403: la respuesta no confirma si el codigo existe en
        // otra empresa.
        if ($ubicacion === null) {
            abort(404, 'Codigo de barras desconocido.');
        }

        $ubicacion->load('zona');

        return response()->json($this->presentar($ubicacion));
    }

    /** El pasillo completo, como lo ve el operario que va a recorrerlo. */
    public function pasillo(string $nombre): JsonResponse
    {
        Gate::authorize('viewAny', Ubicacion::class);

        $ubicaciones = Ubicacion::query()
            ->where('pasillo', $nombre)
            ->orderBy('rack')
            ->orderBy('nivel')
            ->orderBy('posicion')
            ->get()
            ->map(fn (Ubicacion $ubicacion): array => $this->presentar($ubicacion));

        return response()->json([
            'pasillo' => $nombre,
            'total' => $ubicaciones->count(),
            'ubicaciones' => $ubicaciones,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Ubicacion::class);

        $datos = $this->validar($request);

        $ubicacion = Ubicacion::query()->create([
            ...$datos,
            // Si no se manda el codigo, se arma desde las partes, que es lo
            // que el operario escribe en la etiqueta de todas formas.
            'codigo' => $datos['codigo'] ?? $this->armarCodigo($datos),
            'tenant_id' => $request->user()->tenant_id,
        ]);

        $ubicacion->load('zona');

        return response()->json($this->presentar($ubicacion), 201);
    }

    public function update(Request $request, Ubicacion $ubicacion): JsonResponse
    {
        Gate::authorize('update', $ubicacion);

        $datos = $this->validar($request, parcial: true);

        $ubicacion->fill($datos)->save();

        $ubicacion->load('zona');

        return response()->json($this->presentar($ubicacion));
    }

    /**
     * Desactiva una ubicacion.
     *
     * Si tiene mercaderia, NO se desactiva: una ubicacion sin direccion es
     * exactamente el problema que este sistema existe para evitar. Hay que
     * mover la mercaderia primero.
     */
    public function desactivar(Ubicacion $ubicacion): JsonResponse
    {
        Gate::authorize('desactivar', $ubicacion);

        $conStock = Saldo::query()
            ->where('ubicacion_id', $ubicacion->id)
            ->where('cantidad_fisica', '>', 0)
            ->exists();

        if ($conStock) {
            return response()->json([
                'mensaje' => sprintf(
                    'La ubicacion %s tiene mercaderia. Traslade la mercaderia antes de desactivarla.',
                    $ubicacion->codigo
                ),
                'codigo' => 'ubicacion_con_stock',
            ], 422);
        }

        $ubicacion->activo = false;
        $ubicacion->save();

        return response()->json($this->presentar($ubicacion->fresh('zona')));
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, bool $parcial = false): array
    {
        $presente = $parcial ? 'sometimes' : 'required';

        return $request->validate([
            'zona_id' => [$presente, 'nullable', 'uuid', 'exists:zonas,id'],
            'codigo' => [$parcial ? 'sometimes' : 'nullable', 'string', 'max:40'],
            'codigo_barras' => [$presente, 'string', 'max:64'],
            'pasillo' => [$presente, 'string', 'max:16'],
            'rack' => [$presente, 'string', 'max:16'],
            'nivel' => [$presente, 'integer', 'min:0', 'max:99'],
            'posicion' => [$presente, 'integer', 'min:0', 'max:99'],
            'descripcion' => ['nullable', 'string', 'max:160'],
            'activo' => ['nullable', 'boolean'],
        ], [], [
            'codigo_barras' => 'codigo de barras',
            'nivel' => 'nivel',
            'posicion' => 'posicion',
        ]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function armarCodigo(array $datos): string
    {
        return sprintf(
            '%s-%s-%02d-%02d',
            $datos['pasillo'],
            $datos['rack'],
            $datos['nivel'],
            $datos['posicion']
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(Ubicacion $ubicacion): array
    {
        return [
            'id' => $ubicacion->id,
            'codigo' => $ubicacion->codigo,
            'codigo_barras' => $ubicacion->codigo_barras,
            'pasillo' => $ubicacion->pasillo,
            'rack' => $ubicacion->rack,
            'nivel' => $ubicacion->nivel,
            'posicion' => $ubicacion->posicion,
            'etiqueta' => $ubicacion->etiqueta(),
            'descripcion' => $ubicacion->descripcion,
            'zona' => $ubicacion->zona?->codigo,
            'zona_id' => $ubicacion->zona_id,
            'activo' => $ubicacion->activo,
        ];
    }
}