<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Tenancy\ContextoTenant;
use App\Support\Cantidad;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Concilia los saldos contra los movimientos.
 *
 * El saldo de una ubicacion DEBERIA ser la suma de los movimientos de esa
 * ubicacion. Este comando recomputa esa suma y la compara. Si algo no
 * cuadra, el problema no es "el numero esta mal": es que hay una fila de
 * `saldos` que cambio sin movimiento, y el gatillo deberia haberlo
 * impedido. Por eso sirve como auditoria de que la garantia se sostiene.
 *
 * No corrige nada. Que Corregirla a mano es justamente lo que este
 * sistema no quiere: la correccion es un movimiento con motivo.
 */
class AlmacenConciliar extends Command
{
    protected $signature = 'almacen:conciliar
                            {--empresa= : Limitar a una empresa (id).}
                            {--corregir : No hace nada por ahora. Se deja como marca para decidir con el jefe de almacen.}';

    protected $description = 'Compara el saldo de cada ubicacion con la suma de sus movimientos.';

    public function handle(ContextoTenant $contexto): int
    {
        $empresa = $this->option('empresa');

        $empresas = $contexto->tenantsActivos();

        if (is_string($empresa) && $empresa !== '') {
            $empresas = array_filter($empresas, fn (object $fila): bool => $fila->id === $empresa);
        }

        $descuadradas = 0;
        $revisadas = 0;

        foreach ($empresas as $fila) {
            $filas = $contexto->ejecutarAislado($fila->id, function (): array {
                return DB::select(<<<'SQL'
                    SELECT s.id AS saldo_id,
                           s.articulo_id,
                           s.ubicacion_id,
                           s.cantidad_fisica AS saldo,
                           COALESCE(mov.total, 0) AS movimientos
                      FROM saldos s
                      LEFT JOIN (
                            SELECT ubicacion_id, articulo_id, SUM(cantidad_base) AS total
                              FROM movimientos_stock
                             GROUP BY ubicacion_id, articulo_id
                           ) mov
                        ON mov.ubicacion_id = s.ubicacion_id
                       AND mov.articulo_id = s.articulo_id
                     ORDER BY s.ubicacion_id, s.articulo_id
                SQL);
            });

            $revisadas += count($filas);

            foreach ($filas as $saldo) {
                if (Cantidad::esCero(Cantidad::restar((string) $saldo->saldo, (string) $saldo->movimientos))) {
                    continue;
                }

                $descuadradas++;

                $this->error(sprintf(
                    'Descuadre en saldo %s: dice %.6f y sus movimientos suman %.6f.',
                    $saldo->saldo_id,
                    (float) $saldo->saldo,
                    (float) $saldo->movimientos
                ));
            }
        }

        if ($descuadradas === 0) {
            $this->info(sprintf('Conciliacion correcta: %d saldos coinciden con sus movimientos.', $revisadas));

            return self::SUCCESS;
        }

        $this->error(sprintf(
            '%d de %d saldos no coinciden con sus movimientos. Investigue antes de seguir operando.',
            $descuadradas,
            $revisadas
        ));

        return self::FAILURE;
    }
}