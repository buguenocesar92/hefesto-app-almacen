# 04 - Instalación

> **Estado: no ejecutado.** Estas instrucciones están escritas pero no se
> probaron en este equipo: no había Docker, ni PHP, ni Composer, ni
> PostgreSQL. Ver [10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).

---

## 1. Requisitos

| Herramienta | Versión | Para qué |
|---|---|---|
| Docker Engine | 24 o superior | Contenedores |
| Docker Compose | v2 | Los dos archivos de `deploy/` |
| Git | Cualquier versión reciente | Código |

Las versiones de las imágenes están fijadas en los compose:

| Imagen | Versión |
|---|---|
| PostgreSQL | `postgres:18.6` |
| Redis | `redis:8.8.3-alpine` |
| PHP (API) | `php:8.4.26-fpm-alpine3.23` / `php:8.4.26-cli-alpine3.23` |
| Node (interfaz) | `node:24.22.0-alpine3.24` |
| Caddy | `caddy:2.11.7-alpine` |
| Authentik | `ghcr.io/goauthentik/server:2025.10` |

Todas se verificaron contra los registros oficiales durante la escritura de
esta aplicación. Lo que **no** está verificado está anotado en el documento 10.

---

## 2. Preparar el entorno

```sh
git clone <url-del-repositorio> hefesto-almacen
cd hefesto-almacen/apps/almacen

cp .env.example .env
```

Generar los secretos:

```sh
# Clave de la aplicación
php artisan key:generate --show
# (o, sin PHP instalado:  openssl rand -base64 48)

# Secreto de Authentik
openssl rand -base64 60 | tr -d '\n'

# Token de arranque de Authentik
openssl rand -hex 32
```

Pegar cada valor en su variable en `.env`. **Ningún valor de `.env.example`
es real** y todos los archivos `.env` están en `.gitignore`.

---

## 3. Levantar en desarrollo

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env up -d
```

Eso levanta:

| Servicio | Puerto en el host | Qué es |
|---|---|---|
| `web` | 3000 | La interfaz (Nuxt) |
| `api` | 8000 | La API (Laravel) |
| `postgres` | 127.0.0.1:5432 | La base |
| `redis` | 127.0.0.1:6379 | Cache y colas |
| `authentik-server` | 9000 | El proveedor de identidad |

El compose de desarrollo monta el código fuente como volumen, así que los
cambios se ven al instante.

### Qué se aplica solo, y qué no

- Las **migraciones** sí se aplican en desarrollo: el compose pone
  `ALMACEN_CORRER_MIGRACIONES=true`.
- La **semilla** no. Es un paso conscious:

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan db:seed
```

---

## 4. Revisar el aislamiento ANTES de que entre nadie

Este paso no es opcional y no es burocracia. Si el aislamiento no está bien,
una empresa ve los datos de otra.

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan almacen:verificar-aislamiento
```

Tiene que imprimir:

```
Aislamiento verificado: RLS activa, contexto cerrado y stock inmutable.
```

Si no, **no seguir**. Ver [03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md).

Conviene también revisar el saldo contra los movimientos:

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan almacen:conciliar
```

---

## 5. Correr las pruebas

Las pruebas de aislamiento y de stock necesitan **PostgreSQL de verdad**:
el aislamiento está implementado con Row Level Security y la inmutabilidad
con gatillos. En SQLite pasarían sin comprobar nada.

```sh
# Crear la base de pruebas
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  exec postgres createdb -U almacen_app almacen_test

# Aislamiento entre empresas
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan test --testsuite=Aislamiento

# Stock inmutable y reservas vencidas
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan test --testsuite=Stock

# Todo
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan test
```

La configuración de pruebas está en `api/phpunit.xml`, con la base
`almacen_test` y el host `127.0.0.1` del host.

---

## 6. Configurar la identidad

La API no tiene pantallas de alta de personas: las personas se crean en la
base y se autentican contra Authentik. El cruce es por **correo electrónico**.

En Authentik hay que:

1. Crear una aplicación OAuth2/OpenID Connect de tipo cliente regular con
   estos datos:
   - **Redirect URI**: el valor de `AUTHENTIK_REDIRECT_URI`
   - **Scope**: `openid profile email`
2. Copiar el **client ID** y el **client secret** al `.env`.
3. Crear el grupo `almacen-operadores` y asignarle al personal.
4. Crear las personas en Authentik con el **mismo correo** que se usará en la
   tabla `usuarios`.

Ojo con la barra final del emisor: Authentik pública el issuer con barra, y
una diferencia de una barra hace que todos los tokens parezcan de otro emisor.

Para entrar por primera vez a la interfaz de administración de Authentik:

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  exec authentik-server ak shell
```

---

## 7. La primera persona

El acceso exige que la persona exista en `usuarios`. Para crear la primera
(no hay nadie que lo haga por la interfaz todavía):

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan almacen:crear-persona \
    --empresa=bodega-demo.hefestostudio.cl \
    --email=admin@bodega-demo.hefestostudio.cl \
    --nombre="Ana Pérez" \
    --rol=administrador
```

Ese comando da de alta la primera persona. Es el arranque en frío del
sistema: hace falta alguien autorizado antes de que exista alguien que pueda
autorizar el acceso desde la interfaz. Ver
`api/app/Console/Commands/AlmacenCrearPersona.php`.

El correo **debe coincidir** con el de Authentik. Si no coinciden, la API
rechaza el acceso y lo deja escrito en el log.

Si se sembró el almacén de ejemplo (`db:seed`), ya existen cuatro personas:

| Correo | Rol |
|---|---|
| `admin@bodega-demo.hefestostudio.cl` | administrador |
| `jefe@bodega-demo.hefestostudio.cl` | jefe de almacén |
| `preparador@bodega-demo.hefestostudio.cl` | preparador |
| `auditor@bodega-demo.hefestostudio.cl` | auditor |

---

## 8. Producción

```sh
cp .env.example .env.prod
# Llenar .env.prod con secretos reales. NO se versiona.

# Construir las imágenes
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  build api web

# Aplicar migraciones (una vez, a mano)
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  run --rm api php artisan migrate --force

# Verificar el aislamiento ANTES de levantar
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  run --rm api php artisan almacen:verificar-aislamiento

# Levantar
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod up -d
```

Las migraciones **no** se aplican en cada arranque a propósito: un despliegue
que cambia el esquema sin que nadie lo decida es un despliegue que puede
cortar el servicio.

En producción solo Caddy pública puertos. La base no es accesible ni desde la
red local: para entrar hay que usar un túnel SSH.

Lista de comprobación de despliegue:

- [ ] `almacen:verificar-aislamiento` pasa
- [ ] `almacen:conciliar` pasa
- [ ] El rol de la base no es superusuario ni tiene `BYPASSRLS`
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `APP_DEBUG=false`
- [ ] El respaldo automático está configurado y probado ([08](08-RESPALDOS.md))
- [ ] Copia del respaldo **fuera** del servidor

---

## 9. Problemas frecuentes

| Síntoma | Causa habitual | Qué hacer |
|---|---|---|
| La API responde 500 al arrancar | `APP_KEY` vacía | `php artisan key:generate --show` y pegarla en el `.env` |
| `No hay contexto de empresa` | Consulta fuera del middleware | Es un error real: hay una operación que se escapó. Revisar el log |
| `Los saldos no se editan` | Alguien intentó escribir un saldo a mano | Usar el registro de movimientos. Es el sistema funcionando |
| `La ubicación X está en conteo abierto` | Hay un conteo sin cerrar | Cerrarlo o cancelarlo |
| El acceso no vuelve de Authentik | `AUTHENTIK_ISSUER` sin barra final | Agregar la barra |
| Las reservas no se liberan solas | Redis caído o planificador detenido | Revisar el servicio `cola`. Ver `GET /api/v1/salud` |