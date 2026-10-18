# 05 - Configuración

---

## 1. De dónde sale cada cosa

| Tipo | Dónde | Ejemplo |
|---|---|---|
| Variables de entorno | `.env` / `.env.prod` | Credenciales, URLs |
| Reglas del almacén | `api/config/almacen.php` (lee variables de entorno) | Horas de validez de una reserva |
| Identidad | `api/config/authentik.php` | Issuer, cliente, grupos |
| Comportamiento del planificador | `api/routes/console.php` | Cada cuánto se liberan reservas |

`api/.env.example` y `web/.env.example` son las plantillas de cada parte.
La raíz `.env.example` es la que usan los compose.

---

## 2. Variables obligatorias

Sin estas el contenedor **no arranca** (el `entrypoint` falla con un mensaje
explícito, en vez de levantar un contenedor que responde 500 en todas
partes).

| Variable | Para qué |
|---|---|
| `APP_KEY` | Cifrado de sesión y cookies |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_HOST` | Conexión a PostgreSQL |
| `AUTHENTIK_ISSUER` | Identificador del proveedor de identidad |
| `AUTHENTIK_CLIENT_ID`, `AUTHENTIK_CLIENT_SECRET` | Credenciales de la aplicación en Authentik |
| `AUTHENTIK_REDIRECT_URI` | A dónde vuelve el navegador después de entrar |

Generación de los secretos:

```sh
openssl rand -base64 48                    # APP_KEY
openssl rand -base64 60 | tr -d '\n'       # AUTHENTIK_SECRET_KEY
openssl rand -hex 32                       # AUTHENTIK_BOOTSTRAP__TOKEN
```

Ojo: PostgreSQL no acepta contraseñas de más de 99 caracteres
(limitación documentada por Authentik).

---

## 3. Variables con valor por omisión

Tienen un valor que funciona y conviene revisar según el cliente.

| Variable | Omisión | Qué significa en el almacén |
|---|---|---|
| `ALMACEN_HORAS_VALIDEZ_RESERVA` | 72 | Horas que queda comprometida la mercadería antes de volver a estar disponible |
| `ALMACEN_DIAS_ENTRE_CONTEO` | 30 | Días entre dos conteos de la misma zona. **Avisa, no bloquea** |
| `ALMACEN_PARADAS_MAXIMAS_POR_OLEADA` | 120 | Tamaño máximo de una vuelta de picking |
| `SESSION_DRIVER` | `database` | Dónde queda la cookie de sesión |
| `SESSION_SECURE_COOKIE` | `false` en desarrollo | En producción **obligatoriamente** `true` |
| `AUTHENTIK_ALGORITMO` | `RS256` | Algoritmo de firma del token |
| `LOG_LEVEL` | `info` | Nivel de detalle del registro |

### Sobre las horas de validez de una reserva

Es la decisión más discutible del archivo, y conviene que la tome el cliente
con su operación, no con un default.

| Valor | Efecto |
|---|---|
| 24 h | Poco riesgo de stock bloqueado, más reservas vencidas en pedidos válidos |
| 72 h | Punto medio |
| 168 h | Para ventas con clientes que esperan mucho antes de retirar |

Se puede cambiar por empresa si el cliente lo necesita; la instalación tiene
un solo número para que sea más fácil explicar «la reserva dura 72 horas».

### Sobre los días entre conteos

El sistema **avisa**, no bloquea. Impedir un conteo por tiempo sería inventar
una regla de negocio que es del jefe de almacén, no del software. La
sugerencia aparece en el tablero: «zonas para contar» ordena por cuál lleva
más días sin contarse.

---

## 4. Reglas que viven en el código, no en el `.env`

Estas no son parámetros: son decisiones del producto, y por eso no se
configuran por entorno.

| Regla | Dónde | Por qué |
|---|---|---|
| El stock no se edita, se mueve | Gatillo `gatillo_saldos_solo_por_movimiento` | La garantía no puede depender de que alguien se acuerde |
| `disponible` es derivado | `Saldo::getDisponibleAttribute()` | Una columna calculada se desactualiza |
| El zigzag de la ruta alterna por pasillo | `ArmadorDeRuta` | El recorrido continuo por pasillo es la garantía |
| Una ubicación con stock no se desactiva | `UbicacionController::desactivar()` | Una ubicación sin dirección es el problema que esto resuelve |
| Un traspaso no se anula | `ServicioDeDocumentos::anular()` | Se revierte con un traspaso de vuelta, para que la historia muestre el camino |
| Una diferencia sin justificación no cierra el conteo | `ServicioDeConteos::cerrar()` | Un número que no cuadra sin explicación es un número que nadie va a mirar |
| La reserva vence sola | Planificador cada 5 minutos | Sin esto, el almacén promete mercadería que ya salió |

---

## 5. Configuración de Authentik

En Authentik hay que crear una aplicación de tipo cliente regular con:

- **Redirect URI**: el valor de `AUTHENTIK_REDIRECT_URI`
- **Scope**: `openid profile email`

Dos grupos:

| Grupo | Para qué |
|---|---|
| `almacen-operadores` | Todo el personal autorizado a entrar |
| `almacen-administradores` | Solo administración (ayuda visual, no permisos) |

**El grupo de Authentik no da permisos.** El rol que autoriza cada acción
vive en `usuarios.rol`, que es del almacén. El grupo sirve para que el alta y
la baja de personas sea auditable en el proveedor de identidad.

### La barra del emisor

`AUTHENTIK_ISSUER` debe terminar en barra. Authentik lo pública así, y una
diferencia de una barra hace que **todos** los tokens parezcan de otro emisor
y nadie pueda entrar.

---

## 6. El planificador

`api/routes/console.php`:

| Tarea | Frecuencia | Qué hace si falla |
|---|---|---|
| Liberar reservas vencidas | Cada 5 minutos | Avisa por registro; la unidad queda reservada hasta la próxima pasada |
| Liberar reservas vencidas (segunda pasada) | 7:00 | Red de seguridad |
| Conciliar saldos | 3:00 | Avisa. No corrige nada |
| Verificar aislamiento | Cada hora | Avisa |

Todas usan `withoutOverlapping()`: si el almacén está lento, la siguiente
pasada espera en vez de liberar dos veces la misma unidad.

El planificador corre en el contenedor `cola`
(`php artisan schedule:work`). **Si ese contenedor está detenido, las
reservas no se liberan solas** y el almacén sigue despachando igual, en
silencio. Es la falla más difícil de detectar de todo el sistema.

---

## 7. Ajustar una instalación ya existente

### Cambiar el rol de una persona

```sql
UPDATE usuarios SET rol = 'jefe_almacen' WHERE email = 'preparador@empresa.cl';
```

Fuera de la interfaz y **dentro del contexto de empresa**, que es lo que
hace `almacen:crear-persona` y la pantalla de administración.

### Crear una empresa nueva

```sql
INSERT INTO tenants (id, slug, nombre, razon_social, activo, created_at, updated_at)
VALUES (gen_random_uuid(), 'bodega-san-bernardo.hefestostudio.cl',
        'Bodega de San Bernardo', 'Comercial San Bernardo Ltda.', true, now(), now());
```

`tenants` es la excepción consciente sin RLS, así que esta inserción se
puede hacer desde `psql` sin contexto. Después hay que crear sus personas,
sus unidades y sus zonas: el sistema no comparte catálogos entre empresas a
propósito.

### Reiniciar el caché de la identidad

Si Authentik rota una llave y el acceso falla para todos:

```sh
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  run --rm api php artisan cache:clear
```

El verificador ya reintenta una vez con el JWKS recién bajado, así que esto
solo hace falta si la rotación ocurrió hace más del tiempo de caché.

---

## 8. Variables que nunca deben tocarse en producción

| Variable | Por qué |
|---|---|
| `APP_DEBUG=false` | Con depuración activa, un error puede mostrar rutas internas o consultas SQL |
| `SESSION_SECURE_COOKIE=true` | Sin esto, la cookie de sesión viaja en claro |
| El rol de la base sin `BYPASSRLS` | Con cualquiera de los dos, PostgreSQL ignora todas las políticas RLS |
| `ALMACEN_CORRER_MIGRACIONES=false` | Un despliegue que cambia el esquema solo, sin que nadie lo decida, puede cortar el servicio |