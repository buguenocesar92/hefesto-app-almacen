# 06 - Primer ingreso

> Recorrido guiado desde la bodega vacía hasta la ruta de picking
> impresa. Es el camino que hace falta para entender el sistema en una
> sentada, y también la lista de lo que hay que preparar antes de recibir
> mercadería de verdad.

---

## Antes de empezar: el orden importa

En un almacén no se puede inventar el mapa. Estas son las decisiones que
tomar, en orden:

| # | Decisión | Quién la toma |
|---|---|---|
| 1 | Qué artículos maneja la bodega | Jefe de almacén |
| 2 | En qué unidades se compran y en cuáles se venden | Jefe de almacén |
| 3 | Cómo se codifican los pasillos y racks | Jefe de almacén |
| 4 | Qué ubicación tiene cada artículo | Jefe de almacén |
| 5 | Qué motivos de movimiento existen | Jefe de almacén |
| 6 | Cómo se agrupan las zonas para el conteo | Jefe de almacén |

**La aplicación no decide ninguna de estas seis.** Guarda lo que se le diga y
lo devuelve. Si el rack A-03 nivel 2 lo ocupa el motor y no el arnés, eso lo
sabe el jefe de almacén.

---

## 1. Crear las unidades de medida

Administración → Unidades de medida.

Es el paso donde se resuelve el error clásico del almacén, así que conviene
hacerlo bien y despacio.

| Código | Nombre | Unidades por unidad base |
|---|---|---|
| `UND` | Unidad | 1 |
| `CAJA-12` | Caja de 12 | 12 |
| `CAJA-24` | Caja de 24 | 24 |
| `PALLET-48` | Pallet de 48 | 48 |

**El factor se escribe una sola vez y no se toca después.** Si un artículo se
vende en cajas de 12, usa `CAJA-12`. Si otro se vende en cajas de 24, usa
`CAJA-24`. No existe un «mi caja son 12» por artículo: el factor vive en la
unidad, para que haya una sola verdad.

La unidad base del sistema es `UND`. Ningún artículo puede guardar stock en
cajas: el stock se lleva en unidades, siempre.

> Si se escribe mal el factor de una unidad, todos los números de stock de
> los artículos que la usan quedan mal, y el error aparece semanas después
> sin forma de saber de dónde vino.

---

## 2. Crear las zonas

Administración → Zonas.

Las zonas son el agrupamiento de pasillos. Se usan para dos cosas:

- **Conteo cíclico**: se cuenta una zona, no todo el almacén de una vez.
- **Opcionalmente, picking**: una oleada puede limitarse a una zona para no
  obligar al operario a atravesar la bodega entera.

Ejemplo para una bodega de seis pasillos:

| Código | Nombre | Pasillos |
|---|---|---|
| `A` | Reposición rápida | 1 a 3 |
| `B` | Reposición lenta y pesada | 4 a 6 |
| `C` | Recepción y despacho | — |

La zona `C` es un buen ejemplo de por qué las zonas existen: la mesa de
empaque no guarda mercadería, pero sí hay que contarla.

---

## 3. Crear las ubicaciones

Ubicaciones.

Una ubicación es una dirección: **pasillo, rack, nivel y posición**. El
sistema arma el código solo a partir de las partes:

```
pasillo: 1
rack:    03
nivel:   2
posicion: 1
   ↓
codigo: 1-03-02-01
codigo_barras: ALM-1-03-02-01
```

El código de barras se **imprime** en la etiqueta del rack y se escanea en el
piso. El sistema guarda el código; no lo dibuja.

Códigos que funcionan bien:

- `1-03-02-01` — pasillo 1, rack 3, nivel 2, posición 1
- `RACK-A-01` — si se quiere letra de zona

Lo que no funciona es un texto libre tipo «estantería de los tornillos»: es
exactamente el problema que el sistema existe para evitar.

> Los códigos son únicos **por empresa**, no globalmente. Dos bodegas pueden
> tener las dos la ubicación `1-03-02-01` sin que esten compitiendo por el
> mismo sticker.

---

## 4. Crear los motivos de movimiento

Administración → Motivos de movimiento.

Todo movimiento lleva motivo. Los que llevan «exige justificación» van a
obligar a escribir el porqué cuando haya una diferencia de conteo.

| Código | Nombre | Sentido | Exige justificación |
|---|---|---|---|
| `RECEPCION` | Recepción de compra | ingreso | No |
| `DESPACHO` | Despacho por venta | salida | No |
| `TRASPASO` | Traspaso entre ubicaciones | ajuste | No |
| `MERMA` | Merma por rotura o vencimiento | ajuste | **Sí** |
| `AJUSTE_SISTEMA` | Corrección administrativa | ajuste | **Sí** |
| `DEVOLUCION` | Devolución de cliente | ingreso | No |
| `MUESTRA` | Muestra o corte | salida | **Sí** |

`RECEPCION`, `DESPACHO` y `TRASPASO` son **necesarios**: sin ellos, confirmar
un documento falla con un mensaje claro.

El criterio para poner «exige justificación» es simple: **cuando el motivo se
puede repetir, la explicación es obligatoria**. La merma se repite; el
despacho a un cliente no.

---

## 5. Crear los artículos

Artículos → Nuevo.

Aquí es donde se decide la unidad de cada cosa, y es la decisión que más
tiempo ahorra después:

| Artículo | Unidad base | Se compra en | Se vende en |
|---|---|---|---|
| Eje de acero 6204 | UND | CAJA-12 | UND |
| Manual de mantenimiento | UND | CAJA-24 | CAJA-24 |
| Filtro de aire ANF-9 | UND | PALLET-48 | UND |

Con esa tabla, un pedido de «6 cajas de ejes» se traduce solo en 72 unidades.
Nadie tiene que hacer la cuenta, y el sistema no la puede hacer mal.

El factor compra/venta aparece en la ficha: una unidad de compra de ejes son
1,000000 unidades de venta. Si el artículo se vendiera en cajas y se
comprara en unidades, saldría 0,083333.

---

## 6. Recibir la primera mercadería

Recepción y despacho → Nuevo documento.

1. **Tipo**: entrada de compra
2. **Fecha** y **referencia** (el número de orden de compra)
3. **Líneas**: artículo, cantidad y **ubicación de destino**
4. **Guardar**: el documento queda en borrador y **no mueve nada**

Después de guardar, se puede revisar. Recién al **confirmar** se ejecuta la
recepción.

Al confirmar ocurre esto:

- Se crea un movimiento `ingreso` por línea, con el motivo `RECEPCION`
- El saldo de esa ubicación sube
- El documento pasa a `confirmado` y ya no se edita

Si la recepción se digita mal, se **anula** (con rol de jefe de almacén): eso
escribe el movimiento contrario. No se borra nada.

---

## 7. Ver dónde está

La pregunta para la que existe el sistema:

> «Tenemos 400 unidades de ejes. ¿Dónde están?»

Artículos → Eje de acero 6204.

La ficha muestra tres cifras, no una:

| En el rack | Reservado | Disponible |
|---|---|---|
| 480 | 72 | 408 |

Y abajo, **dónde está**: cada ubicación con su código de barras, su zona y
sus tres cifras, en el orden en que conviene recorrerlas.

Esa consulta es la razón del módulo. Un sistema que dice «480» obliga a media
hora de búsqueda antes de servir al primer cliente que las pide.

---

## 8. Crear un pedido y reservarlo

Pedidos → Nuevo.

1. **Número** (el que usa el cliente) y **referencia externa** (la orden de
   venta)
2. **Destinatario** y **fecha de entrega**
3. **Líneas**: artículo y cantidad **en la unidad en que se pidió**

Al reservar, el sistema compromete stock disponible y le pone fecha de
vencimiento (72 horas por omisión). La reserva se hace **sobre un rack
concreto**, no sobre el artículo entero: por eso la ruta ya sabe dónde
está cada cosa.

Si nadie confirma el pedido, la reserva **se libera sola** a las 72 horas y la
mercadería vuelve a estar disponible. La reserva queda como `vencida`, que no
es lo mismo que `liberada`: esa diferencia es la primera pregunta que hace
el jefe de almacén cuando falta mercadería.

---

## 9. Armar y recorrer la ruta

Picking → Nueva oleada.

1. **Nombre**, **fecha** y, si conviene, **zona**
2. **Abrir la oleada** y pulsar **Armar ruta**

La ruta se arma sola, ordenada por ubicación:

```
  1. 1-01-01-01  | EJE-6204  | 36 und. | pedido PED-1001
  2. 1-02-01-01  | EJE-6204  | 36 und. | pedido PED-1001
  3. 2-01-01-01  | MAN-REF   | 24 und. | pedido PED-1002
  4. 2-01-01-02  | COR-8M    | 10 und. | pedido PED-1003
```

Cada pasillo se recorre una sola vez, de punta a punta o al revés según
corresponda. El operario imprime y sigue el papel: no tiene que decidir nada.

Marcar una parada como **recogida** no mueve stock: la mercadería sigue en el
rack hasta que sale del almacén. El despacho es lo que la saca.

---

## 10. Contar una zona

Inventario físico → Abrir conteo.

Al abrir el conteo pasan dos cosas:

1. Se congela una **foto** de los saldos de la zona.
2. La zona **deja de admitir movimientos** hasta que el conteo cierre o se
   cancele.

El bloqueo no es un capricho: si la mercadería se moviera mientras se cuenta,
la diferencia aparecería sola y nadie podría decir si fue merma, error de
conteo o movimiento real.

Para cada parada:

1. Se escanea el código de barras de la ubicación
2. Se escribe lo que se contó
3. Si el número **no** cuadra, se elige un motivo y **se escribe por qué**

Al cerrar, cada diferencia genera un **movimiento de ajuste** y queda atada a
su justificación. El sistema no corrige números: los deja constando para que
se puedan investigar.

Un conteo que cuadra no genera ningún movimiento.

---

## 11. Lo que quedó funcionando al terminar

Un almacén con:

- Unidades con conversión real, sin ambigüedad de caja contra unidad
- Ubicaciones con código de barras en cada rack
- Stock cargado por movimientos de recepción, con su historial
- Un pedido reservado con vencimiento
- Una ruta de picking ordenada e imprimible
- Un conteo cerrado con una diferencia justificada

Y la semenilla `db:seed` deja todo eso listo de una:

```sh
docker compose -f deploy/docker-compose.dev.yml --env-file .env \
  run --rm api php artisan db:seed
```