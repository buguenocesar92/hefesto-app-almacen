<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\ContextoTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija la empresa de la peticion para que la RLS de PostgreSQL sepa de
 * quien son las filas.
 *
 * Se aplica a todo el grupo `web` (ver bootstrap/app.php), ANTES que el
 * middleware `auth` de la ruta. Por eso no puede usar `$request->user()`:
 * resuelve el usuario por su cuenta con el guard `web`, que ya lee la
 * sesion.
 *
 * Peticiones sin sesion (acceso, callback, salud) pasan sin transaccion.
 * Si esas rutas tocaran una tabla protegida, `public.app_tenant_id()`
 * lanzaria excepcion y la peticion fallaria: el aislamiento falla
 * cerrado, nunca abierto.
 */
class ResolverTenant
{
    public function __construct(private readonly ContextoTenant $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = auth()->guard('web')->user();

        if ($usuario === null) {
            return $next($request);
        }

        if ($usuario->tenant_id === null) {
            // Una cuenta sin empresa no puede operar. Se corta antes de
            // tocar la base de datos.
            abort(403, 'La cuenta no esta asociada a ninguna empresa.');
        }

        if (! $usuario->activo) {
            abort(403, 'La cuenta esta desactivada.');
        }

        return $this->contexto->ejecutarAislado(
            $usuario->tenant_id,
            static fn () => $next($request)
        );
    }
}