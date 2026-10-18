# 08 - Respaldos

> **Estado: no ejecutado.** No se probó ni una restauración. Un respaldo que
> nunca se restauró es una carpeta con archivos. Ver
> [10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).

---

## 1. Por qué en un almacén el respaldo importa más

Perder la base de un sistema de formularios significa volver a escribir los
datos. Perder la base de un almacén significa:

1. **Perder la respuesta a «¿dónde estaba esto?»**. El stock sin historial de
   ubicaciones es un número sin significado.
2. **Perder la investigación de las diferencias**. Los movimientos son la
   evidencia. Sin ellos, una merma de marzo es indistinguible de un error de
   conteo de mayo.
3. **Perder las reservas**, que es peor que perder el stock: el almacén
   vuelve a prometer mercadería que ya salió.

Por eso el respaldo tiene que incluir los **movimientos**, no solo los saldos.
Un `pg_dump` completo los incluye: no hay atajo, y no debería haberlo.

---

## 2. Los dos scripts

| Script | Qué hace |
|---|---|
| `deploy/respaldos/respaldo.sh` | Genera el respaldo, aplica retención y **verifica** que el archivo esté sano |
| `deploy/respaldos/restauracion.sh` | Restaura sobre una base (con confirmaciones y salvaguardas) |

Ambos están validados sintácticamente con `bash -n`. No se han ejecutado.

### Variables de entorno

```sh
DIRECCION_RESGARDOS   # dónde se dejan los archivos (por omisión /var/backups/almacen)
RETENCION_DIAS        # por omisión 30
DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
```

---

## 3. Generar un respaldo

```sh
deploy/respaldos/respaldo.sh
```

El archivo queda como `almacen-AAAAMMDD-HHMMSS.dump.gz`.

El script usa **formato personalizado** de PostgreSQL (`--format=custom --compress=9`),
que tiene dos ventajas frente a un volcado de texto plano:

- `pg_restore` puede restaurar **tablas sueltas**. Si se daña la tabla de
  movimientos y no todo lo demás, no hay que perderlo todo.
- El archivo ya va comprimido por dentro; el `gzip` posterior es una capa
  extra para que el archivo se pueda copiar a cualquier lado.

`--no-owner` evita que el respaldo exija un rol que puede que ya no exista
cuando hay que restaurar a las tres de la mañana.

Al terminar, el script **verifica** el archivo con `gzip -t`. Un respaldo
corrupto que nadie notó durante meses es peor que no tener respaldo.

---

## 4. Retención

```sh
find "$DESTINO" -name 'almacen-*.dump.gz' -type f -mtime "+30" -delete
```

30 días por omisión. Es un valor que se puede cambiar con `RETENCION_DIAS`.

La retención no es lo importante. Lo importante es la regla: **el script
nunca borra un respaldo anterior si el nuevo falló**. El `pg_dump` va primero,
y solo si termina bien se llega a la limpieza.

---

## 5. Copia fuera del servidor

`respaldo.sh` **no copia nada a otro lado, a propósito**. Que la copia salga
del servidor depende de cada instalación (una NAS, un servicio de objetos,
otro servidor), y eso no se puede decidir desde un script genérico.

Pero la copia externa es **obligatoria**: un respaldo en el mismo disco que
la base no sobrevive a un incendio, a un robo o a un borrado accidental.

| Instancia | Dónde va la copia |
|---|---|
| Desarrollo | Al equipo del desarrollador |
| Producción | A una NAS en otra red, con copia versionada |

Los archivos de respaldo están en `.gitignore`. Un respaldo contiene toda la
mercadería y toda la historia de movimientos: no tiene nada que estar en un
repositorio.

---

## 6. Probar que el respaldo sirve

**Una vez por trimestre**, como mínimo:

```sh
# 1. Restaurar sobre una base de prueba, NUNCA sobre la de producción
DB_DATABASE=almacen_restore_prueba \
DB_USERNAME=... DB_PASSWORD=... DB_HOST=... \
  deploy/respaldos/restauracion.sh respaldos/almacen-AAAAMMDD-HHMMSS.dump.gz --confirmar

# 2. Comprobar que el aislamiento sigue puesto
DB_DATABASE=almacen_restore_prueba php artisan almacen:verificar-aislamiento

# 3. Comprobar que los saldos cuadran con sus movimientos
DB_DATABASE=almacen_restore_prueba php artisan almacen:conciliar

# 4. Entrar por la interfaz y revisar un conteo y una ruta de picking
```

El paso 3 es el importante: si un respaldo se restaurara sin movimientos,
el almacén arrancaría con saldos que nadie puede explicar.

### El script se niega a restaurar sobre producción

`restauracion.sh` tiene dos salvaguardas:

1. **Pide `--confirmar` explícitamente.**
2. **Se niega** si el nombre de la base contiene `produccion` o `prod`.

La razón es concreta: correrlo sin querer sobre la base equivocada borra la
historia de movimientos, y eso es irreversible. Si realmente hay que
restaurar en producción, el camino es restaurar sobre una base nueva y
cambiar la configuración.

---

## 7. Contraseña de PostgreSQL

El respaldo **no incluye el rol de la base**, solo sus datos. Eso significa
que la contraseña de PostgreSQL no está en el respaldo y hay que guardarla en
otro lado: el gestor de contraseñas de Hefesto, no un archivo de texto en el
servidor.

La clave `APP_KEY` sí es crítica: sin ella, las sesiones existentes no se
pueden recuperar y los valores cifrados quedan ilegibles. Tiene que estar en
el gestor de contraseñas, no solo en el `.env.prod` de un servidor.

---

## 8. Qué pasa si el respaldo falla

| Situación | Qué hace el sistema |
|---|---|
| `pg_dump` falla | El script sale con error y **no borra nada** del respaldo anterior |
| El archivo sale corrupto | `gzip -t` lo detecta al final y avisa |
| No hay espacio en disco | `pg_dump` falla, no se genera un archivo truncado |
| PostgreSQL no responde | El script falla al empezar, sin tocar nada |

La salud de la base se revisa con:

```sh
docker compose -f deploy/docker-compose.prod.yml --env-file .env.prod \
  exec postgres pg_isready -U almacen_app
```

Un respaldo que se genera todas las noches y falla todas las noches **no está
avisando**: `cron` manda correo a una dirección que nadie lee hace seis
meses. Conviene que el planificador o un monitors externo revisen el archivo
más reciente y avisen cuando sea demasiado viejo.

---

## 9. Lista de comprobación

- [ ] `respaldo.sh` se ejecuta todas las noches (programación de tareas)
- [ ] Hay un respaldo **fuera** del servidor
- [ ] La retención está configurada y hay espacio para ella
- [ ] Se probó una restauración completa en los últimos tres meses
- [ ] `APP_KEY` y la contraseña de la base están en el gestor de contraseñas
- [ ] Alguien recibe aviso cuando el respaldo falla o deja de generarse