# Gestión de Almacén

Aplicación propia de Hefesto Studio para administrar un **almacén físico**:
recepción, ubicaciones, control de stock y preparación de pedidos por oleadas.

No es un kit de despliegue de una herramienta de terceros. El código es
original y está en este repositorio.

---

## El problema que resuelve

El problema de un almacén no es saber cuánto stock hay. Es **saber dónde
está**.

La mercadería está en un rack, en un pasillo, en un nivel. Si el sistema solo
dice «tenemos 400 unidades», el operario pierde media hora buscándolas en el
primer cliente que las pide. Si además las unidades son cajas y no piezas,
la cuenta sale mal y nadie lo sabe.

El segundo problema es silencioso y caro: **las diferencias de inventario**.
Nadie las mira a diario, y cuando se miran ya son grandes y nadie sabe
cuándo quedaron así.

Esta aplicación responde a las dos:

1. **Dónde está cada unidad**, con pasillo, rack, nivel, posición y código de
   barras para escanearlo en el piso.
2. **Por qué el número es el que es**, con cada movimiento registrado, con su
   motivo, su fecha y su persona.

---

## Las tres decisiones que definen el producto

### 1. Disponible, reservado y físico son tres cosas

```
cantidad_fisica     lo que hay en el rack
cantidad_reservada  lo comprometido a un pedido
disponible          lo que se puede vender  =  fisica - reservada
```

`disponible` **no es una columna**: se deriva. Si lo fuera, se desactualizaría
y el almacén prometería mercadería que ya salió.

Además, **toda reserva expira**. Un pedido que nadie confirma libera la
mercadería sola, a las 72 horas por omisión. Sin eso, el almacén termina
prometiendo stock que ya está en el camión de otro.

> Un solo campo de stock es un error de diseño, no una simplificación.

### 2. El stock no se edita: se mueve

No hay ninguna pantalla donde escribir un saldo. El stock cambia por
**movimientos**, y cada movimiento tiene motivo.

No es una convención del código: hay un **gatillo en PostgreSQL** que rechaza
cualquier escritura sobre los saldos fuera del registro de movimientos. Un
`UPDATE` escrito a mano desde un `psql` tampoco pasa. Los movimientos, además,
no se editan ni se borran: para corregir algo se escribe el contrario.

### 3. La ruta de picking se ordena por ubicación

Una oleada agrupa varios pedidos en **una sola vuelta de recoleccion**. La
ruta se ordena de modo que cada pasillo se recorre una vez, de punta a punta
o al revés según corresponda. El operario imprime y sigue el papel.

El beneficio no es de sistema: es de **horas de operario**.

---

## Aviso importante

**Esto no reemplaza un sistema de gestión de inventario certificado**, ni
sustituye las decisiones del jefe de almacén sobre ubicaciones.

Si el rack A-03 nivel 2 lo ocupa el motor y no el arnés, eso lo sabe el jefe
de almacén. Esta aplicación guarda lo que se le diga y lo devuelve cuando
preguntan: no decide dónde va nada.

Y antes de poner esto a producir, lee
[docs/10-PRUEBAS-Y-ESTADO.md](docs/10-PRUEBAS-Y-ESTADO.md).

---

## Estado

**Código escrito, sin ejecutar.** No había PHP, ni Composer, ni Docker, ni
PostgreSQL en el equipo donde se escribió. No se ejecutó ni una migración, ni
una prueba, ni un arranque del servidor.

Lo que **sí** se hizo:

- Los tres scripts de shell validados con `bash -n`.
- Las versiones de paquetes e imágenes verificadas contra los registros
  oficiales.
- Revisión de idioma en dos pasadas, buscando caracteres CJK y cirílicos, y
  palabras en inglés pegadas al español.

El detalle de qué está probado, qué no, y qué comandos exactos hay que correr
cuando haya un entorno, está en
[docs/10-PRUEBAS-Y-ESTADO.md](docs/10-PRUEBAS-Y-ESTADO.md).

---

## Cómo está armado

```
almacen/
├── docs/     01 arquitectura · 02 modelo de datos · 03 multitenancy y roles
│             04 instalación · 05 configuración · 06 primer ingreso
│             07 seguridad · 08 respaldos · 09 API · 10 pruebas y estado
├── api/      Laravel 13 + PostgreSQL con Row Level Security
├── web/      Nuxt 4 con render en el servidor
├── deploy/   Docker Compose, imágenes y scripts de respaldo
└── .env.example
```

**Laravel** expone la API y aplica las reglas de negocio.
**PostgreSQL** es la autoridad del aislamiento entre empresas y de la
inmutabilidad del stock.
**Nuxt** es la interfaz y el servidor intermedio: el navegador nunca habla
directo con la API.
**Redis** sostiene la cache, las colas y el planificador que libera las
reservas vencidas.
**Authentik** resuelve la identidad: el almacén no guarda contraseñas.

---

## Para empezar

```sh
cp .env.example .env          # complete los secretos
docker compose -f deploy/docker-compose.dev.yml --env-file .env up -d
```

Después, en este orden:

1. [docs/04-INSTALACION.md](docs/04-INSTALACION.md) — levantar y verificar.
2. [docs/03-MULTITENANCY-Y-ROLES.md](docs/03-MULTITENANCY-Y-ROLES.md) —
   entender el aislamiento **antes** de que entre nadie.
3. [docs/06-PRIMER-INGRESO.md](docs/06-PRIMER-INGRESO.md) — el recorrido
   desde la bodega vacía hasta la ruta impresa.
4. [docs/10-PRUEBAS-Y-ESTADO.md](docs/10-PRUEBAS-Y-ESTADO.md) — qué falta
   ejecutar.

El índice completo está en [docs/00-INDICE.md](docs/00-INDICE.md).