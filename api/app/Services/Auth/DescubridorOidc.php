<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Descubre el proveedor y trae el JWKS de Authentik.
 *
 * El documento de descubrimiento dice donde esta el JWKS y que algoritmos
 * admite. Se lee una vez y se cachea en Redis: en cada peticion no se
 * vuelve a pedir el mismo archivo de configuracion a otro servicio.
 *
 * El JWKS se cachea mas tiempo, porque las llaves cambian pocas veces y
 * cada cambio es un problema si la cache está mala. Cuando el token no
 * valida por firma, el controlador borra la cache y reintenta una vez:
 * asi una llave rotada no deja a nadie fuera del almacen.
 */
class DescubridorOidc
{
    /** El documento de descubrimiento cambia muy pocas veces. */
    private const TTL_DESCUBRIMIENTO = 3600;

    /** El JWKS dura mas, pero se refresca si la firma no calza. */
    private const TTL_JWKS = 86400;

    public function __construct(
        private readonly string $issuer,
        private readonly string $clientId,
        private readonly string $algoritmo,
    ) {}

    /** @return array<string, mixed> */
    public function descubrimiento(): array
    {
        return Cache::remember(
            'almacen:oidc:descubrimiento:' . hash('sha256', $this->issuer),
            self::TTL_DESCUBRIMIENTO,
            function (): array {
                $respuesta = Http::timeout(5)->get(rtrim($this->issuer, '/') . '/.well-known/openid-configuration');

                if (! $respuesta->successful()) {
                    throw new RuntimeException(
                        'Authentik no respondio el documento de descubrimiento: ' . $respuesta->status()
                    );
                }

                return (array) $respuesta->json();
            }
        );
    }

    public function urlJwks(): string
    {
        $descubrimiento = $this->descubrimiento();

        if (! isset($descubrimiento['jwks_uri'])) {
            throw new RuntimeException('El documento de descubrimiento de Authentik no trae jwks_uri.');
        }

        return (string) $descubrimiento['jwks_uri'];
    }

    /**
     * Llaves publicas para verificar la firma del token.
     *
     * @param  bool  $forzar  Descarta la cache. Se usa cuando la firma no
     *                         calza: probablemente la llave roto.
     * @return array<int, array<string, mixed>>
     */
    public function llaves(bool $forzar = false): array
    {
        $clave = 'almacen:oidc:jwks:' . hash('sha256', $this->urlJwks());

        if ($forzar) {
            Cache::forget($clave);
        }

        return Cache::remember($clave, self::TTL_JWKS, function (): array {
            $respuesta = Http::timeout(5)->get($this->urlJwks());

            if (! $respuesta->successful()) {
                throw new RuntimeException(
                    'Authentik no respondio el JWKS: ' . $respuesta->status()
                );
            }

            return (array) ($respuesta->json()['keys'] ?? []);
        });
    }

    /** URL a la que se manda a la persona para iniciar sesion. */
    public function urlDeAcceso(): string
    {
        return $this->descubrimiento()['authorization_endpoint'] ?? $this->issuer . '/application/o/authorize/';
    }

    public function urlDeSalida(): ?string
    {
        return $this->descubrimiento()['end_session_endpoint'] ?? null;
    }

    public function algoritmo(): string
    {
        return $this->algoritmo;
    }

    public function clienteId(): string
    {
        return $this->clientId;
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    /** Datos minimos para diagnosticar un problema de acceso. */
    public function diagnostico(): array
    {
        try {
            $descubrimiento = $this->descubrimiento();

            return [
                'issuer' => $this->issuer,
                'jwks' => $this->urlJwks(),
                'autoriza' => $descubrimiento['authorization_endpoint'] ?? null,
            ];
        } catch (\Throwable $error) {
            Log::error('oidc.diagnostico', ['error' => $error->getMessage()]);

            return ['issuer' => $this->issuer, 'error' => $error->getMessage()];
        }
    }
}