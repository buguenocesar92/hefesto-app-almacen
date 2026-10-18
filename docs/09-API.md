# 09 - API

> **Estado: escrita, no probada.** No se hizo ni una sola petición a esta API:
> no había PHP ni base de datos en el entorno donde se escribió. Ver
> [10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).

---

## 1. Convenciones

- **Prefijo**: todo está bajo `/api/v1`.
- **Sesión**: cookie `HttpOnly`. No hay tokens en el cuerpo de la petición.
- **CSRF**: las escrituras necesitan la cabecera `X-XSRF-TOKEN`, que el BFF de
  Nuxt agrega automáticamente. Quien llame desde el navegador **no** habla con
  la API: pasa por el BFF.
- **Formato**: JSON en las dos direcciones.
- **Fechas**: ISO 8601. Zona horaria del servidor: `America/Santiago`.
- **Cantidades**: texto decimal con seis decimales (`"120.000000"`). Van como
  texto y no como número a propósito: un número de coma flotante en el
  recorrido de un JSON puede perder precisión.
- **Paginación**: `?por_pagina=N`, y la respuesta trae `data`, `current_page`,
  `last_page`, `total`.

---

## 2. Códigos de respuesta

| Código | Cuándo | Qué trae |
|---|---|---|
| 200 | Lectura, o acción que no crea recurso | El resultado |
| 201 | Se creó un recurso | El recurso creado |
| 401 | Sin sesión, o el token no valió | `{ "mensaje": ... }` |
| 403 | La sesión existe pero no alcanza | `{ "mensaje": ... }` |
| 404 | No existe **o no puede existir a ojos de esta empresa** | `{ "mensaje": "No encontrado." }` |
| 422 | Error de validación o regla de almacén | `{ "mensaje": ..., "codigo": ... }` |
| 503 | Un componente no responde (el endpoint de salud) | Estado por componente |

El 404 en vez de 403 es deliberado: un 403 confirmaría que el recurso existe.

---

## 3. Códigos de error de regla de almacén

En el 422, el campo `codigo` es estable y la interfaz puede reaccionar a cada
uno sin adivinar.

| Código | Qué pasó |
|---|---|
| `stock_insuficiente` | No hay tanto stock en esa ubicación |
| `stock_insuficiente_para_reservar` | El pedido pide más de lo que hay disponible |
| `reserva_excede_stock` | La reserva supera lo físico del saldo |
| `reserva_inconsistente` | La reserva a liberar es mayor que lo reservado |
| `reserva_no_vigente` | La reserva ya está resuelta |
| `salto_reservado` | La salida dejaría la reserva sin mercadería |
| `sin_contexto_empresa` | La operación se ejecutó sin contexto de empresa |
| `cantidad_invalida` | Cantidad cero o negativa |
| `unidad_invalida` | Factor de conversión inválido |
| `unidad_base_no_base` | El artículo intenta guardar stock en cajas |
| `articulo_sin_unidad_base` | El artículo no tiene unidad base |
| `unidad_faltante` | Falta una unidad necesaria |
| `ubicacion_en_conteo` | La ubicación está en conteo abierto |
| `traspaso_mismo_origen` | Origen y destino son la misma ubicación |
| `traspaso_incompleto` | Al traspaso le falta un extremo |
| `traspaso_no_anulable` | Un traspaso se revierte, no se anula |
| `documento_no_confirmable` | El documento no está en borrador |
| `documento_no_anulable` | Solo se anula un documento confirmado |
| `documento_sin_items` | El documento no tiene líneas |
| `ingreso_sin_destino` | Una entrada necesita dónde se guarda |
| `ingreso_con_origen` | Una entrada no tiene origen |
| `salida_sin_origen` | Una salida necesita de dónde sale |
| `salida_con_destino` | Una salida no tiene destino |
| `motivo_faltante` | Falta el motivo de movimiento por defecto |
| `pedido_no_reservable` | El pedido no está en un estado reservable |
| `oleada_no_abierta` | La oleada ya se arme o no está abierta |
| `oleada_no_en_curso` | La oleada no está en curso |
| `oleada_sin_reservas` | No hay reservas vigentes para armar |
| `oleada_con_paradas_pendientes` | Quedan paradas sin recoger |
| `conteo_cerrado` | El conteo ya cerró |
| `conteo_no_abierto` | El conteo no está abierto |
| `conteo_incompleto` | Faltan paradas por contar |
| `conteo_sin_items` | La zona no tiene saldos que contar |
| `zona_con_conteo_abierto` | Esa zona ya tiene un conteo abierto |
| `diferencia_sin_justificar` | Hay una diferencia sin motivo ni explicación |
| `diferencia_invalida` | La diferencia es cero |
| `ubicacion_con_stock` | No se puede desactivar una ubicación con mercadería |

---

## 4. Endpoints

### Público

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/salud` | Estado de la base y de Redis. 503 si algo no responde |
| `GET` | `/api/v1/acceso` | Redirige a Authentik |
| `GET` | `/api/v1/acceso/callback` | Callback de Authentik; abre sesión |

`/api/v1/salud` no necesita sesión a propósito: un chequeo de salud que exige
credenciales no se puede correr justo cuando hace falta. No devuelve datos de
negocio: solo si las piezas de abajo contestan.

### Sesión

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/yo` | Quién está operando y qué puede hacer |
| `GET` | `/api/v1/yo/diagnostico` | Si el almacén alcanza a hablar con Authentik |
| `POST` | `/api/v1/salir` | Cierra la sesión |

### Artículos y stock

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/articulos` | Listado con las tres cifras |
| `POST` | `/api/v1/articulos` | Crear (administración) |
| `GET` | `/api/v1/articulos/bajo-minimo` | Alertas de reposición |
| `GET` | `/api/v1/articulos/{id}` | Ficha de un artículo |
| `PUT` | `/api/v1/articulos/{id}` | Editar (administración) |
| `POST` | `/api/v1/articulos/{id}/desactivar` | Desactivar, nunca borrar |
| `GET` | `/api/v1/articulos/{id}/ubicaciones` | **Dónde está**, en orden de recorrido |
| `GET` | `/api/v1/articulos/{id}/movimientos` | La historia del artículo |

### Ubicaciones

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/ubicaciones` | Todas, con filtros `pasillo`, `rack`, `zona` |
| `POST` | `/api/v1/ubicaciones` | Crear (administración) |
| `GET` | `/api/v1/ubicaciones/codigo-barras/{codigo}` | Lo que hace el escáner |
| `GET` | `/api/v1/ubicaciones/pasillo/{nombre}` | Un pasillo completo |
| `GET` | `/api/v1/ubicaciones/{id}` | Ficha con el contenido |
| `PUT` | `/api/v1/ubicaciones/{id}` | Editar (administración) |
| `POST` | `/api/v1/ubicaciones/{id}/desactivar` | Rechaza si hay mercadería |

### Recepción y despacho

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/documentos` | Listado, con filtros `estado` y `tipo` |
| `POST` | `/api/v1/documentos` | Crear en borrador |
| `GET` | `/api/v1/documentos/ubicaciones/{tipo}` | Ubicaciones para elegir al digitar |
| `GET` | `/api/v1/documentos/{id}` | Ficha con líneas y movimientos |
| `POST` | `/api/v1/documentos/{id}/confirmar` | **Mueve el stock** |
| `POST` | `/api/v1/documentos/{id}/anular` | Movimiento contrario (jefe de almacén) |

### Pedidos

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/pedidos` | Listado; `?abiertos=1` deja fuera los cerrados |
| `POST` | `/api/v1/pedidos` | Crear |
| `GET` | `/api/v1/pedidos/{id}` | Ficha con líneas y reservas |
| `POST` | `/api/v1/pedidos/{id}/reservar` | Compromete stock, con vencimiento |
| `POST` | `/api/v1/pedidos/{id}/liberar` | Devuelve el stock (jefe de almacén) |
| `POST` | `/api/v1/pedidos/{id}/despachar` | **Saca la mercadería del rack** |

### Oleadas de picking

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/oleadas` | Listado |
| `POST` | `/api/v1/oleadas` | Crear |
| `GET` | `/api/v1/oleadas/{id}` | La ruta completa, ordenada |
| `POST` | `/api/v1/oleadas/{id}/armar` | Ordena por ubicación y guarda |
| `GET` | `/api/v1/oleadas/{id}/ruta` | Ruta en texto plano, para imprimir |
| `POST` | `/api/v1/oleadas/{id}/paradas/{parada}/recoger` | Marca una parada |
| `POST` | `/api/v1/oleadas/{id}/completar` | Cierra la oleada |

### Inventario físico

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/v1/conteos` | Listado |
| `POST` | `/api/v1/conteos` | **Abrir**: congela la zona |
| `GET` | `/api/v1/conteos/pendientes` | Zonas para contar, por antigüedad |
| `GET` | `/api/v1/conteos/{id}` | Foto del sistema, contado y diferencias |
| `POST` | `/api/v1/conteos/{id}/items/{item}` | Registrar lo contado |
| `POST` | `/api/v1/conteos/{id}/items/{item}/justificar` | Motivo y explicación |
| `POST` | `/api/v1/conteos/{id}/cerrar` | **Genera los ajustes** (jefe de almacén) |
| `POST` | `/api/v1/conteos/{id}/cancelar` | Devuelve la zona al movimiento |

### Administración

| Método | Ruta | Qué hace |
|---|---|---|
| `GET`/`POST` | `/api/v1/admin/zonas` | Listar / crear |
| `GET`/`POST` | `/api/v1/admin/motivos` | Listar / crear |
| `GET`/`POST` | `/api/v1/admin/unidades` | Listar / crear |
| `GET` | `/api/v1/admin/usuarios` | Listar personas |
| `POST` | `/api/v1/admin/usuarios/{id}/rol` | Cambiar rol |

---

## 5. Ejemplos

### Dónde está

```http
GET /api/v1/articulos/{id}/ubicaciones
```

```json
{
  "articulo": { "id": "...", "sku": "EJE-6204", "nombre": "Eje de acero 6204" },
  "cifras": {
    "fisico": "480.000000",
    "reservado": "72.000000",
    "disponible": "408.000000"
  },
  "ubicaciones": [
    {
      "codigo": "1-01-01-01",
      "codigo_barras": "ALM-1-01-01-01",
      "etiqueta": "Pasillo 1, rack 01, nivel 1, posicion 1",
      "zona": "A",
      "fisico": "120.000000",
      "reservado": "36.000000",
      "disponible": "84.000000"
    }
  ]
}
```

### Reservar

```http
POST /api/v1/pedidos/{id}/reservar
{ "expira_en": "2026-10-20T18:00:00Z" }
```

```json
{
  "mensaje": "Pedido PED-1001 reservado.",
  "reservas": [
    { "id": "...", "cantidad_base": "72.000000", "expira_en": "2026-10-20T18:00:00Z" }
  ],
  "vence": "2026-10-20T18:00:00Z"
}
```

### Regla de almacén que corta la operación

```http
POST /api/v1/pedidos/{id}/reservar
```

```json
{
  "mensaje": "No hay stock disponible para reservar todo el pedido. Faltan 15.000000 unidades base de EJE-6204.",
  "codigo": "stock_insuficiente_para_reservar"
}
```

El mensaje dice **cuánto falta y de qué artículo**. Un «error de validación» sin
número no ayuda a nadie en el mostrador.

---

## 6. Dónde vive la validación

| Tipo | Dónde |
|---|---|
| Validación de entrada (tipos, formatos, obligatoriedad) | `$request->validate([...])` en el controlador, con mensajes en español |
| Reglas que dependen de otra fila (tipo de documento y ubicaciones) | `ServicioDeDocumentos::verificarItem()` |
| Reglas de stock (insuficiente, reservado, conteo abierto) | `RegistroDeMovimiento` y `ServicioDeConteos` |
| Restricciones que valen también para SQL escrito a mano | `CHECK` de PostgreSQL |

No hay clases `FormRequest` separadas: con este número de reglas, metidas en
el controlador quedan a la vista de quien lee la acción. Si el conjunto crece,
sacarlas es un cambio mecánico.

---

## 7. Comandos de consola

| Comando | Qué hace |
|---|---|
| `almacen:verificar-aislamiento` | Comprueba RLS, contexto cerrado y gatillo de stock |
| `almacen:liberar-reservas-vencidas` | Devuelve a disponible lo que venció |
| `almacen:conciliar` | Compara cada saldo con la suma de sus movimientos |
| `almacen:crear-persona` | Da de alta una persona (arranque en frío) |