<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comprueba que el aislamiento entre empresas este puesto de verdad.
 *
 * Se corre en el despliegue y en cada arranque (el entrypoint lo invoca y
 * avisa si falla). Comprueba cuatro cosas:
 *
 *  1. Que el rol de la base NO sea superusuario ni tenga BYPASSRLS. Con
 *     cualquiera de las dos, PostgreSQL ignora las politicas por completo
 *     y el aislamiento seria decorativo.
 *  2. Que todas las tablas de negocio tengan RLS habilitado y forzado, y
 *     su politica con `USING` y `WITH CHECK`.
 *  3. Que la funcion de contexto siga fallando cerrada: sin `app.tenant_id`
 *     una consulta tiene que reventar, no devolver cero filas en silencio.
 *  4. Que el gatillo de saldos siga puesto: el stock no se puede editar
 *     sin movimiento.
 */
class AlmacenVerificarAislamiento extends Command
{
    protected $signature = 'almacen:verificar-aislamiento';

    protected $description = 'Verifica que el aislamiento entre empresas y la inmutabilidad del stock esten puestos.';

    public function handle(): int
    {
        $problemas = [];

        $problemas = array_merge($problemas, $this->verificarRol());
        $problemas = array_merge($problemas, $this->verificarPoliticas());
        $problemas = array_merge($problemas, $this->verificarContextoCerrado());
        $problemas = array_merge($problemas, $this->verificarGatilloDeSaldos());

        if ($problemas === []) {
            $this->info('Aislamiento verificado: RLS activa, contexto cerrado y stock inmutable.');

            return self::SUCCESS;
        }

        foreach ($problemas as $problema) {
            $this->error($problema);
        }

        $this->error(sprintf('Se encontraron %d problemas. No reciba personas hasta corregirlos.', count($problemas)));

        return self::FAILURE;
    }

    /** @return list<string> */
    private function verificarRol(): array
    {
        $fila = DB::selectOne(
            'SELECT current_setting(''is_superuser'') AS superusuario, rolbypassrls AS bypass_rls FROM pg_roles WHERE rolname = current_user'
        );

        if ($fila === null) {
            return ['No se pudo leer el rol actual de PostgreSQL.'];
        }

        $problemas = [];

        if ($fila->superusuario === 'on') {
            $problemas[] = 'El rol de la aplicacion es SUPERUSUARIO: PostgreSQL ignora todas las politicas RLS.';
        }

        if ($fila->bypass_rls === true) {
            $problemas[] = 'El rol de la aplicacion tiene BYPASSRLS: ignora todas las politicas RLS.';
        }

        return $problemas;
    }

    /** @return list<string> */
    private function verificarPoliticas(): array
    {
        $tablas = DB::select(
            "SELECT c.relname AS tabla
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE n.nspname = 'public'
                AND c.relkind = 'r'
                AND c.relname NOT IN ('migrations', 'jobs', 'job_batches', 'cache', 'cache_locks', 'sessions', 'password_reset_tokens')
              ORDER BY c.relname"
        );

        $problemas = [];

        foreach ($tablas as $fila) {
            $nombre = $fila->tabla;

            if ($nombre === 'tenants') {
                // Excepcion consciente: se lee en el acceso, sin sesion.
                continue;
            }

            $estado = DB::selectOne(
                'SELECT relrowsecurity AS rls, relforcerowsecurity AS forzado FROM pg_class WHERE relname = ? AND relnamespace = ''public''::regnamespace',
                [$nombre]
            );

            if ($estado === null) {
                continue;
            }

            if ($estado->rls !== true) {
                $problemas[] = sprintf('La tabla %s no tiene Row Level Security habilitado.', $nombre);
                continue;
            }

            if ($estado->forzado !== true) {
                $problemas[] = sprintf('La tabla %s no tiene FORCE ROW LEVEL SECURITY: el dueno saltaria la politica.', $nombre);
            }

            $politica = DB::selectOne(
                "SELECT polcmd, qual, with_check
                   FROM pg_policies
                  WHERE schemaname = 'public' AND tablename = ? AND policyname = ?",
                [$nombre, 'politica_aislamiento_' . $nombre]
            );

            if ($politica === null) {
                $problemas[] = sprintf('La tabla %s no tiene la politica politica_aislamiento_%s.', $nombre, $nombre);
                continue;
            }

            if ($politica->qual === null || $politica->with_check === null) {
                $problemas[] = sprintf(
                    'La politica de %s no tiene USING ni WITH CHECK completos: %s',
                    $nombre,
                    $politica->polcmd
                );
            }
        }

        return $problemas;
    }

    /** @return list<string> */
    private function verificarContextoCerrado(): array
    {
        $problemas = [];

        // Se limpia el contexto a proposito: es la situacion de una consulta
        // que se escapo del middleware.
        DB::select('SELECT set_config(?, ?, false)', ['app.tenant_id', '']);

        try {
            DB::select('SELECT public.app_tenant_id()');
            $problemas[] = 'La funcion de contexto de empresa NO falla cuando no hay contexto: el aislamiento falla abierto.';
        } catch (\Throwable) {
            // Esto es lo correcto: la funcion tiene que reventar.
        }

        DB::select('SELECT set_config(?, ?, false)', ['app.tenant_id', '']);

        return $problemas;
    }

    /** @return list<string> */
    private function verificarGatilloDeSaldos(): array
    {
        $existe = DB::selectOne(
            "SELECT tgname
               FROM pg_trigger
              WHERE tgrelid = 'public.saldos'::regclass
                AND NOT tgisinternal"
        );

        if ($existe === null) {
            return [
                'La tabla saldos no tiene el gatillo que impide editar el stock sin movimiento. '
                . 'Revise la migracion 000006.',
            ];
        }

        return [];
    }
}