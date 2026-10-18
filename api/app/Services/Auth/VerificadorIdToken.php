<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Verifica el token de identidad que entrega Authentik.
 *
 * Tres cosas se comprueban, en este orden:
 *
 *  1. LA FIRMA, contra las llaves publicas del JWKS. Si no calza, se
 *     descarta la cache del JWKS y se reintenta una vez: casi siempre
 *     es que Authentik roto una llave y el almacen entero quedaria
 *     afuera sin una segunda oportunidad.
 *  2. EL EMISOR (`iss`), para que un token emitido por otro servicio no
 *     se aceche en este almacen.
 *  3. LA AUDIENCIA (`aud`), que tiene que ser esta aplicacion.
 *
 * El tiempo de vida del token lo verifica la libreria, que ademas usa el
 * margen de reloj que le entrega el servidor y no el de esta maquina.
 */
class VerificadorIdToken
{
    public function __construct(private readonly DescubridorOidc $descubridor) {}

    /**
     * @return array<string, mixed> Claims verificados.
     *
     * @throws RuntimeException si el token no es valido.
     */
    public function verificar(string $token): array
    {
        try {
            return $this->decodificar($token, forzarLlaves: false);
        } catch (Throwable $error) {
            // Un solo reintento con el JWKS recien bajado. Si vuelve a
            // fallar, el token esta malo de verdad.
            try {
                return $this->decodificar($token, forzarLlaves: true);
            } catch (Throwable) {
                throw new RuntimeException('El token de identidad no es valido: ' . $error->getMessage(), previous: $error);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodificar(string $token, bool $forzarLlaves): array
    {
        $llaves = JWK::parseKeySet(
            $this->descubridor->llaves($forzarLlaves),
            $this->descubridor->algoritmo()
        );

        $decodificado = JWT::decode($token, $llaves);

        $claims = (array) $decodificado;

        $this->verificarEmisor($claims);
        $this->verificarAudiencia($claims);

        return $claims;
    }

    /** @param array<string, mixed> $claims */
    private function verificarEmisor(array $claims): void
    {
        $esperado = $this->descubridor->issuer();
        $recibido = (string) ($claims['iss'] ?? '');

        if ($recibido === '') {
            throw new RuntimeException('El token no trae emisor.');
        }

        // Se comparan sin la barra final, que cambia entre configuraciones
        // equivalentes y haria fallar un token valido.
        if (rtrim($recibido, '/') !== rtrim($esperado, '/')) {
            throw new RuntimeException('El token fue emitido por otro servicio.');
        }
    }

    /** @param array<string, mixed> $claims */
    private function verificarAudiencia(array $claims): void
    {
        $audiencia = $claims['aud'] ?? null;
        $esperado = $this->descubridor->clienteId();

        $audiencias = is_array($audiencia) ? $audiencia : [$audiencia];

        $coincide = false;

        foreach ($audiencias as $valor) {
            if ((string) $valor === $esperado) {
                $coincide = true;
                break;
            }
        }

        if (! $coincide) {
            throw new RuntimeException('El token no fue emitido para esta aplicacion.');
        }
    }

    /** Datos de la persona, en la forma que espera el vinculador. */
    public function datosDePersona(array $claims): array
    {
        return [
            'correo' => (string) ($claims['email'] ?? ''),
            'nombre' => (string) ($claims['name'] ?? $claims['preferred_username'] ?? ''),
            'grupos' => array_map('strval', (array) ($claims['groups'] ?? [])),
            'expira' => isset($claims['exp']) ? Carbon::createFromTimestamp((int) $claims['exp']) : null,
        ];
    }
}