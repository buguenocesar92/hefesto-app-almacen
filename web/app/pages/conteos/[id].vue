<template>
  <section>
    <div v-if="pendienteCarga" class="aviso">Cargando…</div>

    <template v-else-if="conteo">
      <h1>{{ conteo.nombre }}</h1>

      <p class="intro">
        Zona {{ conteo.zona ?? 'sin zona' }} · estado {{ conteo.estado }}
      </p>

      <p v-if="conteo.congelado" class="congelado">
        Zona congelada: no se puede mover mercadería hasta que este conteo cierre o
        se cancele. Es lo que hace que la comparación signifique algo.
      </p>

      <p class="resumen">
        {{ conteo.resumen.contadas }} de {{ conteo.resumen.paradas }} paradas
        contadas · {{ conteo.resumen.diferencias }} con diferencia
      </p>

      <table class="tabla">
        <thead>
          <tr>
            <th>Ubicación</th>
            <th>Artículo</th>
            <th class="numero">Sistema</th>
            <th class="numero">Contado</th>
            <th class="numero">Diferencia</th>
            <th>Justificación</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in conteo.items" :key="item.id">
            <td>
              <strong>{{ item.ubicacion.codigo }}</strong><br>
              <small>{{ item.ubicacion.etiqueta }}</small>
            </td>
            <td>{{ item.articulo.sku }} — {{ item.articulo.nombre }}</td>
            <td class="numero">{{ item.cantidad_sistema }}</td>
            <td class="numero">
              <input
                v-if="conteo.congelado"
                type="number"
                min="0"
                step="1"
                :value="item.cantidad_contada ?? ''"
                :aria-label="`Cantidad contada en ${item.ubicacion.codigo}`"
                @change="registrar(item, $event)"
              >
              <span v-else>{{ item.cantidad_contada ?? '—' }}</span>
            </td>
            <td class="numero" :class="claseDiferencia(item.diferencia)">
              {{ item.diferencia ?? '—' }}
            </td>
            <td>
              <template v-if="conteo.congelado">
                <select
                  v-model="item.motivo_id"
                  aria-label="Motivo de la diferencia"
                  @change="justificar(item)"
                >
                  <option :value="null">Motivo…</option>
                  <option
                    v-for="motivo in motivos"
                    :key="motivo.id"
                    :value="motivo.id"
                  >
                    {{ motivo.nombre }}
                  </option>
                </select>
                <textarea
                  v-if="item.motivo_id"
                  v-model="item.justificacion"
                  rows="2"
                  placeholder="¿Qué pasó aquí?"
                  aria-label="Explicación de la diferencia"
                  @change="justificar(item)"
                />
              </template>
              <template v-else>
                <span v-if="item.justificacion">{{ item.justificacion }}</span>
                <span v-else>—</span>
              </template>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="conteo.congelado" class="acciones">
        <button type="button" class="boton boton--principal" @click="cerrar">
          Cerrar conteo y corregir con movimientos
        </button>
        <button type="button" class="boton boton--discreto" @click="cancelar">
          Cancelar conteo
        </button>
      </div>

      <p v-if="mensaje" class="mensaje">{{ mensaje }}</p>
      <p v-if="error" class="error">{{ error }}</p>
    </template>

    <p v-else class="vacio">No se encontró el conteo.</p>
  </section>
</template>

<script setup lang="ts">
import type { Conteo, ConteoItem } from '~/tipos/api';

interface Motivo {
  id: string;
  nombre: string;
}

const ruta = useRoute();
const id = String(ruta.params.id);

const pendienteCarga = ref(true);
const conteo = ref<Conteo | null>(null);
const motivos = ref<Motivo[]>([]);
const mensaje = ref('');
const error = ref('');

const { data } = await useAsyncData(`conteo-${id}`, async () => {
  const [ficha, catalogo] = await Promise.all([
    $fetch<Conteo>(`/api/v1/conteos/${id}`),
    $fetch<{ motivos: Motivo[] }>('/api/v1/admin/motivos'),
  ]);

  return { ficha, motivos: catalogo.motivos };
});

conteo.value = data.value?.ficha ?? null;
motivos.value = data.value?.motivos ?? [];
pendienteCarga.value = false;

function claseDiferencia(diferencia: string | null): string {
  if (diferencia === null) {
    return '';
  }

  return diferencia.startsWith('-') ? 'negativo' : 'positivo';
}

async function registrar(item: ConteoItem, evento: Event) {
  const valor = (evento.target as HTMLInputElement).value;

  error.value = '';
  mensaje.value = '';

  try {
    await $fetch(`/api/v1/conteos/${id}/items/${item.id}`, {
      method: 'POST',
      body: {
        cantidad: Number(valor),
        ...(item.motivo_id ? { motivo_id: item.motivo_id } : {}),
        ...(item.justificacion ? { justificacion: item.justificacion } : {}),
      },
    });

    const ficha = await $fetch<Conteo>(`/api/v1/conteos/${id}`);

    // La diferencia la calcula el servidor al cerrar. Aquí solo se avisa
    // que el valor quedó registrado.
    item.cantidad_contada = ficha.items.find((i) => i.id === item.id)?.cantidad_contada ?? null;

    mensaje.value = `Cantidad registrada en ${item.ubicacion.codigo}.`;
  } catch (fallo) {
    const detalle = fallo as { data?: { mensaje?: string } };

    error.value = detalle.data?.mensaje ?? 'No se pudo registrar la cantidad.';
  }
}

async function justificar(item: ConteoItem) {
  if (!item.motivo_id || !item.justificacion) {
    return;
  }

  try {
    await $fetch(`/api/v1/conteos/${id}/items/${item.id}/justificar`, {
      method: 'POST',
      body: {
        motivo_id: item.motivo_id,
        justificacion: item.justificacion,
      },
    });

    mensaje.value = 'Justificación guardada.';
  } catch (fallo) {
    const detalle = fallo as { data?: { mensaje?: string } };

    error.value = detalle.data?.mensaje ?? 'No se pudo guardar la justificación.';
  }
}

async function cerrar() {
  error.value = '';
  mensaje.value = '';

  try {
    const respuesta = await $fetch<{ mensaje: string; ajustes: unknown[] }>(
      `/api/v1/conteos/${id}/cerrar`,
      { method: 'POST' },
    );

    mensaje.value = respuesta.mensaje;

    conteo.value = await $fetch<Conteo>(`/api/v1/conteos/${id}`);
  } catch (fallo) {
    const detalle = fallo as { data?: { mensaje?: string } };

    error.value = detalle.data?.mensaje ?? 'No se pudo cerrar el conteo.';
  }
}

async function cancelar() {
  error.value = '';

  try {
    await $fetch(`/api/v1/conteos/${id}/cancelar`, { method: 'POST' });

    conteo.value = await $fetch<Conteo>(`/api/v1/conteos/${id}`);

    mensaje.value = 'Conteo cancelado. La zona vuelve a moverse con normalidad.';
  } catch (fallo) {
    const detalle = fallo as { data?: { mensaje?: string } };

    error.value = detalle.data?.mensaje ?? 'No se pudo cancelar el conteo.';
  }
}

useHead({ title: 'Conteo · Gestión de Almacén' });
</script>

<style scoped>
.intro {
  color: #52606d;
  line-height: 1.6;
}

.congelado {
  padding: 0.75rem 1rem;
  background: #fffaf0;
  border: 1px solid #fbd38d;
  border-radius: 0.375rem;
  color: #7b341e;
}

.resumen {
  font-variant-numeric: tabular-nums;
  color: #52606d;
}

.aviso {
  padding: 1rem;
  background: #f0f4f8;
  border-radius: 0.375rem;
}

.tabla {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 1.5rem;
}

.tabla th,
.tabla td {
  text-align: left;
  padding: 0.5rem 0.75rem;
  border-bottom: 1px solid #e4e7eb;
  vertical-align: top;
}

.tabla th {
  font-size: 0.8rem;
  text-transform: uppercase;
  color: #627d98;
}

.tabla input[type='number'] {
  width: 6rem;
  padding: 0.25rem 0.5rem;
  border: 1px solid #cbd2d9;
  border-radius: 0.25rem;
}

.tabla textarea,
.tabla select {
  width: 100%;
  margin-bottom: 0.25rem;
}

.numero {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.negativo {
  color: #b02a37;
  font-weight: 600;
}

.positivo {
  color: #2b6cb0;
  font-weight: 600;
}

.acciones {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.vacio {
  color: #7b8794;
  font-style: italic;
}

.mensaje {
  margin-top: 1rem;
  padding: 0.75rem 1rem;
  background: #f0fff4;
  border: 1px solid #9ae6b4;
  border-radius: 0.375rem;
  color: #22543d;
}

.error {
  margin-top: 1rem;
  padding: 0.75rem 1rem;
  background: #fff5f5;
  border: 1px solid #f5c2c7;
  border-radius: 0.375rem;
  color: #821a1a;
}
</style>