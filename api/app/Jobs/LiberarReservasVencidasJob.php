<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Reservas\GestorDeReservas;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Trabajo en cola que libera reservas vencidas.
 *
 * El comando `almacen:liberar-reservas-vencidas` hace lo mismo y se usa
 * desde cron. Este trabajo existe para que el almacen no dependa de que
 * alguien tenga configurado el cron.
 *
 * IMPORTANTE SOBRE EL CONTEXTO DE EMPRESA
 * --------------------------------------
 * El worker de colas REUTILIZA la misma conexion para trabajos de empresas
 * distintas. Por eso el contexto viaja con `set_config(..., true)`, o sea
 * con alcance de transaccion: al terminar este trabajo, PostgreSQL lo
 * descarta. Con un `SET` de sesion, este trabajo podria dejar el
 * contexto de la empresa anterior colgado y el siguiente leeria sus
 * datos. Es el mismo motivo por el que existe
 * `ContextoTenant::establecer()` y por lo que exige transaccion.
 *
 * El trabajo NO lleva empresa en su nombre a proposito: recorre todas las
 * activas y abre una transaccion propia por cada una, de modo que el
 * contexto de una no pueda filtrarse a la siguiente.
 */
class LiberarReservasVencidasJob implements ShouldQueue
{
    use Queueable;

    /** Numero de intentos antes de rendirse. */
    public int $tries = 3;

    public function __construct(public readonly ?string $soloEmpresa = null) {}

    public function handle(GestorDeReservas $gestor, ContextoTenant $contexto): void
    {
        $empresas = $contexto->tenantsActivos();

        if ($this->soloEmpresa !== null) {
            $empresas = array_values(array_filter(
                $empresas,
                fn (object $fila): bool => $fila->id === $this->soloEmpresa
            ));
        }

        foreach ($empresas as $fila) {
            // Una transaccion por empresa: si una falla, el contexto de la
            // anterior ya se descarta y la siguiente parte limpio.
            $contexto->ejecutarAislado($fila->id, function () use ($gestor): void {
                $gestor->vencer();
            });
        }
    }
}