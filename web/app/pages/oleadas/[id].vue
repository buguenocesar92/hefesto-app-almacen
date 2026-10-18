<template>
  <section>
    <h1>Ruta de oleada</h1>

    <div v-if="pendienteCarga" class="aviso">Cargando…</div>

    <template v-else-if="oleada">
      <h2>{{ oleada.nombre }}</h2>

      <p class="intro">
        {{ oleada.fecha }} · estado {{ oleada.estado }}
        <template v-if="oleada.zona">· zona {{ oleada.zona }}</template>
      </p>

      <div class="acciones">
        <button
          v-if="oleada.estado === 'abierta'"
          type="button"
          class="boton"
          @click="armar"
        >
          Armar ruta
        </button>
        <a class="boton boton--discreto" :href="`/api/v1/oleadas/${oleada.id}/ruta`" target="_blank">
          Ver ruta en texto plano
        </a>
        <button
          v-if="oleada.estado === 'en_curso'"
          type="button"
          class="boton boton--discreto"
          @click="completar"
        >
          Marcar oleada como completada
        </button>
      </div>

      <p class="intro">
        La ruta está ordenada por ubicación: cada pasillo se recorre una sola
        vez, de punta a punta o al revés según corresponda. El operario no
        tiene que decidir nada: sigue el papel.
      </p>

      <table class="tabla">
        <thead>
          <tr>
            <th class="numero">Paso</th>
            <th>Ubicación</th>
            <th>Código de barras</th>
            <th>Artículo</th>
            <th class="numero">Cantidad</th>
            <th>Pedido</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="parada in oleada.paradas"
            :key="parada.secuencia"
            :class="{ recogida: parada.estado === 'recogido' }"
          >
            <td class="numero"><strong>{{ parada.secuencia }}</strong></td>
            <td>
              <strong>{{ parada.ubicacion?.codigo ?? '—' }}</strong><br>
              <small>{{ parada.ubicacion?.etiqueta }}</small>
            </td>
            <td><code>{{ parada.ubicacion?.codigo_barras }}</code></td>
            <td>{{ parada.articulo.sku }} — {{ parada.articulo.nombre }}</td>
            <td class="numero">{{ parada.cantidad_base }}</td>
            <td>{{ parada.pedido.numero }}</td>
            <td>
              <button
                v-if="parada.estado !== 'recogido'"
                type="button"
                class="boton boton--discreto"
                @click="recoger(parada)"
              >
                Recogido
              </button>
              <span v-else class="ok">Recogido</span>
            </td>
          </tr>
          <tr v-if="oleada.paradas.length === 0">
            <td colspan="7" class="vacio">
              Esta oleada todavía no tiene ruta armada.
            </td>
          </tr>
        </tbody>
      </table>

      <p v-if="mensaje" class="mensaje">{{ mensaje }}</p>
    </template>

    <p v-else class="vacio">No se encontró la oleada.</p>
  </section>
</template>

<script setup lang="ts">
import type { Oleada, ParadaDeRuta } from '~/tipos/api';

const ruta = useRoute();
const id = String(ruta.params.id);

const pendienteCarga = ref(true);
const oleada = ref<Oleada | null>(null);
const mensaje = ref('');

const { data } = await useAsyncData(`oleada-${id}`, () =>
  $fetch<Oleada>(`/api/v1/oleadas/${id}`),
);

oleada.value = data.value;
pendienteCarga.value = false;

async function armar() {
  await $fetch(`/api/v1/oleadas/${id}/armar`, { method: 'POST' });
  mensaje.value = 'Ruta armada y ordenada por ubicación.';
  oleada.value = await $fetch<Oleada>(`/api/v1/oleadas/${id}`);
}

async function recoger(parada: ParadaDeRuta) {
  await $fetch(`/api/v1/oleadas/${id}/paradas/${parada.id}/recoger`, { method: 'POST' });

  oleada.value = await $fetch<Oleada>(`/api/v1/oleadas/${id}`);
  mensaje.value = `Paso ${parada.secuencia} marcado como recogido.`;
}

async function completar() {
  try {
    await $fetch(`/api/v1/oleadas/${id}/completar`, { method: 'POST' });
    oleada.value = await $fetch<Oleada>(`/api/v1/oleadas/${id}`);
    mensaje.value = 'Oleada completada.';
  } catch {
    mensaje.value = 'No se pudo completar: quedan paradas sin recoger.';
  }
}

useHead({ title: 'Oleada · Gestión de Almacén' });
</script>

<style scoped>
.intro {
  color: #52606d;
  max-width: 52rem;
  line-height: 1.6;
}

.acciones {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  margin: 1rem 0;
}

.aviso {
  padding: 1rem;
  background: #f0f4f8;
  border-radius: 0.375rem;
}

.tabla {
  width: 100%;
  border-collapse: collapse;
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

.numero {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.recogida {
  opacity: 0.55;
}

.ok {
  color: #2f855a;
  font-weight: 600;
}

.vacio {
  color: #7b8794;
  font-style: italic;
}

.mensaje {
  padding: 0.75rem 1rem;
  background: #f0fff4;
  border: 1px solid #9ae6b4;
  border-radius: 0.375rem;
  color: #22543d;
}
</style>