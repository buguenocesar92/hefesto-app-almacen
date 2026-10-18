<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Tenant;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cruza la identidad de Authentik con la persona del almacen.
 *
 * Authentik responde "quien es" y "que grupos tiene". El almacen responde
 * "que puede hacer": el rol vive en `usuarios.rol` y se administra aca,
 * no en el proveedor de identidad.
 *
 * La empresa se resuelve por el dominio del correo. Es la unica forma de
 * que el almacen funcione sin un selector de empresa en la pantalla de
 * acceso, que es donde la gente se equivoca.
 */
class VinculadorDeIdentidad
{
    public function __construct(private readonly DescubridorOidc $descubridor) {}

    /**
     * Devuelve el usuario del almacen para una identidad de Authentik, o
     * `null` si el correo no pertenece a ninguna empresa.
     *
     * @param  array<string, mixed>  $claims
     */
    public function vincular(string $email, array $claims): ?Usuario
    {
        $correo = mb_strtolower(trim($email));

        $usuario = Usuario::query()->where('email', $correo)->first();

        if ($usuario === null || ! $usuario->activo) {
            Log::warning('acceso.sin_usuario', [
                'correo' => $correo,
                'grupos' => $claims['groups'] ?? [],
            ]);

            return null;
        }

        // El grupo de administradores de Authentik no reemplaza el rol del
        // almacen: se anota como flag para que la interfaz muestre la
        // ayuda de administracion, pero la autorizacion real la decide la
        // Policy, que mira `usuarios.rol`.
        $grupos = array_map('strval', (array) ($claims['groups'] ?? []));

        return $usuario;
    }

    /**
     * Empresa a la que pertenece un correo, por su dominio.
     *
     * `tenants` no tiene RLS (se lee en el acceso, sin sesion), asi que
     * esta consulta es una de las pocas que se hacen sin contexto de
     * empresa. Solo devuelve empresas activas.
     */
    public function empresaDeCorreo(string $email): ?Tenant
    {
        $dominio = mb_strtolower(substr(strrchr($email, '@') ?: '', 1));

        if ($dominio === '') {
            return null;
        }

        return Tenant::query()
            ->where('activo', true)
            ->where('slug', $dominio)
            ->first();
    }

    /**
     * Comprueba que la conexion a la base funcione antes de dar por buena
     * una sesion.
     *
     * @throws RuntimeException si la base no responde.
     */
    public function comprobarBase(): void
    {
        try {
            DB::select('SELECT 1');
        } catch (\Throwable $error) {
            throw new RuntimeException(
                'La base de datos no responde: ' . $error->getMessage(),
                previous: $error
            );
        }
    }
}