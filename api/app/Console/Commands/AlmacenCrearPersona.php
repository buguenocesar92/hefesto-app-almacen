<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\RolOperador;
use App\Models\Tenant;
use App\Models\Usuario;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Console\Command;

/**
 * Da de alta una persona del almacen.
 *
 * POR QUE ES UN COMANDO Y NO UN FORMULARIO
 * ---------------------------------------
 * Porque hace falta una persona autorizada para entrar antes de que exista
 * alguien que pueda autorizar el acceso a la interfaz. Es el arranque en
 * frio del sistema: el administrador primero, y el resto por la pantalla de
 * administración.
 *
 * El correo es la llave con que se cruza con Authentik. Si aquí se escribe
 * `jefe@empresa.cl` y en Authentik la persona es `jefe@empresa.cl`, entra. Si
 * no coinciden, la API rechaza el acceso y lo deja escrito en el log.
 */
class AlmacenCrearPersona extends Command
{
    protected $signature = 'almacen:crear-persona
                            {--empresa= : Identificador (slug) de la empresa. Por defecto, la primera activa.}
                            {--email= : Correo de la persona. Debe coincidir con Authentik.}
                            {--nombre= : Nombre completo.}
                            {--rol= : Rol: auditor, preparador, jefe_almacen o administrador.}
                            {--inactivo : Crear la persona desactivada.}';

    protected $description = 'Da de alta una persona del almacen. El correo debe coincidir con el de Authentik.';

    public function handle(ContextoTenant $contexto): int
    {
        $correo = mb_strtolower(trim((string) $this->option('email')));

        if ($correo === '' || ! str_contains($correo, '@')) {
            $this->error('Falta --email con un correo valido.');

            return self::FAILURE;
        }

        $nombre = trim((string) $this->option('nombre'));

        if ($nombre === '') {
            $this->error('Falta --nombre.');

            return self::FAILURE;
        }

        $rol = RolOperador::tryFrom((string) $this->option('rol')) ?? RolOperador::PREPARADOR;

        // `tenants` no tiene RLS: se puede leer sin contexto.
        $empresa = $this->resolverEmpresa();

        if ($empresa === null) {
            return self::FAILURE;
        }

        $creada = $contexto->ejecutarAislado($empresa->id, function () use ($empresa, $correo, $nombre, $rol): bool {
            $existente = Usuario::query()
                ->where('email', $correo)
                ->where('tenant_id', $empresa->id)
                ->first();

            if ($existente !== null) {
                $this->warn(sprintf('La persona %s ya existe en %s.', $correo, $empresa->nombre));

                return false;
            }

            Usuario::query()->create([
                'tenant_id' => $empresa->id,
                'email' => $correo,
                'nombre' => $nombre,
                'rol' => $rol,
                'activo' => ! $this->option('inactivo'),
            ]);

            return true;
        });

        if ($creada) {
            $this->info(sprintf('Persona creada: %s (%s) en %s.', $correo, $rol->value, $empresa->nombre));
            $this->line('Ahora debe existir en Authentik con ese mismo correo para poder entrar.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    private function resolverEmpresa(): ?Tenant
    {
        $slug = $this->option('empresa');

        if (is_string($slug) && $slug !== '') {
            $empresa = Tenant::query()->where('slug', $slug)->first();

            if ($empresa === null) {
                $this->error(sprintf('No existe ninguna empresa con el identificador %s.', $slug));
            }

            return $empresa;
        }

        $empresa = Tenant::query()->where('activo', true)->orderBy('created_at')->first();

        if ($empresa === null) {
            $this->error('No hay ninguna empresa activa. Cree una primero o indique --empresa.');
        }

        return $empresa;
    }
}