<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Proveedor de identidad (Authentik)
    |--------------------------------------------------------------------------
    |
    | El almacen no maneja contrasenas: Authentik resuelve la identidad y la
    | API solo verifica el token y decide el rol. Ver docs/02-AUTENTICACION.
    |
    | El issuer debe terminar en barra: Authentik lo publica asi y una
    | diferencia de una barra hace que todos los tokens parezcan de otro
    | emisor.
    |
    */

    'issuer' => rtrim((string) env('AUTHENTIK_ISSUER', ''), '/') . '/',

    'client_id' => (string) env('AUTHENTIK_CLIENT_ID', ''),

    'client_secret' => (string) env('AUTHENTIK_CLIENT_SECRET', ''),

    'redirect_uri' => (string) env('AUTHENTIK_REDIRECT_URI', ''),

    'scopes' => (string) env('AUTHENTIK_SCOPES', 'openid profile email'),

    // Algoritmo de firma. RS256 es el que usa Authentik por omision.
    'algoritmo' => (string) env('AUTHENTIK_ALGORITMO', 'RS256'),

    /*
    | Grupo de Authentik que corresponde al administrador del almacen.
    |
    | Ojo: este grupo NO da permisos por si solo. El rol con el que se
    | autoriza cada accion vive en `usuarios.rol`, que es del almacen. El
    | grupo sirve para mostrar ayuda de administracion y para que el alta
    | de personas sea auditable en el proveedor de identidad.
    */
    'grupo_administradores' => (string) env('AUTHENTIK_GRUPO_ADMIN', 'almacen-administradores'),

    /*
    | Grupo que agrupa a todo el personal autorizado a entrar al almacen.
    | Si una persona no esta en este grupo, el acceso se rechaza aunque
    | exista en la base: la base dice que trabaja aca, Authentik que ya no
    | es de la empresa.
    */
    'grupo_operadores' => (string) env('AUTHENTIK_GRUPO_OPERADORES', 'almacen-operadores'),

    /*
    | Sesion: en que almacenamiento queda la cookie. `database` es el
    | clasico de Laravel y permite revocar una sesion desde la base si
    | alguien pierde el equipo.
    */
    'sesion' => [
        'driver' => (string) env('SESSION_DRIVER', 'database'),
        'nombre_cookie' => (string) env('SESSION_COOKIE', 'almacen_sesion'),
    ],

];