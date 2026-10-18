<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Salud del servicio.
 *
 * Lo usa el orquestador (docker compose) y sirve para responder "esta caido
 * el almacen?" sin tener que entrar por la interfaz. No se autentica a
 * proposito: un chequeo de salud no puede necesitar credenciales, o nadie
 * lo podria correr justo cuando hace falta.
 *
 * Por eso NO dice nada de los datos: ni cuantas empresas hay, ni cuanta
 * mercaderia. Solo si las piezas de abajo contestan. Lo que este endpoint
 * no puede hacer es filtrar informacion de negocio.
 */
class SaludController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $componentes = [
            'base' => $this->estadoBase(),
            'cache' => $this->estadoRedis(),
        ];

        $todoBien = ! in_array(false, array_column($componentes, 'ok'), true);

        return response()->json([
            'estado' => $todoBien ? 'ok' : 'degradado',
            'componentes' => $componentes,
            'momento' => now()->toIso8601String(),
        ], $todoBien ? 200 : 503);
    }

    /**
     * La base tiene que contestar Y la funcion de contexto de empresa tiene
     * que existir. Si la RLS se aplico a medias, el almacen esta de pie
     * pero sin aislamiento: eso es peor que estar caido, asi que se
     * reporta como degradado.
     *
     * @return array{ok: bool, detalle: string}
     */
    private function estadoBase(): array
    {
        try {
            DB::select('SELECT 1');
        } catch (Throwable $error) {
            return ['ok' => false, 'detalle' => 'La base no responde: ' . $error->getMessage()];
        }

        try {
            $existe = DB::selectOne("SELECT to_regprocedure('public.app_tenant_id()') AS funcion");

            if ($existe === null || $existe->funcion === null) {
                return [
                    'ok' => false,
                    'detalle' => 'La funcion de contexto de empresa no existe: falta aplicar la migracion de Row Level Security.',
                ];
            }
        } catch (Throwable $error) {
            return ['ok' => false, 'detalle' => 'No se pudo verificar la RLS: ' . $error->getMessage()];
        }

        return ['ok' => true, 'detalle' => 'Base accesible con RLS aplicada.'];
    }

    /**
     * Redis sostiene la cache y las colas. Sin el, la API sigue
     * respondiendo pero sin reservas vencidas liberadas: es degradado,
     * no caido.
     *
     * @return array{ok: bool, detalle: string}
     */
    private function estadoRedis(): array
    {
        try {
            Redis::connection()->ping();

            return ['ok' => true, 'detalle' => 'Cache y colas operativas.'];
        } catch (Throwable $error) {
            return [
                'ok' => false,
                'detalle' => 'Redis no responde. La API contesta, pero las reservas vencidas NO se liberan solas: ' . $error->getMessage(),
            ];
        }
    }
}