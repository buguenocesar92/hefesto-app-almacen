<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reservas\GestorDeReservas;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Console\Command;

/**
 * Devuelve a disponible las reservas vencidas.
 *
 * El error caro de un almacen es prometer stock que ya salio. Este comando
 * es el que hace que una reserva que nadie tomo por plazo deje de
 * comprometer mercaderia para siempre.
 *
 * Corre empresa por empresa, cada una en su propia transaccion con su
 * contexto: si una empresa falla, no se mezclan los datos ni se detiene el
 * trabajo de las demas. El contexto de empresa viaja con alcance de
 * transaccion justamente para este caso: el worker reutiliza la conexion.
 */
class AlmacenLiberarReservasVencidas extends Command
{
    protected $signature = 'almacen:liberar-reservas-vencidas
                            {--empresa= : Limitar a una empresa (id). Para depurar.}';

    protected $description = 'Libera las reservas cuya fecha de vencimiento ya paso y devuelve las unidades a disponible.';

    public function handle(GestorDeReservas $gestor, ContextoTenant $contexto): int
    {
        $empresa = $this->option('empresa');

        $empresas = $contexto->tenantsActivos();

        if (is_string($empresa) && $empresa !== '') {
            $empresas = array_filter($empresas, fn (object $fila): bool => $fila->id === $empresa);
        }

        if ($empresas === []) {
            $this->warn('No hay empresas activas que revisar.');

            return self::SUCCESS;
        }

        $total = 0;
        $fallos = 0;

        foreach ($empresas as $fila) {
            try {
                $liberadas = $contexto->ejecutarAislado(
                    $fila->id,
                    fn (): int => $gestor->vencer()
                );

                $total += $liberadas;

                if ($liberadas > 0) {
                    $this->info(sprintf('Empresa %s: %d reservas vencidas liberadas.', $fila->id, $liberadas));
                }
            } catch (\Throwable $error) {
                $fallos++;
                $this->error(sprintf('Empresa %s: %s', $fila->id, $error->getMessage()));
            }
        }

        $this->info(sprintf('Total: %d reservas liberadas en %d empresas.', $total, count($empresas) - $fallos));

        return $fallos > 0 ? self::FAILURE : self::SUCCESS;
    }
}