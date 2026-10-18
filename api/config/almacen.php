<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Reservas
    |--------------------------------------------------------------------------
    |
    | Cuanto tiempo queda comprometida la mercaderia antes de volver a estar
    | disponible. Es el plazo que evita que un pedido olvidado bloquee el
    | stock para siempre.
    |
    | El valor se puede cambiar por empresa si hizo falta, pero aca hay un
    | solo numero para toda la instalacion: es mas facil explicar "la
    | reserva dura 72 horas" que "dura lo que diga cada empresa".
    |
    */

    'reservas' => [
        'horas_validez' => (int) env('ALMACEN_HORAS_VALIDEZ_RESERVA', 72),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conteo ciclico
    |--------------------------------------------------------------------------
    |
    | Dias que se recommends esperar entre dos conteos de la misma zona.
    | El sistema no bloquea el conteo por falta de tiempo: avisa, nada
    | mas. Bloquear seria inventar una regla de negocio que es del jefe
    | de almacen, no del software.
    |
    */

    'conteo' => [
        'dias_minimos_entre_conteos' => (int) env('ALMACEN_DIAS_ENTRE_CONTEO', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Picking por oleadas
    |--------------------------------------------------------------------------
    |
    | `paradas_maximas` limita el tamano de una oleada. Una vuelta con
    | demasiadas paradas se vuelve un brazo muerto de operario y el papel
    | de picking deja de caber arriba del canasto.
    |
    */

    'picking' => [
        'paradas_maximas' => (int) env('ALMACEN_PARADAS_MAXIMAS_POR_OLEADA', 120),
    ],

];