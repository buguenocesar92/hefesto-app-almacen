# 07 - Seguridad

> **Estado: revisado por lectura, no auditado.** No hubo revisión externa de
> seguridad ni pruebas de penetración. Ver
> [10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).

---

## 1. Qué está en riesgo

El almacén tiene tres cosas que no están en un sistema de formularios:

1. **Dinero**: cada unidad que sale sin registro es mercadería que alguien se
   llevó.
2. **La palabra de quien lo hizo**: la bitácora tiene que servir para
   responder «¿quién movió esto y por qué?».
3. **La relación con el cliente**: si el número no cuadra y no hay historial,
   la discusión con el cliente la pierde el almacén.

Por eso la seguridad no es «que nadie entre», sino **que no se pueda mover
mercadería sin que quede escrito**.

---

## 2. Las cinco capas

| Capa | Qué protege |
|---|---|
| Identidad (Authentik) | Quién entra |
| Sesión y CSRF | Que la petición venga del navegador de verdad |
| Autorización (Policies) | Qué puede hacer cada rol |
| Aislamiento (RLS) | Que una empresa no vea otra |
| Integridad del stock (gatillos) | Que el stock no cambie sin movimiento |

Cada una cubre lo que la anterior no.

---

## 3. Sesión y CSRF

La sesión viaja en una cookie `HttpOnly`. El navegador puede mandarla, no
leerla. En producción:

```php
'session.cookie_httponly' => 1
'session.use_strict_mode' => 1
'session.use_only_cookies' => 1
```

- `HttpOnly`: el JavaScript de la página no puede leer la cookie.
- `use_strict_mode`: el navegador solo manda el identificador de sesión que
  le dio el servidor. Sin esto, un identificador inventado por alguien más
  sería aceptado.
- `use_only_cookies`: no se acepta el identificador en la URL.

En producción, `SESSION_SECURE_COOKIE=true` y `SESSION_SAME_SITE=lax`: la
cookie solo viaja por HTTPS y no se manda en peticiones de terceros.

### Por qué todo pasa por un origen

La cookie con `SameSite=Lax` solo se manda al mismo origen. Si la API
respondiera en otro origen, habría que resolver CORS con credenciales y el
token CSRF se volvería frágil.

Por eso Nuxt corre en modo servidor y reenvía: `web/server/api/[...ruta].ts`
copia las cookies hacia la API y la cookie `XSRF-TOKEN` como cabecera
`X-XSRF-TOKEN`, que es lo que Laravel acepta. Caddy reparte `/api/*` y el
resto, de modo que hay un solo origen público.

### Token CSRF

Todas las rutas de escritura (`POST`, `PUT`, `PATCH`, `DELETE`) pasan por el
middleware de CSRF de Laravel. Solo queda exento `/api/v1/salud`, que no
devuelve ni recibe datos de negocio.

Al entrar y al cambiar de rol se regenera el identificador de sesión
(`$request->session()->regenerate()`), para que un identificador fijado antes
del acceso no siga siendo válido después.

---

## 4. Autorización

Todo recurso pasa por una Policy. El menú de la interfaz esconde botones que
la API va a rechazar, pero **eso es una cortesía, no una protección**: la
decisión se toma en el servidor, en `Gate::authorize(...)`.

La tabla de roles está en
[03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md), sección 4.

La regla que más importa: **anular un documento y cerrar un conteo con
diferencia requieren jefe de almacén**. Son las dos acciones que cambian la
historia del stock.

Y: **nadie se sube su propio rol.**

### 404, no 403

Una Policy que rechaza devuelve 404, no 403. Un 403 confirmaría que el
recurso existe; en una aplicación donde hay varias empresas, eso ya es
información.

---

## 5. Validación

Toda entrada externa se valida antes de tocar la base. Se usa el validador de
Laravel con mensajes en español y nombres de campo en el idioma de quien
opera (`code` → «código de barras»).

Tres validaciones que merecen mención:

- **La unidad base tiene que ser una unidad base.** Un artículo que guardara
  stock en cajas tendría toda su historia en una unidad distinta.
- **El tipo de documento y las ubicaciones tienen que calzar.** Una entrada
  de compra no puede tener ubicación de origen; un traspaso necesita las dos
  y distintas.
- **La cantidad contada no puede ser negativa.** Un conteo no puede «descontar»
  mercadería: si falta, la diferencia es negativa y el ajuste es una salida.

Las reglas que dependen de otra fila (el tipo de documento y sus ubicaciones)
no se pueden expresar con una restricción simple de PostgreSQL, así que las
valida `ServicioDeDocumentos` antes de mover una sola unidad.

---

## 6. Integridad del stock

Es la capa específica del almacén, y la única que no se copiería en otro
sistema.

| Garantía | Cómo |
|---|---|
| El saldo no se edita a mano | Gatillo `gatillo_saldos_solo_por_movimiento` |
| El saldo es la suma de sus movimientos | `CHECK` y `almacen:conciliar` |
| No hay stock negativo | `CHECK (cantidad_fisica >= 0)` |
| No se reserva más de lo que hay | `CHECK (cantidad_reservada <= cantidad_fisica)` |
| Los movimientos no se editan ni se borran | Gatillo `gatillo_movimientos_inmutables` |
| Sin motivo no hay movimiento | `motivo_id` obligatorio en el modelo |
| No se mueve mercadería en conteo abierto | `RegistroDeMovimiento::verificarConteoAbierto()` |

Las tres primeras filas son restricciones de la base: valen también para un
`UPDATE` escrito a mano desde un `psql`. Es lo que las hace confiables y no
una convención.

---

## 7. Aislamiento entre empresas

Row Level Security en 17 tablas, con `USING`, `WITH CHECK` y
`FORCE ROW LEVEL SECURITY`. El detalle está en
[03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md).

El requisito de despliegue es duro: **el rol de la base no puede ser
superusuario ni tener `BYPASSRLS`**. Con cualquiera de los dos, PostgreSQL
ignora todas las políticas y el aislamiento sería decorativo.

`almacen:verificar-aislamiento` lo comprueba y falla si no se cumple.

---

## 8. Contraseñas y secretos

- **El almacén no guarda contraseñas.** La identidad la resuelve Authentik.
- `.env.example`, `api/.env.example` y `web/.env.example` no tienen ningún
  valor real.
- Todos los `.env` están en `.gitignore`, y el patrón `.env.*` está
  ignorado salvo las plantillas.
- `APP_KEY` se genera por instalación, nunca se comparte entre entornos.
- `APP_DEBUG=false` en producción: con depuración activa, un error puede
  mostrar rutas internas o consultas SQL.

---

## 9. Registro de operaciones

`AuditarOperacion` escribe en el registro las operaciones de **escritura**
(`POST`, `PUT`, `PATCH`, `DELETE`): quién, de qué empresa, con qué rol, qué
ruta, qué código de respuesta y desde qué dirección.

Los listados no dejan rastro: si dejaran, el registro serviría para nada
porque estaría lleno de ruido.

Los movimientos de stock, además, guardan usuario y fecha en cada fila. Por
eso el registro sirve para dos preguntas distintas:

- «¿Quién abrió este conteo?» → registro de operaciones
- «¿Quién movió estas tres unidades de este rack?» → `movimientos_stock`

---

## 10. Cabeceras

Caddy añade a todas las respuestas:

| Cabecera | Para qué |
|---|---|
| `Strict-Transport-Security` | Obligar al navegador a no volver a HTTP |
| `X-Content-Type-Options: nosniff` | Que no interprete el contenido como otra cosa |
| `X-Frame-Options: DENY` | Que nadie meta el almacén en un marco |
| `Referrer-Policy` | Que no se filtren URLs internas a terceros |
| `Content-Security-Policy` | Que solo carguen recursos del propio sitio |

`frame-ancestors 'none'` está en la CSP: el almacén no tiene embebidos, así
que se puede cerrar del todo.

---

## 11. Uploads y archivos

La aplicación **no sube archivos**. Los documentos de compra y los certificados
viven en el sistema del cliente, no acá. Esa decisión elimina de entrada toda
una clase de problemas: archivos ejecutables, rutas de almacenamiento,
servir documentos por HTTP y decidir quién puede descargarlos.

---

## 12. Lo que NO se hizo

Con todas las letras:

1. **No hay revisión de seguridad externa** ni pruebas de penetración.
2. **No hay límite de peticiones** (rate limiting) por persona. Una sesión
   válida podría pedir miles de consultas por minuto. En la red interna de una
   bodega el riesgo es bajo, pero si la aplicación se expone a internet hay
   que añadirlo.
3. **No hay segundo factor por rol.** Authentik puede exigirlo a un grupo, y
   para `administrador` debería ser obligatorio. Queda como decisión
   pendiente.
4. **No hay cifrado de datos sensibles en la base.** Correos y nombres de
   clientes de los pedidos están en claro. Si el cliente tiene requisitos de
   protección de datos personales, hay que revisarlo.
5. **No hay registro de acceso de solo lectura.** Ver quién consultó qué
   stock puede importar en un almacén con mercadería de valor.
6. **Las pruebas de seguridad están escritas pero no se ejecutaron** (no
   había PostgreSQL ni PHP en el entorno donde se escribió esto).

---

## 13. Lista de comprobación de seguridad para producción

- [ ] `php artisan almacen:verificar-aislamiento` pasa
- [ ] El rol de la base no es superusuario ni tiene `BYPASSRLS`
- [ ] `APP_DEBUG=false`
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`
- [ ] Caddy emite el certificado y `Strict-Transport-Security` está activo
- [ ] Solo Caddy pública puertos; PostgreSQL y Redis no son accesibles
- [ ] `.env.prod` tiene secretos propios y no está versionado
- [ ] Las cuentas del almacén son **personales**, no compartidas entre
      operarios
- [ ] El grupo de administración de Authentik exige segundo factor
- [ ] El respaldo está configurado y **probado** ([08](08-RESPALDOS.md))