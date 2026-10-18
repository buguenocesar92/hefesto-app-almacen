# 10 - Pruebas y estado

Este documento separa lo probado de lo no probado. Con todas las letras,
porque en un almacén una suposición no probada termina en mercadería perdida.

---

## 1. Estado general

**CÓDIGO ESCRITO, SIN EJECUTAR.**

En el equipo donde se escribió esta aplicación **no había PHP, ni Composer,
ni Docker, ni PostgreSQL, ni Redis**. No se ha ejecutado:

- ni una migración,
- ni una prueba,
- ni un arranque de servidor,
- ni una petición a la API,
- ni una restauración de respaldo.

Ninguna afirmación de este proyecto dice que algo haya funcionado. Las
versiones de paquetes e imágenes **sí** se verificaron contra los registros
oficiales (sección 4).

Una advertencia sobre lo que sigue: al no haber PHP en esta máquina, el
código **no se interpretó ni una vez**. Los errores de sintaxis o de tipos
que pudiera tener quedan dentro de los archivos y no se detectaron. Por eso
lo primero que hay que hacer, en un entorno con PHP, es
`php artisan --version`.

Una advertencia sobre lo que sigue: al no haber PHP en esta máquina, el
código **no se interpretó ni una vez**. Los errores de sintaxis o de tipos
que pudiera tener quedan dentro de los archivos y no se detectaron. Por eso
lo primero que hay que hacer, en un entorno con PHP, es
`php artisan --version`.

---

## 2. Lo que sí se hizo de verdad en esta sesión

| Comprobación | Resultado |
|---|---|
| Los tres scripts `.sh` validados con `bash -n` (Git Bash 5.x) | **Pasan los tres**: `entrypoint.sh`, `respaldo.sh`, `restauracion.sh` |
| Búsqueda de caracteres CJK y cirílicos en todo el árbol de `apps/almacen/` | Ejecutada en dos pasadas (ver sección 7) |
| Versiones de paquetes e imágenes verificadas contra los registros oficiales | Ver sección 4 |
| Comprobación de que todo quedó dentro de `apps/almacen/` | Ejecutada (ver sección 8) |
| Revisión manual de imports sin usar y de referencias rotas | Ejecutada, con las salvedades de la sección 7 |

**Nada más se ejecutó.** En particular, el PHP no se interpretó ni una vez:
no hay `php` en esta máquina, así que **los errores de sintaxis o de tipos
que quedaran dentro de los archivos no se detectaron**.

---

## 3. Lo que falta ejecutar, en orden

El orden importa: el primero es el que puede encontrar un error de diseño.

### 3.1 Sintaxis y autoload

```sh
cd api
composer install
composer dump-autoload --optimize
php artisan --version
```

Si esto falla, hay un error de sintaxis o un `use` que no resuelve. **Es lo
primero que hay que correr.**

### 3.2 Migraciones

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env up -d postgres
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  exec postgres createdb -U almacen_app almacen_test

docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan migrate --force
```

Lo que hay que mirar con atención:

1. **El orden de las migraciones.** `conteos` (000008) tiene que existir antes
   que `movimientos_stock` (000009), porque el segundo tiene una llave foránea
   al primero. Y `conteo_items.movimiento_id` es una columna suelta en 000008
   cuya llave foránea se agrega en 000009. Si el orden está mal, falla aquí.
2. **Los gatillos.** Que existan `gatillo_saldos_solo_por_movimiento` y
   `gatillo_movimientos_inmutables`.
3. **La RLS.** Que las 17 tablas queden con `ENABLE` y `FORCE`.

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan almacen:verificar-aislamiento
```

Debe imprimir: `Aislamiento verificado: RLS activa, contexto cerrado y stock
inmutable.`

### 3.3 Semilla

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan db:seed
```

La semilla ejercita el camino completo: crea una empresa, carga stock **por
movimientos**, reserva un pedido, arma una oleada y cierra un conteo con
diferencia. Si la semilla termina, el camino feliz funciona.

### 3.4 Pruebas

```sh
# Aislamiento entre empresas
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan test --testsuite=Aislamiento

# Stock inmutable y reservas vencidas
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan test --testsuite=Stock

# Conversion de unidades (no necesita base de datos)
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan test --testsuite=Unit

# Todo
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan test
```

**Estas pruebas necesitan PostgreSQL de verdad.** El aislamiento está
implementado con Row Level Security y la inmutabilidad del stock con
gatillos: en SQLite pasarían sin comprobar nada. `phpunit.xml` fija
`DB_CONNECTION=pgsql` y `DB_DATABASE=almacen_test`.

---

## 4. Qué se verificó y cómo

### Versiones de los paquetes

| Paquete | Versión usada | Verificación |
|---|---|---|
| `laravel/framework` | `^13.37` | Etiqueta `v13.37.0` existe en el repositorio de Laravel (API de GitHub) |
| `nuxt` | `4.6.2` | Registro de npm: es la versión `latest` |
| `firebase/php-jwt` | `^7.2` | Heredado del Portal de Autoservicio, **no verificado en esta sesión** |
| `laravel/socialite` | `^5.31` | Ídem, no verificado |
| `predis/predis` | `^3.7` | Ídem, no verificado |
| `laravel/tinker` | `^2.10` | Ídem, no verificado |
| `phpunit/phpunit` | `^13.4` | Ídem, no verificado |
| `fakerphp/faker`, `mockery/mockery`, `nunomaduro/collision` | igual que la referencia | Ídem, no verificados |

### Versiones de las imágenes

| Imagen | Versión | Verificación |
|---|---|---|
| PostgreSQL | `postgres:18.6` | Docker Hub: etiqueta activa |
| Redis | `redis:8.8.3-alpine` | Docker Hub: etiqueta activa |
| PHP (API) | `php:8.4.26-cli-alpine3.23` y `php:8.4.26-fpm-alpine3.23` | Docker Hub: etiquetas activas |
| Node (interfaz) | `node:24.22.0-alpine3.24` | Docker Hub: etiqueta activa |
| Caddy | `caddy:2.11.7-alpine` | Docker Hub: etiqueta activa |
| Authentik | `ghcr.io/goauthentik/server:2025.10` | **No verificada.** Heredada del Portal de Autoservicio |

### Cosas que quedaron pendientes de verificar

1. **La imagen de Authentik.** El intento de verificar la etiqueta falló con
   un error del registro durante esta sesión. Antes de producción hay que
   comprobar que `ghcr.io/goauthentik/server:2025.10` existe y que su modelo de
   servicios (sin Redis, `server` más `worker`) sigue siendo el de esa
   versión.
2. **La imagen `composer:2` no está fijada** por versión exacta ni por digest.
   El `Dockerfile` lo dice en un comentario. Antes de producción hay que
   reemplazarla por `composer:<versión>@sha256:<digest>` verificados.
3. **Ninguna imagen está fijada por digest SHA256.** Todas están fijadas por
   etiqueta exacta, que es mejor que `:8.4` pero no es inmutable. El paso
   siguiente es fijar por digest.
4. **Las restricciones de Composer** heredadas del Portal de Autoservicio no
   se verificaron una por una contra Packagist en esta sesión.
5. **La API de Laravel no se comparó con la documentación oficial.** Los
   nombres de las clases (`Gate`, `$request->validate`, `Schedule`,
   `HasUuids`) son de uso corriente, pero **no se abrieron los documentos de
   Laravel 13 para confirmarlos**.

---

## 5. Pruebas escritas, por qué importan

### 5.1 Aislamiento entre empresas
`api/tests/Feature/Aislamiento/AislamientoDeDatosTest.php` — 6 pruebas

| Prueba | Qué comprueba |
|---|---|
| Sin contexto, la consulta **falla** | La función de contexto no devuelve `NULL` en silencio |
| Cada empresa ve solo sus filas | La RLS filtra de verdad |
| El stock ajeno no se lee ni se escribe | 404 al leer, excepción al escribir (`WITH CHECK`) |
| No se inserta con empresa ajena | `WITH CHECK` en la escritura |
| El contexto no sobrevive a la transacción | El worker de colas no arrastra la empresa de otro trabajo |
| La función falla cerrada | El aislamiento falla cerrado, nunca abierto |

### 5.2 El stock no se puede editar
`api/tests/Feature/Stock/StockInmutableTest.php` — 9 pruebas

| Prueba | Qué comprueba |
|---|---|
| Actualizar el saldo desde el modelo revienta | La barrera de PHP |
| **Escribir directo en la base también revienta** | La barrera de PostgreSQL, la que importa |
| Insertar un saldo a mano revienta | También la creación |
| Los movimientos no se editan ni se borran | Solo agregado |
| El saldo **sí** cambia cuando pasa por un movimiento | La vía legítima funciona |
| No se puede sacar más de lo que hay | Stock negativo imposible |
| El saldo es siempre la suma de sus movimientos | La propiedad que hace posible conciliar |
| El traspaso es un par, y el total no cambia | El error típico no puede ocurrir |
| No se mueve mercadería en conteo abierto | La zona congelada |

La segunda prueba es la más importante del proyecto: simula a alguien con un
`psql` en la mano. Si esa pasa, la garantía no depende de que el código
obedeza.

### 5.3 La reserva vencida libera la unidad
`api/tests/Feature/Stock/ReservaVencidaTest.php` — 6 pruebas

| Prueba | Qué comprueba |
|---|---|
| Reservar no suma físico, sube reservado | Las tres cifras separadas |
| Una reserva vencida devuelve la unidad | **El caso caro** |
| Queda como `vencida`, no `liberada` | Se puede distinguir por qué se liberó |
| Una reserva vigente no se libera | Expirar es por plazo, no por turno |
| No se puede reservar más de lo que hay | La restricción de la base, con mensaje útil |
| El despacho consume la reserva y saca la mercadería | El flujo completo |
| Liberar un pedido devuelve todas sus reservas | La operación de jefe de almacén |

### 5.4 La ruta de picking
`api/tests/Feature/Picking/RutaDePickingTest.php` — 3 pruebas

| Prueba | Qué comprueba |
|---|---|
| Los pasillos van en orden y no se parten en dos | **La garantía operacional** |
| El zigzag alterna sin romper el orden | El recorrido dentro del pasillo |
| Una reserva vencida no entra a la ruta | La ruta no pide stock que ya se liberó |

### 5.5 El conteo cíclico
`api/tests/Feature/Conteos/ConteoCiclicoTest.php` — 6 pruebas

| Prueba | Qué comprueba |
|---|---|
| Al abrir se congela una foto | La línea de comparación |
| El movimiento de arriba no cambia la foto | La foto es una foto |
| Una diferencia sin justificación impide cerrar | La regla |
| Al cerrar se corrige con un movimiento | No se edita el saldo |
| Un conteo que cuadra no inventa movimientos | No genera ruido |
| No se puede cerrar un conteo incompleto | Todo o nada |
| Cancelar devuelve la zona al movimiento | La salida del atasco |

### 5.6 Conversión de unidades
`api/tests/Unit/ConversorDeUnidadesTest.php` — 8 pruebas, sin base de datos

Caja de 12, pallet de 48, media caja, conversión de vuelta, cantidad
negativa, y que un artículo no pueda guardar stock en cajas.

**Total: 38 pruebas escritas, ninguna ejecutada.**

---

## 6. Lo que NO está probado

Con todo el detalle:

| Área | Estado |
|---|---|
| Sintaxis de los 60 archivos PHP | **Sin comprobar.** No hay PHP en la máquina |
| Las 12 migraciones contra PostgreSQL real | **Sin ejecutar** |
| Los 5 endpoints de Authentik | **Sin ejecutar.** No hay Authentik ni internet desde los contenedores |
| El BFF de Nuxt (`server/api/[...ruta].ts`) | **Sin ejecutar** |
| Las 10 páginas de la interfaz | **Sin ejecutar**. No hay Node instalado |
| `npm install` y `nuxt build` | **Sin ejecutar** |
| Las consultas SQL escritas a mano en los comandos | **Sin ejecutar** |
| `bcmath` en tiempo de ejecución | **Sin comprobar.** El Dockerfile la instala, pero aquí no está |
| Carga y rendimiento | **Sin medir** |
| Comportamiento con volúmenes reales | **Sin probar** |
| Accesibilidad y uso en pantalla de bodega | **Sin probar** |

---

## 7. Revisión de idioma

Se hizo una revisión de todos los archivos de `apps/almacen/` buscando
caracteres fuera del alfabeto latino.

**Pasada 1 (saltándose casos):** se buscaron rangos Unicode de han, kana,
hangul y cirílico. Se encontraron y corrigieron **cuatro** apariciones
introducidas durante la escritura, todas en comentarios y textos de
explicación: un verbo chino pegado en el comentario de los traspasos
(`api/app/Enums/TipoMovimiento.php`), una palabra china en la nota de
desactivación de artículos (`api/app/Http/Controllers/ArticuloController.php`),
un ideograma en la descripción de la ruta de picking
(`api/app/Services/Picking/ArmadorDeRuta.php`) y otro en la justificación de
merma de la semilla (`api/database/seeders/AlmacenEjemploSeeder.php`).

**Pasada 2 (completa, incluidos los casos que la primera saltaba):** se
revisó además cada archivo PHP, Vue, Markdown, YAML, SQL, shell, JSON y
TypeScript, línea por línea, buscando **palabras en inglés pegadas al texto
español** y caracteres CJK o cirílicos que hubieran sobrevivido a la primera
pasada. Se encontraron y corrigieron quince, entre ellas:

| Archivo | Qué se encontró |
|---|---|
| `api/app/Console/Commands/AlmacenLiberarReservasVencidas.php` | «Promiseiendo» |
| `api/database/seeders/AlmacenEjemploSeeder.php` | Dos palabras en inglés y dos ideogramas |
| `api/tests/Feature/Stock/StockInmutableTest.php` | «happened» |
| `web/app/pages/oleadas/[id].vue` | «segúnToucha» |
| `web/app/pages/pedidos/index.vue` | Un campo mal escrito en una interfaz |
| `api/app/Http/Controllers/ConteoController.php` | Una asignación espuria en un método |
| `docs/01-ARQUITECTURA.md` | «seProvisioning», «consolidate» |
| `docs/02-MODELO-DE-DATOS.md` | «Constant» |
| `docs/04-INSTALACION.md` | «These settings» |
| `docs/06-PRIMER-INGRESO.md` | «Constant» |
| `docs/07-SEGURIDAD.md` | «took», «reliability» |
| `docs/08-RESPALDOS.md` | «nobody» |
| `deploy/caddy/Caddyfile` | Un fragmento en chino |
| `deploy/docker-compose.dev.yml` | Un ideograma |
| `deploy/api/docker/entrypoint.sh` | Un ideograma |

Una **pasada automática de acentos** no se usó, a propósito: en estos
archivos hay valores de entorno, nombres de rutas y enlaces markdown que una
pasada así puede romper. Además, el barrido de caracteres CJK **no** detecta
palabras inglesas pegadas: por eso la segunda pasada es manual y queda
documentada acá.

**Palabras clave de shell que NO se tocan** (traducirlas rompería los
scripts):

```sh
until   while   for   do   done   case   esac
```

Los tres scripts pasan `bash -n` **después** de las correcciones de idioma.

---

## 8. Comprobaciones de seguridad de esta sesión

| Comprobación | Resultado |
|---|---|
| Todo quedó dentro de `apps/almacen/` | Sí. No se escribió nada en la raíz del espacio de trabajo ni en otra aplicación de `apps/` |
| Ninguna otra aplicación fue modificada | Correcto. `kits/` y las demás apps de `apps/` solo se leyeron, como referencia |
| `.env.example` sin valores reales | Sí. Los secretos se generan con comandos documentados |
| `.gitignore` cubre `.env`, respaldos y claves | Sí |
| Ningún archivo de respaldo dentro del repositorio | Sí, `*.dump`, `*.dump.gz`, `*.sql` y `respaldos/` están ignorados |

---

## 9. Riesgos principales, en orden de gravedad

1. **Nada se ha ejecutado.** Es el riesgo número uno y no admite matiz: hasta
   que corran las migraciones y las pruebas, el estado real de esta aplicación
   es desconocido. Un error de sintaxis en un archivo PHP basta para que nada
   funcione.

2. **Las pruebas de aislamiento no se ejecutaron.** Si la RLS quedó mal
   puesta por un error en la migración 000012, una empresa podría ver los datos
   de otra **sin que nada avise**: la interfaz se vería perfecta.

3. **El stock inmutable no se probó contra la base real.** El gatillo es la
   garantía central del producto. Si `current_setting('app.movimiento_stock')`
   se comportara distinto de lo esperado, el stock sería editable a mano y la
   premisa del sistema sería falsa.

4. **Las reservas vencidas no se probaron con el planificador corriendo.** Si
   el contenedor `cola` no levanta, el almacén sigue despachando en silencio
   mientras la mercadería de pedidos olvidados queda reservada para siempre.
   Es la falla más difícil de detectar y la más cara.

5. **El zigzag de la ruta es una decisión, no un estándar.** Si en la bodega
   real la geometría es otra (pasillos en U, racks de doble fondo), el
   recorrido puede no ser el óptimo. La garantía que sí se sostiene es que
   ningún pasillo se parte en dos.

6. **No hay medición de rendimiento.** Una ruta de picking de 1.200 paradas o
   una conciliación sobre millones de movimientos pueden no aguantar en el
   tiempo esperado.

7. **El código no usa clases `FormRequest`.** Es una decisión consciente para
   mantener el número de archivos acotado, pero con un equipo más grande
   conviene extraerlas.

8. **El marco de `bcmath` cambia el comportamiento de las comparaciones.**
   Con la extensión, la comparación es exacta; sin ella, hay una tolerancia de
   `0.000001`. El Dockerfile la instala, pero conviene confirmarlo en el
   contenedor en ejecución.

---

## 10. Qué haría yo, en orden, con un entorno disponible

1. `composer install` y `php artisan --version` (caza errores de sintaxis).
2. `php artisan migrate` contra un PostgreSQL limpio.
3. `php artisan almacen:verificar-aislamiento`.
4. `php artisan db:seed` y abrir la interfaz.
5. `php artisan test` completo.
6. Probar a mano el camino de una recepción: crear, confirmar, ver el saldo.
7. Probar a mano una ruta: armar, imprimir, recoger, despachar.
8. Probar un conteo con diferencia y revisar el movimiento de ajuste.
9. Apagar Redis y ver que el endpoint de salud lo dice claro.
10. Restaurar un respaldo sobre una base de prueba.

Nada de lo anterior está hecho. Esta lista es el trabajo que queda.

---

## Los dos roles de PostgreSQL: escritos, no ejecutados

La separación entre el rol de arranque (`almacen_admin`) y el rol de la
aplicación (`almacen_app`) está escrita en el `compose`, en
`deploy/postgres/inicializar-rol.sh` y en
[03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md). **No se ha ejecutado
ninguna vez.**

En el equipo donde se escribió este cambio no había PostgreSQL ni Docker. Eso
significa que nada de lo que sigue está comprobado:

1. Que el volumen de PostgreSQL se inicialice y el script corra sin error.
2. Que el rol `almacen_app` quede creado con sus cinco atributos explícitos.
3. Que `ALTER DATABASE almacen OWNER TO almacen_app` se aplique.
4. Que el rol de la conexión no sea superusuario ni tenga `BYPASSRLS`.
5. Que `php artisan almacen:verificar-aislamiento` salga con código 0.
6. Que una empresa no pueda leer datos de otra, que es el escenario que motiva
   todo este cambio.

Lo único verificado hasta ahora es estático: la sintaxis del script pasa
`bash -n` y el `compose` es YAML válido, ambas cosas comprobadas por
`tools/validar_apps.py`. El razonamiento se apoya en la documentación oficial de
PostgreSQL 18, no en una ejecución.

**Pendiente, sin fecha:** correr el punto 5 y el punto 6 contra una base real
antes de recibir la primera empresa.