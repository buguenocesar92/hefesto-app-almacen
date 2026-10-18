<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Punto de entrada de las semillas.
 *
 * `db:seed` siembra el almacen de ejemplo completo. Ver docs/06 para el
 * recorrido guiado.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AlmacenEjemploSeeder::class);
    }
}