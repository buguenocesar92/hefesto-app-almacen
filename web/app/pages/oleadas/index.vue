<template>
  <section>
    <h1>Picking por oleadas</h1>

    <p class="intro">
      Una oleada agrupa varios pedidos en una sola vuelta de recoleccion. El
      beneficio no es de sistema: es de horas de operario. Armar una oleada
      ordena la ruta por ubicación y la deja lista para imprimir.
    </p>

    <div v-if="pendienteCarga" class="aviso">Cargando…</div>

    <table v-else class="tabla">
      <thead>
        <tr>
          <th>Oleada</th>
          <th>Fecha</th>
          <th>Zona</th>
          <th class="numero">Paradas</th>
          <th>Estado</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="oleada in oleadas" :key="oleada.id">
          <td><NuxtLink :to="`/oleadas/${oleada.id}`">{{ oleada.nombre }}</NuxtLink></td>
          <td>{{ oleada.fecha }}</td>
          <td>{{ oleada.zona?.nombre ?? 'Sin zona' }}</td>
          <td class="numero">{{ oleada.items_count ?? 0 }}</td>
          <td>{{ oleada.estado }}</td>
          <td><NuxtLink :to="`/oleadas/${oleada.id}`">Abrir ruta</NuxtLink></td>
        </tr>
        <tr v-if="oleadas.length === 0">
          <td colspan="6" class="vacio">Todavía no hay oleadas.</td>
        </tr>
      </tbody>
    </table>

    <h2>Nueva oleada</h2>

    <form class="formulario" @submit.prevent="crear">
      <label>
        Nombre
        <input v-model="nombre" type="text" required placeholder="Oleada mañana - zona A">
      </label>

      <label>
        Fecha
        <input v-model="fecha" type="date" required>
      </label>

      <label>
        Zona
        <select v-model="zonaId">
          <option value="">Sin zona (toda la bodega)</option>
          <option v-for="zona in zonas" :key="zona.id" :value="zona.id">{{ zona.codigo }}</option>
        </select>
      </label>

      <button type="submit" class="boton">Crear oleada</button>
    </form>

    <p v-if="mensaje" class="mensaje">{{ mensaje }}</p>
  </section>
</template>

<script setup lang="ts">
interface OleadaListada {
  id: string;
  nombre: string;
  fecha: string | null;
  estado: string;
  items_count?: number;
  zona: { codigo: string; nombre: string } | null;
}

interface Zona {
  id: string;
  codigo: string;
  nombre: string;
}

const pendienteCarga = ref(true);
const oleadas = ref<OleadaListada[]>([]);
const zonas = ref<Zona[]>([]);
const nombre = ref('');
const fecha = ref(new Date().toISOString().slice(0, 10));
const zonaId = ref('');
const mensaje = ref('');

const { data } = await useAsyncData('oleadas', async () => {
  const [lista, catalogo] = await Promise.all([
    $fetch<{ data: OleadaListada[] }>('/api/v1/oleadas?por_pagina=20'),
    $fetch<{ zonas: Zona[] }>('/api/v1/admin/zonas'),
  ]);

  return { lista: lista.data, zonas: catalogo.zonas };
});

oleadas.value = data.value?.lista ?? [];
zonas.value = data.value?.zonas ?? [];
pendienteCarga.value = false;

async function crear() {
  const creada = await $fetch<{ id: string }>('/api/v1/oleadas', {
    method: 'POST',
    body: {
      nombre: nombre.value,
      fecha: fecha.value,
      ...(zonaId.value ? { zona_id: zonaId.value } : {}),
    },
  });

  mensaje.value = 'Oleada creada. Ábrela y pulsa «Armar ruta».';

  const lista = await $fetch<{ data: OleadaListada[] }>('/api/v1/oleadas?por_pagina=20');
  oleadas.value = lista.data;
  nombre.value = '';

  await navigateTo(`/oleadas/${creada.id}`);
}

useHead({ title: 'Picking · Gestión de Almacén' });
</script>

<style scoped>
.intro {
  color: #52606d;
  max-width: 52rem;
  line-height: 1.6;
}

.aviso {
  padding: 1rem;
  background: #f0f4f8;
  border-radius: 0.375rem;
}

.tabla {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 2rem;
}

.tabla th,
.tabla td {
  text-align: left;
  padding: 0.5rem 0.75rem;
  border-bottom: 1px solid #e4e7eb;
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

.formulario {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
  align-items: flex-end;
}

.formulario label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.85rem;
  color: #52606d;
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
</style>