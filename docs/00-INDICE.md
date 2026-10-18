# Gestión de Almacén · Documentación

Aplicación propia de Hefesto Studio para administrar un almacén físico:
recepción, ubicaciones, control de stock y preparación de pedidos por
oleadas.

---

## Qué es esto y qué no es

**Es** un sistema que sabe dónde está cada unidad de mercadería, que separa
el stock disponible del reservado del físico, y que arma la ruta de
recolección ordenada por ubicación para que el operario no haga el mismo
pasillo veinte veces.

**No es** un reemplazo de un sistema de gestión de inventario certificado,
ni reemplaza las decisiones del jefe de almacén sobre qué ubicación es
cuál. Si el rack A-03 nivel 2 lo ocupa el motor o el arnés, eso lo sabe el
jefe de almacén; esta aplicación solo lo guarda y lo devuelve cuando
preguntan.

---

## En una pantalla

La pregunta que el sistema tiene que responder mejor que nadie:

> «Tenemos 400 unidades de ejes. ¿Dónde están?»

Un sistema que responde «400» obliga a media hora de búsqueda antes de
servir al primer cliente. Este responde con el pasillo, el rack, el nivel y
la posición, y con el código de barras para escanearlo en el piso.

La segunda pregunta, más silenciosa y más cara:

> «El número no cuadra. ¿Desde cuándo y por qué?»

Un sistema de movimientos responde eso con fecha, motivo, documento y
persona. Un campo de stock editable responde «no sé».

---

## Los documentos

| # | Documento | De qué trata |
|---|---|---|
| 01 | [Arquitectura](01-ARQUITECTURA.md) | Las tres capas, el flujo de una recepción y el flujo de una ruta de picking |
| 02 | [Modelo de datos](02-MODELO-DE-DATOS.md) | Tablas, las tres cifras del stock, unidades de conversión, traspasos |
| 03 | [Multitenancy y roles](03-MULTITENANCY-Y-ROLES.md) | Aislamiento entre empresas con RLS, y qué puede hacer cada rol |
| 04 | [Instalación](04-INSTALACION.md) | Levantar el entorno paso a paso |
| 05 | [Configuración](05-CONFIGURACION.md) | Variables de entorno y reglas del almacén |
| 06 | [Primer ingreso](06-PRIMER-INGRESO.md) | Recorrido guiado desde la bodega vacía hasta la ruta impresa |
| 07 | [Seguridad](07-SEGURIDAD.md) | CSRF, validación, autorización, cookies, qué queda fuera |
| 08 | [Respaldos](08-RESPALDOS.md) | Qué respaldar, cada cuánto y cómo probar que sirve |
| 09 | [API](09-API.md) | Endpoints, códigos de error y convenciones |
| 10 | [Pruebas y estado](10-PRUEBAS-Y-ESTADO.md) | Qué se probó, qué no se probó y por qué |

---

## Estado, sin adornos

**Código escrito, sin ejecutar.** En el equipo donde se escribió esta
aplicación no había PHP, ni Composer, ni Docker, ni PostgreSQL. No se ha
ejecutado ni una sola migración, ni una prueba, ni un arranque del servidor.

El detalle de qué está probado y qué no está, con los comandos exactos que
hay que correr cuando haya un entorno disponible, está en
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).

Las versiones de los paquetes y de las imágenes **sí** se verificaron
contra los registros oficiales; esa verificación está anotada archivo por
archivo en el mismo documento 10.

---

## Estructura

```
apps/almacen/
├── README.md          # este punto de partida
├── docs/              # la documentación
├── api/               # Laravel 13 + PostgreSQL
├── web/               # Nuxt 4 (SSR)
├── deploy/            # Docker Compose, Dockerfiles, respaldos
└── .env.example       # plantilla, sin valores reales
```

---

## Advertencia

Esta aplicación administra mercadería y datos de trabajo. Antes de ponerla
a producir hay que leer [10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md) y
entender qué partes siguen sin probarse.