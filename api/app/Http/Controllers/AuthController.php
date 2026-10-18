<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Auth\DescubridorOidc;
use App\Services\Auth\VerificadorIdToken;
use App\Services\Auth\VinculadorDeIdentidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

/**
 * Acceso y cierre de sesion con Authentik.
 *
 * El almacen no guarda contrasenas. Authentik dice quien entra y esta API
 * decide que puede hacer con ese ingreso, leyendo el rol de la tabla
 * `usuarios`.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly VerificadorIdToken $verificador,
        private readonly VinculadorDeIdentidad $vinculador,
        private readonly DescubridorOidc $descubridor,
    ) {}

    /** Empieza el acceso: manda a Authentik. */
    public function entrar(): RedirectResponse
    {
        return Socialite::driver('authentik')->redirect();
    }

    /** Callback de Authentik: verifica el token y abre sesion. */
    public function callback(Request $request): RedirectResponse|JsonResponse
    {
        try {
            $claims = $this->verificador->verificar((string) $request->query('token', ''));

            $persona = $this->verificador->datosDePersona($claims);

            $usuario = $this->vinculador->vincular($persona['correo'], $claims);

            if ($usuario === null) {
                Log::warning('acceso.rechazado', [
                    'correo' => $persona['correo'],
                    'grupos' => $persona['grupos'],
                ]);

                return response()->json([
                    'mensaje' => 'Esta cuenta no esta autorizada para el almacen.',
                ], 403);
            }

            // El token ya se verifico de forma criptografica; la sesion de
            // Laravel es local y es la que autoriza cada peticion.
            Auth::login($usuario, remember: true);

            $request->session()->regenerate();

            return redirect()->intended(config('app.web_url', '/'));
        } catch (\Throwable $error) {
            Log::error('acceso.error', ['error' => $error->getMessage()]);

            return response()->json([
                'mensaje' => 'No se pudo completar el acceso.',
            ], 401);
        }
    }

    /** Cierra la sesion local y, si Authentik lo pide, tambien la suya. */
    public function salir(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $salidaAuthentik = $this->descubridor->urlDeSalida();

        if ($salidaAuthentik === null) {
            return redirect()->to(config('app.web_url', '/'));
        }

        return redirect()->away($salidaAuthentik);
    }

    /** Quien es la persona que esta operando. Lo usa la interfaz. */
    public function yo(Request $request): JsonResponse
    {
        $usuario = $request->user();

        if ($usuario === null) {
            return response()->json(['mensaje' => 'Sin sesion.'], 401);
        }

        return response()->json([
            'id' => $usuario->id,
            'nombre' => $usuario->nombre,
            'email' => $usuario->email,
            'rol' => $usuario->rol->value,
            'empresa' => $usuario->empresa?->nombre,
            'puede_anular' => $usuario->rol->puedeAnularDocumentos(),
            'puede_cerrar_conteos' => $usuario->rol->puedeCerrarConteos(),
            'puede_administrar' => $usuario->rol->puedeAdministrarCatalogos(),
        ]);
    }

    /**
     * Estado del servicio de identidad, para diagnostico.
     *
     * No dice si un token es valido: dice si el almacen alcanza a hablar
     * con Authentik. Cuando hay un problema de acceso, esto es lo primero
     * que hay que mirar.
     */
    public function diagnostico(): JsonResponse
    {
        return response()->json([
            'authentik' => $this->descubridor->diagnostico(),
        ]);
    }
}