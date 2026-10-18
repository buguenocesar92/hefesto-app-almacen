# 01 - Arquitectura

> PostgreSQL corre con dos roles: uno de arranque y otro de aplicación. Solo el
> de aplicación conecta con el sistema, y es el que las políticas de
> aislamiento pueden alcanzar. Ver [03](03-MULTITENANCY-Y-ROLES.md).

---

## 1. Las tres capas

```
Navegador
   │  (misma origen, siempre)
   ▼
Nuxt 4 (SSR) ──── BFF: reenvía a la API, nunca el navegador
   │
   ▼
Laravel 13 ─────── reglas de negocio, autorización, validación
   │
   ▼
PostgreSQL ─────── Row Level Security + gatillos de stock
   │
   ▼
Redis ──────────── cache, colas y planificador
```

Authentik resuelve la identidad. El almacén no guarda contraseñas.

---

## 2. Arquitectura BFF, y por qué en un almacén no es un detalle

Nuxt corre en modo servidor y **reenvía** las llamadas a la API. El
navegador nunca habla directo con Laravel.

El motivo es concreto. La sesión vive en una cookie `HttpOnly` + `Secure` +
`SameSite=Lax`. El navegador solo manda esa cookie a **su mismo origen**.
Si la API respondiera en otro origen, habría que resolver CORS con
credenciales y el token CSRF se volvería frágil.

En un almacén esto no es purismo de seguridad web. Confirmar una recepción,
cerrar un conteo o despachar un pedido **escriben en el stock**. Que esas
acciones no se puedan falsificar con una petición suelta desde otro sitio es
lo que las hace confiables. El archivo que hace ese trabajo es
`web/server/api/[...ruta].ts`: copia las cookies, reenvía el token CSRF
como cabecera `X-XSRF-TOKEN` y devuelve las cookies de la respuesta.

Caddy (`deploy/caddy/Caddyfile`) es el único que pública puertos:
`/api/*` va a Laravel y todo lo demás a Nuxt.

---

## 3. El módulo que más importa: dónde está

La mercadería está en un rack, en un pasillo, en un nivel. `ubicaciones`
guarda esa dirección con partes consultables: `pasillo`, `rack`, `nivel`,
`posicion`, más un `codigo` legible y un `codigo_barras` único por empresa.

`saldos` guarda el stock de un artículo **en una dirección concreta**. No es
«el artículo tiene 400»: es «el artículo tiene 120 en 1-01-01-01, 180 en
2-03-01-01 y 100 en 5-02-01-01». Esa granularidad es la que permite armar
una ruta sin volver a preguntar nada.

El endpoint que responde a la pregunta del operario es:

```
GET /api/v1/articulos/{articulo}/ubicaciones
```

y sale en orden de recorrido, no en orden de creación.

---

## 4. Las tres cifras del stock

Este es el otro punto donde un almacén se puede equivocar de forma cara.

```
cantidad_fisica     lo que hay en el rack
cantidad_reservada  lo comprometido a un pedido
disponible          lo que se puede vender = fisica - reservada
```

`disponible` **no es una columna**. Es un atributo calculado
(`Saldo::getDisponibleAttribute`). Si fuera una columna, cualquier
movimiento la dejaría vieja y el almacén prometería mercadería que ya salió.

Un solo campo de stock es un error de diseño, no una simplificación.

---

## 5. El stock no se edita: se mueve

Hay tres capas, y la que importa es la tercera.

| Capa | Qué hace | Qué detecta |
|---|---|---|
| Aplicación | `RegistroDeMovimiento` es el único que escribe saldos | Errores de lógica |
| Modelo (`Saldo`) | Falla al guardar cantidades sin permiso | Errores de código |
| **PostgreSQL** | **Gatillo que rechaza la escritura** | **Todo lo que se le escape a las dos anteriores** |

El gatillo `gatillo_saldos_solo_por_movimiento` revisa la variable de
PostgreSQL `app.movimiento_stock`. Solo `RegistroDeMovimiento` la pone, y
con alcance de **transacción**: al confirmar o revertir, PostgreSQL la
descarta. Un humano con un `psql` en la mano tampoco puede saltárselo.

Cada variación deja una fila en `movimientos_stock` con su motivo. Sin
motivo no hay movimiento, y sin movimiento no hay variación.

Los movimientos son de **solo agregado**: hay un gatillo que prohíbe el
`UPDATE` y el `DELETE` sobre esa tabla. Si un movimiento estaba mal, se
escribe el contrario.

Verificado en `api/tests/Feature/Stock/StockInmutableTest.php`.

---

## 6. Flujo de una recepción

1. El usuario crea un **documento** en borrador con sus líneas. No pasa nada
   todavía: la mercadería sigue bajando del camión.
2. El usuario **confirma**. Ahí el servicio valida cada línea según el tipo
   de documento y llama a `RegistroDeMovimiento`:
   - `entrada_compra` → un ingreso en la ubicación de destino
   - `salida_venta` → una salida de la ubicación de origen
   - `traspaso` → **un par de movimientos** (ver sección 8)
3. Cada línea deja un movimiento con su motivo (`RECEPCION`, `DESPACHO`,
   `TRASPASO`).
4. Si hay que anular un documento confirmado, **no se borra nada**: se
   escriben movimientos de sentido contrario.

Un traspaso **no se anula**: se revierte con un traspaso de vuelta, para que
la historia muestre el camino completo.

---

## 7. Flujo de una ruta de picking

1. Un pedido se **reserva**: se compromete stock disponible y se le pone
   fecha de vencimiento. La reserva se hace sobre un **saldo concreto**,
   o sea, de un rack. Por eso la ruta ya sabe dónde está cada cosa.
2. Se crea una **oleada** y se **arma**: `ArmadorDeRuta` toma las reservas
   vigentes de los pedidos reservados y las ordena por ubicación.
3. El operario imprime la ruta y la recorre. Cada parada dice rack,
   código de barras, artículo y cantidad.
4. Marcar una parada como **recogida** no mueve stock: la mercadería sigue
   en el rack hasta que sale del almacén.
5. El **despacho** consume la reserva y saca la mercadería, en la misma
   operación.

### Cómo se ordena la ruta

Dos reglas, en este orden:

1. **Por pasillo, de corrido.** Esta es la garantía que importa: cada
   pasillo se recorre una vez, en un solo bloque. Sin esto, «400 unidades»
   se vuelven veinte viajes al mismo pasillo.
2. **Adentro del pasillo, en zigzag.** El primer pasillo se recorre de un
   extremo al otro; el siguiente, al revés. Es la forma clásica de recorrer
   una retícula sin volver sobre los pasos.

El zigzag alterna por pasillo según su índice en el recorrido: los pares
de punta a punta, los impares al revés. Es una **decisión de diseño**, no un
estándar de la industria. Lo que sí se comprueba con una prueba es que
ningún pasillo se parte en dos, y que el orden de pasillos es creciente
(`api/tests/Feature/Picking/RutaDePickingTest.php`).

El papel de picking sale de `ArmadorDeRuta::rutaImprimible()`.

---

## 8. Traspasos: un par de movimientos, nunca un ajuste

El error típico es sumar al destino sin descontar del origen. Aquí no puede
ocurrir: `RegistroDeMovimiento::traspasar()` escribe **siempre dos
movimientos** con el mismo `grupo_traspaso`, dentro de la misma transacción.
Si la segunda escritura falla, la primera tampoco queda.

```php
$par = $movimientos->traspasar($articulo, $origen, $destino, 40, $motivo);
// $par['salida']  -> resta en el origen
// $par['entrada'] -> suma en el destino
// mismo grupo_traspaso
```

Un traspaso a medias queda visible en la primera consulta: un
`traspaso_entrada` sin su par.

---

## 9. Inventario físico

Al abrir un conteo de una zona:

1. Se **congela una foto** de los saldos de esa zona
   (`conteo_items.cantidad_sistema`). Esa foto no vuelve a cambiar.
2. Se **bloquea** el movimiento en las ubicaciones de la zona
   (`RegistroDeMovimiento::verificarConteoAbierto()` lo rechaza).

Sin el bloqueo, el conteo compararía una foto contra un mundo que cambió
mientras la persona contaba: la diferencia aparecería sola y nadie podría
decir si fue merma, error de conteo o movimiento real.

Al cerrar, cada diferencia necesita **motivo y explicación escrita**, y se
corrige con un movimiento de ajuste. El sistema no corrige números.

Ver `api/tests/Feature/Conteos/ConteoCiclicoTest.php`.

---

## 10. Reservas que expiran

El error caro de un almacén no es que falte stock: es **prometer stock que
ya salió**. Un pedido que nadie confirma mantiene la mercadería reservada
para siempre, y el almacén cree que tiene disponible lo que ya está en el
camión de otro.

Por eso `reservas.expira_en` no es decorativo. El planificador corre
`almacen:liberar-reservas-vencidas` cada cinco minutos, y el trabajo en cola
`LiberarReservasVencidasJob` hace lo mismo a las 7 de la mañana. La reserva
que expira queda en estado `vencida`, **no** `liberada`: la diferencia entre
«la liberó una persona» y «se venció sola» es justo la que después pregunta
el jefe de almacén.

Ver `api/tests/Feature/Stock/ReservaVencidaTest.php`.

---

## 11. Los comandos que sostienen el sistema

| Comando | Cuándo | Qué hace |
|---|---|---|
| `almacen:verificar-aislamiento` | En cada despliegue y arranque | Comprueba rol sin `BYPASSRLS`, RLS en todas las tablas, contexto que falla cerrado y gatillo de saldos |
| `almacen:liberar-reservas-vencidas` | Cada 5 minutos | Devuelve a disponible lo que venció |
| `almacen:conciliar` | Diario, 03:00 | Compara cada saldo con la suma de sus movimientos |

`almacen:conciliar` es la auditoría del sistema: si encuentra un descuadre,
significa que hay una fila de saldos que cambió sin movimiento, y eso
debería ser imposible. **No corrige nada**: la corrección es un movimiento
con motivo, siempre.

---

## 12. Lo que NO hace esta arquitectura

- No hay integración con el sistema de ventas ni con el de inventario de
  Hefesto: esta aplicación es el almacén físico, y habla con el resto por
  API. La integración concreta queda como decisión pendiente del cliente.
- No hay códigos de barras generados: se **imprimen** en la etiqueta del
  rack. El sistema guarda el código, no lo dibuja.
- No hay rutas de despacho de vehículos ni cálculo de consolidación de
  carga: el picking es una vuelta dentro del almacén.
- No hay multi-almacén: hay zonas y racks dentro de un almacén.