# 02 - Modelo de datos

---

## 1. El mapa de tablas

Diecisiete tablas de negocio llevan `tenant_id` y Row Level Security.

| Grupo | Tablas |
|---|---|
| Identidad | `tenants` (sin RLS), `usuarios` |
| Catálogos | `zonas`, `motivos_movimiento`, `unidades_medida`, `articulos` |
| Físico | `ubicaciones`, `saldos` |
| Historia | `movimientos_stock` |
| Documentos | `documentos`, `documento_items` |
| Pedidos | `pedidos`, `pedido_items`, `reservas` |
| Picking | `oleadas`, `oleada_items` |
| Inventario | `conteos`, `conteo_items` |

Las migraciones están en `api/database/migrations/` y van numeradas del 1 al
12. El orden importa: `movimientos_stock` referencia `conteos`, y por eso
`conteos` se crea primero. La llave foránea de `conteo_items.movimiento_id`
se agrega al final de la migración de movimientos, porque en la de conteos la
tabla todavía no existe.

---

## 2. Las tres cifras del stock

La tabla `saldos` es el corazón del modelo.

```php
cantidad_fisica     decimal(18,6)  lo que hay en el rack
cantidad_reservada  decimal(18,6)  lo comprometido a un pedido
disponible                        DERIVADO: fisica - reservada
```

**`disponible` no existe como columna.** Es un atributo calculado:

```php
public function getDisponibleAttribute(): string
{
    return Cantidad::restar($this->cantidad_fisica, $this->cantidad_reservada);
}
```

Si fuera una columna, habría dos verdades: el saldo y la disponibilidad. Y
la disponibilidad siempre queda vieja primero, porque depende de tres cosas
que cambian por lo menos (reservas que vencen, pedidos que se despachan,
ajustes de conteo).

### Restricciones en la base

No son validación de PHP: son `CHECK` de PostgreSQL, así que valen también
para un `UPDATE` escrito a mano.

```sql
CHECK (cantidad_fisica >= 0)
CHECK (cantidad_reservada >= 0)
CHECK (cantidad_reservada <= cantidad_fisica)
```

La tercera es la importante: no se puede comprometer más de lo que hay en el
rack. La restricción de nuevo se repite en PHP, en
`RegistroDeMovimiento::reservar()`, para dar un mensaje que un humano pueda
leer: «No se puede reservar 15 en 1-01-01-01: hay 10 en el rack».

### El gatillo

```sql
CREATE TRIGGER gatillo_saldos_solo_por_movimiento
BEFORE INSERT OR UPDATE ON public.saldos
FOR EACH ROW
EXECUTE FUNCTION public.almacen_saldo_solo_por_movimiento();
```

La función revisa `current_setting('app.movimiento_stock', true)`. Si no es
`'1'`, lanza excepción. Solo `RegistroDeMovimiento` pone esa variable, y lo
hace con `set_config(..., true)`, o sea **con alcance de transacción**.

---

## 3. Unidades de medida: donde se resuelve el error clásico

El error clásico del almacén es confundir caja con unidad. Un pedido de 12
ejes que se despacha como 12 cajas son 132 piezas de diferencia, y nadie lo
detecta hasta que falta mercadería.

La solución no es un campo de texto libre. Es aritmética:

```
cantidad_base = cantidad / unidades_por_base
```

| Código | Nombre | `unidades_por_base` |
|---|---|---|
| `UND` | Unidad | 1 |
| `CAJA-12` | Caja de 12 | 12 |
| `CAJA-24` | Caja de 24 | 24 |
| `PALLET-48` | Pallet de 48 | 48 |

### El factor vive en la unidad, no en el artículo

Esta es la decisión más importante del modelo.

Si cada artículo guardara su propio factor («mi caja son 12»), habría dos
verdades para la misma caja y el problema volvería, con más pasos. En cambio:

- La unidad dice cuántas unidades base trae **una** unidad.
- El artículo **elige** tres unidades del catálogo: base, compra y venta.

Dos artículos que se venden en cajas de 12 y de 24 usan dos unidades
distintas (`CAJA-12` y `CAJA-24`), y cada una dice la verdad una sola vez.

### El stock se guarda en unidad base

Nunca en cajas ni en pallets. `saldos.cantidad_fisica` siempre está en
unidad base, así que la historia no depende de con qué unidad se mire.

`ConversorDeUnidades::verificarArticulo()` impide que un artículo tenga una
unidad base que no sea base: si lo fuera, todo su stock quedaría guardado en
cajas y la conversión de la historia sería distinta cada vez que se tocara la
unidad.

### La pregunta que se hace todos los días

«¿Una caja de ejes son seis unidades de venta?» La respuesta sale de las
unidades, no de un campo escrito a mano:

```php
$articulo->factorCompraVenta()  // 12 / 12 = 1 para compra CAJA-12, venta UND
```

---

## 4. Movimientos: el libro

```php
cantidad_base    decimal(18,6)  CON SIGNO: positiva suma, negativa resta
grupo_traspaso   uuid nullable  el par de un traspaso
motivo_id        obligatorio    no hay movimiento sin motivo
```

El signo es lo que permite una conciliación trivial: el saldo de una
ubicación **es** la suma de los movimientos de esa ubicación. Eso es
literalmente lo que comprueba `almacen:conciliar`.

### Solo agregado

Hay un gatillo que prohíbe `UPDATE` y `DELETE` sobre `movimientos_stock`, y
el modelo declara `const UPDATED_AT = null` para que Eloquent tampoco lo
intente. Para corregir algo se escribe el movimiento contrario.

---

## 5. Traspasos

Un traspaso son **dos movimientos** con el mismo `grupo_traspaso`:

```sql
-- salida
INSERT INTO movimientos_stock (..., tipo, ubicacion_id, cantidad_base, grupo_traspaso)
VALUES (..., 'traspaso_salida', 'origen', -40.000000, 'uuid-comun');

-- entrada
INSERT INTO movimientos_stock (..., tipo, ubicacion_id, cantidad_base, grupo_traspaso)
VALUES (..., 'traspaso_entrada', 'destino',  40.000000, 'uuid-comun');
```

En la misma transacción. Si la segunda falla, la primera tampoco queda. El
error típico —sumar al destino sin descontar del origen— no tiene forma de
ocurrir.

Para el historial, `MovimientoStock::contraparte()` trae el otro movimiento
del grupo: si vuelve `null`, hay un traspaso a medias.

---

## 6. Documentos

Un documento es la **intención** del usuario. El stock se mueve cuando se
confirma.

| Tipo | Ubicación que usa | Confirmar genera |
|---|---|---|
| `entrada_compra` | `ubicacion_destino_id` | Un ingreso |
| `salida_venta` | `ubicacion_origen_id` | Una salida |
| `traspaso` | Las dos, y distintas | Un par de movimientos |

Estas reglas no se pueden expresar con una restricción simple de PostgreSQL
(dependen de otra fila), así que las valida `ServicioDeDocumentos` antes de
mover una sola unidad.

`documento_items` guarda **dos** cifras:

- `cantidad` con la unidad con que la escribió el usuario («3 cajas»)
- `cantidad_base` en unidad base (36)

Para poder mostrar el documento como fue escrito y calcular el movimiento
sin volver a convertir. La conversión se hace **una vez**, al crear la línea.

Estados: `borrador` (editable) → `confirmado` (ya no se toca) → `anulado`
(generó movimientos inversos).

---

## 7. Reservas

```php
saldo_id        qué rack se compromete
cantidad_base   cuánta mercadería
expira_en       CUÁNDO se libera sola
estado          vigente | consumida | liberada | vencida
```

La reserva se hace **sobre un saldo concreto**, no «sobre el artículo». Por
eso el picking puede armar una ruta sin volver a preguntar dónde está nada.

El índice `(tenant_id, estado, expira_en)` existe para el trabajo de
vencimiento: filtra por estado y ordena por vencimiento, así que no escanea
toda la tabla en cada pasada.

`vencida` y `liberada` son estados distintos a propósito. La diferencia entre
«la liberó una persona» y «se venció sola» es la primera pregunta que hace
el jefe de almacén cuando falta mercadería.

---

## 8. Rutas de picking

`oleada_items` es la ruta ya ordenada:

```php
saldo_id     de qué rack se saca (viene de la reserva: ya está resuelto)
secuencia    orden de paso, UNICA dentro de la oleada
```

`UNIQUE (oleada_id, secuencia)` está en la base: dos paradas con el mismo
número serían dos paradas en el mismo punto, que es un error de planificación
que no se deja pasar.

---

## 9. Conteos cíclicos

```php
cantidad_sistema   la foto congelada al abrir el conteo
cantidad_contada   lo que vio la persona
diferencia         cantidad_contada - cantidad_sistema, al cerrar
justificacion      texto escrito, obligatorio si hay diferencia
movimiento_id      el ajuste que se generó al cerrar
```

La foto **se conserva** aunque después el ajuste cambie el saldo: es la
evidencia de contra qué se comparó lo que contó la persona.

El bloqueo de la zona no es una restricción de la base: lo aplica
`RegistroDeMovimiento::verificarConteoAbierto()` antes de escribir, porque
el movimiento de ajuste del propio conteo tiene que poder pasar. Si fuera una
restricción de SQL, ningún conteo podría cerrarse nunca.

---

## 10. Decisiones que se descartaron

| Alternativa | Por qué no |
|---|---|
| Un solo campo de stock | Se desactualiza y el almacén promete mercadería que salió |
| Factor de conversión en el artículo | Dos verdades para la misma caja; el error vuelve |
| Cantidad en caja en la línea de pedido | El picking y la ruta dejan de poder ordenar por magnitudes comparables |
| Traspaso como «ajuste del destino» | El error típico: sumar sin descontar |
| Bloquear la zona con una restricción SQL | Ningún conteo podría cerrarse |
| `disponible` como columna calculada por trigger | Oculta la regla; mejor un atributo derivado y visible |

---

## 11. Decimales y aritmética

Las cantidades se guardan como `decimal(18,6)`: seis decimales alcanzan
para la fracción de un pallet que se maneje en un almacén corriente, y
evitan el error de coma flotante al sumar miles de movimientos.

En PHP, `App\Support\Cantidad` compara con `bcmath` cuando la extensión está
disponible (el Dockerfile la instala) y con punto flotante cuando no. La
diferencia de comportamiento está anotada en
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).

Si no hay `bcmath`, la comparación tiene una tolerancia de `0.000001`.