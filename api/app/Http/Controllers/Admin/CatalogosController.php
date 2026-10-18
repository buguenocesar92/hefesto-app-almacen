<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SentidoMovimiento;
use App\Models\MotivoMovimiento;
use App\Models\UnidadMedida;
use App\Models\Usuario;
use App\Models\Zona;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Catalogos de administracion: zonas, motivos, unidades y personas.
 *
 * Todo lo que se escribe aca cambia el modo en que trabaja el resto del
 * sistema, asi que queda en administracion. Un motivo mal definido hace
 * que un ajuste se registre como si fuera una compra; una unidad con
 * factor erroneo hace que todos los numeros de stock sean erroneos.
 *
 * La lectura es libre: cualquiera necesita ver los motivos para entender
 * un movimiento y las unidades para leer un articulo.
 */
class CatalogosController extends Controller
{
    // ------------------------------------------------------------------
    // Zonas
    // ------------------------------------------------------------------

    public function zonas(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Zona::class);

        $zonas = Zona::query()
            ->withCount('ubicaciones')
            ->orderBy('codigo')
            ->get()
            ->map(fn (Zona $zona): array => $this->presentarZona($zona));

        return response()->json(['zonas' => $zonas]);
    }

    public function guardarZona(Request $request): JsonResponse
    {
        Gate::authorize('create', Zona::class);

        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:16'],
            'nombre' => ['required', 'string', 'max:120'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'pasillo_desde' => ['nullable', 'string', 'max:16'],
            'pasillo_hasta' => ['nullable', 'string', 'max:16'],
            'activo' => ['nullable', 'boolean'],
        ], [], [
            'codigo' => 'codigo',
            'nombre' => 'nombre',
            'pasillo_desde' => 'primer pasillo',
            'pasillo_hasta' => 'ultimo pasillo',
        ]);

        $zona = Zona::query()->create([
            ...$datos,
            'tenant_id' => $request->user()->tenant_id,
        ]);

        return response()->json($this->presentarZona($zona), 201);
    }

    // ------------------------------------------------------------------
    // Motivos de movimiento
    // ------------------------------------------------------------------

    public function motivos(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', MotivoMovimiento::class);

        $motivos = MotivoMovimiento::query()
            ->orderBy('codigo')
            ->get()
            ->map(fn (MotivoMovimiento $motivo): array => [
                'id' => $motivo->id,
                'codigo' => $motivo->codigo,
                'nombre' => $motivo->nombre,
                'sentido' => $motivo->sentido->value,
                'exige_justificacion' => $motivo->exige_justificacion,
                'activo' => $motivo->activo,
            ]);

        return response()->json(['motivos' => $motivos]);
    }

    public function guardarMotivo(Request $request): JsonResponse
    {
        Gate::authorize('alta', MotivoMovimiento::class);

        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:24'],
            'nombre' => ['required', 'string', 'max:120'],
            'sentido' => ['required', 'string', 'in:' . implode(',', array_column(SentidoMovimiento::cases(), 'value'))],
            'exige_justificacion' => ['nullable', 'boolean'],
            'activo' => ['nullable', 'boolean'],
        ], [], [
            'codigo' => 'codigo',
            'nombre' => 'nombre',
            'sentido' => 'sentido',
            'exige_justificacion' => 'exige justificacion',
        ]);

        $motivo = MotivoMovimiento::query()->create([
            ...$datos,
            'tenant_id' => $request->user()->tenant_id,
        ]);

        return response()->json([
            'id' => $motivo->id,
            'codigo' => $motivo->codigo,
            'nombre' => $motivo->nombre,
            'sentido' => $motivo->sentido->value,
            'exige_justificacion' => $motivo->exige_justificacion,
        ], 201);
    }

    // ------------------------------------------------------------------
    // Unidades de medida
    // ------------------------------------------------------------------

    public function unidades(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', UnidadMedida::class);

        $unidades = UnidadMedida::query()
            ->orderBy('unidades_por_base')
            ->orderBy('codigo')
            ->get()
            ->map(fn (UnidadMedida $unidad): array => [
                'id' => $unidad->id,
                'codigo' => $unidad->codigo,
                'nombre' => $unidad->nombre,
                'unidades_por_base' => $unidad->unidades_por_base,
                'es_base' => $unidad->esBase(),
                'activo' => $unidad->activo,
            ]);

        return response()->json(['unidades' => $unidades]);
    }

    /**
     * Crea una unidad con su factor de conversion.
     *
     * El factor es lo mas importante de esta pantalla: si se escribe mal,
     * todos los numeros de stock del almacen quedan mal, y el error
     * aparece semanas despues cuando ya no se sabe de donde vino.
     */
    public function guardarUnidad(Request $request): JsonResponse
    {
        Gate::authorize('alta', UnidadMedida::class);

        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:24'],
            'nombre' => ['required', 'string', 'max:80'],
            'unidades_por_base' => ['required', 'numeric', 'gt:0'],
            'activo' => ['nullable', 'boolean'],
        ], [
            'unidades_por_base' => [
                'gt' => 'La cantidad de unidades por unidad base debe ser mayor que cero.',
            ],
        ], [
            'codigo' => 'codigo',
            'nombre' => 'nombre',
            'unidades_por_base' => 'unidades por unidad base',
        ]);

        $unidad = UnidadMedida::query()->create([
            ...$datos,
            'tenant_id' => $request->user()->tenant_id,
        ]);

        return response()->json([
            'id' => $unidad->id,
            'codigo' => $unidad->codigo,
            'nombre' => $unidad->nombre,
            'unidades_por_base' => $unidad->unidades_por_base,
            'es_base' => $unidad->esBase(),
        ], 201);
    }

    // ------------------------------------------------------------------
    // Personas
    // ------------------------------------------------------------------

    public function usuarios(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Usuario::class);

        $usuarios = Usuario::query()
            ->orderBy('nombre')
            ->get()
            ->map(fn (Usuario $usuario): array => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
                'email' => $usuario->email,
                'rol' => $usuario->rol->value,
                'activo' => $usuario->activo,
            ]);

        return response()->json(['usuarios' => $usuarios]);
    }

    /**
     * Cambia el rol de una persona.
     *
     * El rol decide que puede hacer en el almacen. Por eso esta pantalla
     * es de administracion y deja rastro en la auditoria.
     */
    public function cambiarRol(Request $request, Usuario $usuario): JsonResponse
    {
        Gate::authorize('cambiarRol', $usuario);

        $datos = $request->validate([
            'rol' => ['required', 'string', 'in:' . implode(',', array_column(\App\Enums\RolOperador::cases(), 'value'))],
            'activo' => ['nullable', 'boolean'],
        ], [], [
            'rol' => 'rol',
            'activo' => 'activo',
        ]);

        $usuario->fill([
            'rol' => $datos['rol'],
            ...(array_key_exists('activo', $datos) ? ['activo' => (bool) $datos['activo']] : []),
        ])->save();

        return response()->json([
            'id' => $usuario->id,
            'rol' => $usuario->rol->value,
            'activo' => $usuario->activo,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentarZona(Zona $zona): array
    {
        return [
            'id' => $zona->id,
            'codigo' => $zona->codigo,
            'nombre' => $zona->nombre,
            'descripcion' => $zona->descripcion,
            'pasillo_desde' => $zona->pasillo_desde,
            'pasillo_hasta' => $zona->pasillo_hasta,
            'activo' => $zona->activo,
            'ubicaciones' => $zona->ubicaciones_count ?? null,
        ];
    }
}