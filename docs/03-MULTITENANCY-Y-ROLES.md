# 03 - Multitenancy y roles

---

## 1. Qué hay que evitar primero

En un almacén, el riesgo más caro **no es que falle una pantalla**. Es que
un operador de la Bodega de Quilicura vea el stock y los pedidos de la
Bodega de San Bernardo. Todo lo demás —la interfaz, la rapidez— es menos
urgente que eso.

Por eso el aislamiento **no puede depender solo del código**. Se implementa
en dos capas:

| Capa | Qué hace | Qué detecta |
|---|---|---|
| Aplicación (Policies y middleware) | Decide y filtra | Errores de lógica, consultas mal escritas |
| **PostgreSQL (Row Level Security)** | **Impide que PostgreSQL devuelva filas ajenas** | **Todo lo que se le escape a la capa de arriba** |

La base es la última línea. Si el código tiene un error, la base sigue sin
entregar datos de otra empresa.

---

## 2. El patrón elegido: columna `tenant_id` + RLS

Todas las tablas de negocio llevan `tenant_id`, y PostgreSQL aplica una
política que exige que esa columna sea igual a la de la petición.

**Este patrón no es una invención de esta aplicación.** Se tomó de la
decisión ya escrita y probada en
`apps/portal-autoservicio/docs/03-MULTITENANCY.md` y se reutilizó tal cual.
No se inventó un cuarto enfoque.

### Por qué no un esquema por empresa

La alternativa clásica es darle a cada empresa su propio esquema
(`cliente_1.saldos`, `cliente_2.saldos`). Se evaluó y se descartó:

1. **Las migraciones se multiplican.** Con esquema por cliente, cada
   migración tiene que aplicarse en TODOS los esquemas: con 30 empresas son
   30 ejecuciones, con 300 son 300.
2. **El pool de conexiones se vuelve el cuello de botella**, porque hay que
   dimensionarlo por el número de combinaciones esquema/rol que se usan de
   verdad.
3. **Laravel obliga a duplicar la lógica de esquema.** Un `DB::table()`
   olvidado, una relación mal definida.
4. **Las herramientas de administración se complican**: respaldar una empresa
   sería `pg_dump -n cliente_1`, lo que obliga a scripts a medida.
5. **El aislamiento queda en manos de la aplicación.** El `search_path` es
   un parámetro de sesión: si queda mal puesto, la consulta va al esquema
   equivocado y **no falla** — devuelve los datos de la empresa equivocada sin
   error visible.

### Por qué entonces columna única + RLS

- Una migración, un esquema, un pool.
- La política se aplica en la base, no en PHP: no se puede «olvidar».
- Un `where tenant_id = ?` olvidado no fuga datos: filtra de más, que es un
  error visible.
- Respaldar es `pg_dump` de una base, sin scripts por empresa.

El costo es que **todas** las consultas necesitan contexto de empresa. Ese
costo está resuelto y forzado por diseño.

---

## 3. El mecanismo, en detalle

Definido en
`api/database/migrations/2026_10_17_000012_activar_row_level_security.php`.

### La función de contexto

```sql
CREATE FUNCTION public.app_tenant_id() RETURNS uuid
    valor := current_setting('app.tenant_id', true);
    IF valor IS NULL OR valor = '' THEN
        RAISE EXCEPTION ...
```

**Lanza excepción** si no hay empresa. Podría devolver `NULL` y filtrar todo
en silencio, pero se eligió que falle de forma ruidosa:

- Si devuelve `NULL`, la consulta devuelve cero filas sin aviso. Alguien lo
  «arregla» devolviendo `NULL` cuando hace falta (un reporte, un comando de
  consola) y la puerta queda abierta sin que nada lo detecte.
- Si lanza excepción, el error aparece en el log **en el momento**, y se
  sabe exactamente qué operación se escapó del middleware.

Es el mismo criterio que un candado: importa que falle cerrado.

### La política

```sql
ALTER TABLE public.saldos ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.saldos FORCE ROW LEVEL SECURITY;

CREATE POLICY politica_aislamiento_saldos ON public.saldos
    FOR ALL
    TO PUBLIC
    USING (tenant_id = public.app_tenant_id())
    WITH CHECK (tenant_id = public.app_tenant_id());
```

Tres detalles que importan:

- `USING` filtra lo que se **lee**.
- `WITH CHECK` impide **escribir** una fila con otro `tenant_id`. Sin esto,
  un `INSERT` con el tenant equivocado pasaría la lectura y dejaría una fila
  invisible que nadie podría ver ni borrar.
- `FORCE ROW LEVEL SECURITY` hace que la política aplique **también al
  dueño** de la tabla. Sin `FORCE`, el dueño la saltaría, y en desarrollo el
  rol de la aplicación suele ser el dueño: sin `FORCE`, el aislamiento sería
  decorativo justo en el entorno donde se prueba.

### Cómo viaja la empresa

```php
DB::select('SELECT set_config(?, ?, true)', ['app.tenant_id', $tenantId]);
```

El `true` final es lo más importante del archivo: significa **alcance de
transacción**. Al confirmar o revertir, PostgreSQL descarta el valor solo.

Con un `SET` normal (ámbito de sesión), el valor sobreviviría a la petición.
Y el **worker de colas reutiliza la misma conexión** para trabajos de
empresas distintas: un `SET` a sesión significa que un trabajo de la Bodega
de Quilicura podría leer los datos de la Bodega de San Bernardo. Ese es
exactamente el fallo que este diseño evita, y por eso
`ContextoTenant::establecer()` **lanza excepción si no está dentro de una
transacción**.

### Quién fija el contexto

Solo una clase, `App\Services\Tenancy\ContextoTenant`, y solo a través del
middleware `App\Http\Middleware\ResolverTenant`, que envuelve la petición
autenticada en una transacción con la empresa ya fijada.

### La tabla `tenants` es la excepción consciente

`tenants` **no lleva RLS**, a propósito: se consulta durante el acceso,
cuando todavía no hay sesión y por lo tanto no hay `app.tenant_id` que poner.

Dos compensaciones:

1. **La vista `vw_empresas_publicas`** (migración
   `2026_10_17_000012_activar_row_level_security.php`, que expone `slug`, `nombre`
   y `activo` de las empresas activas). **Hoy queda sin uso**. El código lee la
   **tabla** `tenants`: `App\Services\Auth\VinculadorDeIdentidad::empresaDeCorreo`
   resuelve la empresa por el dominio del correo con
   `Tenant::query()->where('activo', true)->where('slug', $dominio)->first()`, que
   trae la fila completa sin `select()`. Este almacén **no tiene selector de
   empresa**: la empresa se resuelve sola por el dominio de la cuenta. La vista no
   se eliminó: se conserva como defensa en profundidad y como respaldo para cuando
   se decida activar la protección.

   Sobre lo que se expone: la tabla `tenants` guarda `razon_social`, pero **no
   guarda correos ni teléfonos**, así que lo que sale de esta consulta son columnas
   de identificación (`slug`, `nombre`). El dominio del correo es un dato del
   proveedor de identidad, no de la base del almacén, y en este almacén tampoco hay
   datos de pacientes.

   **Pendiente, no comprobado:** activar la vista exige revisar qué espera la
   interfaz del acceso y si la fila completa aporta algo que la vista no traiga. No
   se ha podido comprobar contra una base de datos porque no había una disponible,
   así que la decisión sigue abierta. Una versión anterior de este documento
   afirmaba que la aplicación leía la vista; era falso y se corrige aquí.
2. **El rol de la aplicación no es superusuario.** Quien tenga acceso SQL
   directo puede leer `tenants` entera. Por eso el despliegue exige revisar
   los permisos y `almacen:verificar-aislamiento` comprueba que el rol no sea
   superusuario ni tenga `BYPASSRLS`.

---

## 4. Doble filtro dentro de la empresa

La RLS garantiza que nada sale de la empresa. Dentro de la empresa hay un
segundo filtro, en las Policies.

| Acción | `preparador` | `jefe_almacen` | `administrador` | `auditor` |
|---|:---:|:---:|:---:|:---:|
| Ver artículos, stock, ubicaciones | Sí | Sí | Sí | Sí |
| Ver movimientos e historial | Sí | Sí | Sí | Sí |
| Crear documentos (borrador) | Sí | Sí | Sí | No |
| Confirmar documentos (mueve stock) | Sí | Sí | Sí | No |
| Reservar y liberar pedidos | Sí | Sí | Sí | No |
| Armar oleadas y marcar recogido | Sí | Sí | Sí | No |
| **Anular documento confirmado** | No | **Sí** | **Sí** | No |
| **Cerrar conteo con diferencia** | No | **Sí** | **Sí** | No |
| Desactivar artículo o ubicación | No | No | **Sí** | No |
| Crear zonas, motivos y unidades | No | No | **Sí** | No |
| Cambiar roles de personas | No | No | **Sí** | No |

La línea que importa está en negrita: **anular y cerrar cambian la historia
del stock**. Por eso están arriba. Preparar y despachar son el día a día del
almacén y los puede hacer cualquiera que opere.

Una regla adicional: **nadie se sube su propio rol**
(`UsuarioPolicy::cambiarRol()` lo rechaza). La separación de poderes también
aplica dentro de la aplicación.

### 404, no 403

Cuando una Policy rechaza, la respuesta es **404, no 403**: un 403 confirmaría
que el recurso existe. Como el filtro por empresa impide que el recurso exista
a ojos de la otra, 404 es además la respuesta correcta.

Está configurado en `api/bootstrap/app.php`.

---

## 5. Qué comprueba `almacen:verificar-aislamiento`

Se corre en cada despliegue y en cada arranque (el entrypoint lo invoca).

1. Que el rol **no** sea superusuario ni tenga `BYPASSRLS`.
2. Que todas las tablas de negocio tengan RLS **habilitado y forzado**, y su
   política con `USING` y `WITH CHECK`.
3. Que la función de contexto **siga fallando cerrada** sin `app.tenant_id`.
4. Que exista el gatillo `gatillo_saldos_solo_por_movimiento`.

---

## 6. Riesgos que siguen abiertos

Con todas las letras:

1. **No hay revisión de seguridad externa** ni pruebas de penetración.
2. **Las pruebas de aislamiento están escritas pero no se ejecutaron** (no
   había PostgreSQL disponible). Ver
   [10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).
3. El `slug` de una empresa se puede enumerar antes de autenticarse. Un
   atacante puede descubrir que Hefesto opera un almacén para cierta empresa,
   pero no ve más que su nombre. Si eso se considera inaceptable, la
   alternativa es dejar el campo en blanco hasta el acceso y resolver siempre
   por dominio de correo. Queda anotado como decisión pendiente del cliente,
   no como algo resuelto.
4. Los operadores de almacén acceden desde equipos compartidos en la bodega.
   El uso de un perfil de Windows por operario, no una cuenta compartida, es
   la primera condición para que la bitácora diga algo útil.

---

## 7. Los dos roles de PostgreSQL

La base de este almacén corre con **dos** roles, no con uno. La separación es
deliberada: es lo que hace que todo lo de este documento funcione en vez de
quedar como decoración.

### Qué es cada rol

| Rol | Nombre | De dónde sale | Para qué sirve | ¿Lo usa la aplicación? |
|---|---|---|---|---|
| **Arranque** | `almacen_admin` | `POSTGRES_USER` | Crear la base y las tareas administrativas del despliegue | **Nunca** |
| **Aplicación** | `almacen_app` | `DB_USERNAME`, creado por `deploy/postgres/inicializar-rol.sh` | Todo el tráfico de la API, del planificador y del trabajador de colas | **Sí, este y solo este** |

El rol de arranque no es una convención del estudio: la imagen oficial de
PostgreSQL ejecuta `initdb --username="$POSTGRES_USER"`, así que ese rol nace
**superusuario**. Por eso su contraseña va en `DB_ADMIN_PASSWORD`, que es una
variable distinta de `DB_PASSWORD`. Si se mezclaran, la aplicación heredaría
el superusuario y volveríamos a tener un solo rol.

### Por qué con un solo rol no servía

La documentación de PostgreSQL 18 lo dice de forma textual:

> *«Superusers and roles with the `BYPASSRLS` attribute always bypass the row
> security system when accessing a table. Table owners normally bypass row
> security as well, though a table owner can choose to be subject to row
> security with `ALTER TABLE ... FORCE ROW LEVEL SECURITY`.*

La segunda frase es la clave. `FORCE ROW LEVEL SECURITY` sujeta al **dueño** de
la tabla, pero **no al superusuario**. Mientras la aplicación conectara con el
rol de `POSTGRES_USER`, ninguna política de este documento se iba a aplicar:
el almacén se habría comportado como una sola empresa, sin error, sin aviso y
sin consulta lenta que lo delatara. El aislamiento entre empresas era
decorativo.

### Los atributos van todos escritos

`deploy/postgres/inicializar-rol.sh`, montado en
`/docker-entrypoint-initdb.d/`, crea el rol de aplicación con `NOSUPERUSER`,
`NOCREATEDB`, `NOCREATEROLE`, `NOINHERIT` y `NOBYPASSRLS` **explícitos
todos**, aunque `NOBYPASSRLS` ya sea el valor por omisión.

Se escriben igual a propósito: un rol verificado no debería depender de un
valor por omisión que una versión nueva puede cambiar. Lo que se lee en el
script es lo que queda, sin tener que saber qué cambió en la versión
siguiente.

El script también falla ruidosamente si falta `POSTGRES_DB`, `APP_DB_ROLE` o
`APP_DB_PASSWORD`. Es preferible un despliegue detenido a una base con un rol
de contraseña vacía.

### Por qué el rol de aplicación es dueño de la base

Justo después de crearlo, el script corre:

```sql
ALTER DATABASE almacen OWNER TO almacen_app;
```

Las migraciones de Laravel se ejecutan con el rol de la aplicación y
necesitan **crear tablas**. La única forma de darles ese permiso sin
convertirlos en superusuario es que ese rol sea el dueño de la base. Desde
PostgreSQL 15 el esquema `public` pertenece al dueño de la base, así que con
esto el rol ya puede crear tablas ahí.

Y aquí está el punto exacto del diseño, que conviene no pasar por alto: al
ser el dueño, **las tablas quedan a su nombre**, y por eso el
`FORCE ROW LEVEL SECURITY` que usan las tablas sí lo sujeta. Ser dueño no es
saltarse la política; es justamente lo que hace que la política lo alcance. Un
superusuario, en cambio, la saltearía siempre.

### Cómo se comprueba

```
php artisan almacen:verificar-aislamiento
```

El comando consulta `pg_roles` sobre el rol de la conexión y falla con código
1 si ese rol es superusuario o tiene `BYPASSRLS`. Después revisa que todas las
tablas de negocio tengan RLS habilitada y forzada, que su política tenga
`USING` y `WITH CHECK`, que la función de contexto siga fallando cerrada sin
`app.tenant_id`, y que exista el gatillo
`gatillo_saldos_solo_por_movimiento`.

**Este comando no se ha ejecutado.** En el equipo donde se escribió este
cambio no había PostgreSQL ni Docker. El diseño es correcto y está sostenido
en la documentación oficial, pero nadie lo ha corrido. Ver
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).