<?php

declare(strict_types=1);

use App\Console\Commands\AlmacenConciliar;
use App\Console\Commands\AlmacenLiberarReservasVencidas;
use App\Console\Commands\AlmacenVerificarAislamiento;
use App\Jobs\LiberarReservasVencidasJob;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
|
| Las reservas vencidas se liberan solas. Es la garantia que evita que el
| almacen prometa mercaderia comprometida por un pedido que nadie confirmo.
|
| `withoutOverlapping()` evita que dos pasadas se pisen: si el almacen esta
| lento, la segunda espera en vez de liberar dos veces la misma unidad.
|
*/

Schedule::command(AlmacenLiberarReservasVencidas::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::job(new LiberarReservasVencidasJob())
    ->hourlyAt(7)
    ->name('almacen:libera-reservas-vencidas')
    ->withoutOverlapping();

/*
| La conciliacion se deja para las 3 de la mañana, cuando no hay operarios.
| Si encuentra un descuadre, manda una alerta: significa que hay una fila de
| saldos que cambio sin movimiento, y eso deberia ser imposible.
*/
Schedule::command(AlmacenConciliar::class)
    ->dailyAt('03:00')
    ->name('almacen:concilia-saldos')
    ->withoutOverlapping();

/*
| La verificacion del aislamiento corre en cada despliegue. Si falla, el
| despliegue se detiene: es la ultima linea entre una empresa y los datos
| de otra.
*/
Schedule::command(AlmacenVerificarAislamiento::class)
    ->hourly()
    ->name('almacen:verifica-aislamiento');