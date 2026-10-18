<template>
  <section>
    <h1>Inventario físico</h1>

    <p class="intro">
      Conteo cíclico por zona. Al abrir un conteo se congela una foto de los
      saldos y la zona deja de admitir movimientos: si no, el conteo
      compararía una foto contra un mundo que cambió mientras la persona
      contaba.
    </p>

    <div v-if="pendienteCarga" class="aviso">Cargando…</div>

    <template v-else>
      <h2>Conteos</h2>

      <table class="tabla">
        <thead>
          <tr>
            <th>Nombre</th>
            <th>Zona</th>
            <th>Estado</th>
            <th class="numero">Paradas</th>
            <th>Apertura</th>
            <th>Cierre</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="conteo in conteos" :key="conteo.id">
            <td><NuxtLink :to="`/conteos/${conteo.id}`">{{ conteo.nombre }}</NuxtLink></td>
            <td>{{ conteo.zona?.nombre ?? '—' }}</td>
            <td>{{ conteo.estado }}</td>
            <td class="numero">{{ conteo.items_count ?? 0 }}</td>
            <td>{{ conteo.abierto_en ? formatear(conteo.abierto_en) : '—' }}</td>
            <td>{{ conteo.cerrado_en ? formatear(conteo.cerrado_en) : '—' }}</td>
            <td><NuxtLink :to="`/conteos/${conteo.id}`">Abrir</NuxtLink></td>
          </tr>
          <tr v-if="conteos.length === 0">
            <td colspan="7" class="vacio">Todavía no hay conteos.</td>
          </tr>
        </tbody>
      </table>

      <h2>Abrir un conteo</h2>

      <p class="intro">
        Al confirmar, la zona queda congelada hasta que el conteo cierre o se
        cancele.
      </p>

      <form class="formulario" @submit.prevent="abrir">
        <label>
          Zona
          <select v-model="zonaId" required>
            <option value="" disabled>Seleccione una zona</option>
            <option v-for="zona in zonas" :key="zona.id" :value="zona.id">
              {{ zona.codigo }} — {{ zona.nombre }}
            </option>
          </select>
        </label>

        <label>
          Nombre del conteo
          <input v-model="nombre" type="text" required placeholder="Conteo mensual zona A">
        </label>

        <button type="submit" class="boton">Abrir conteo</button>
      </form>

      <p v-if="mensaje" class="mensaje">{{ mensaje }}</p>
    </template>
  </section>
</template>

<script setup lang="ts">
interface ConteoListado {
  id: string;
  nombre: string;
  estado: string;
  abierto_en: string | null;
  cerrado_en: string | null;
  items_count?: number;
  zona: { codigo: string; nombre: string } | null;
}

interface Zona {
  id: string;
  codigo: string;
  nombre: string;
}

const pendienteCarga = ref(true);
const conteos = ref<ConteoListado[]>([]);
const zonas = ref<Zona[]>([]);
const zonaId = ref('');
const nombre = ref('');
const mensaje = ref('');

function formatear(valor: string): string {
  return new Date(valor).toLocaleString('es-CL');
}

const { data } = await useAsyncData('conteos', async () => {
  const [lista, catalogo] = await Promise.all([
    $fetch<{ data: ConteoListado[] }>('/api/v1/conteos?por_pagina=20'),
    $fetch<{ zonas: Zona[] }>('/api/v1/admin/zonas'),
  ]);

  return { lista: lista.data, zonas: catalogo.zonas };
});

conteos.value = data.value?.lista ?? [];
zonas.value = data.value?.zonas ?? [];
pendienteCarga.value = false;

async function abrir() {
  const creado = await $fetch<{ id: string }>('/api/v1/conteos', {
    method: 'POST',
    body: { zona_id: zonaId.value, nombre: nombre.value },
  });

  await navigateTo(`/conteos/${creado.id}`);
}

useHead({ title: 'Inventario físico · Gestión de Almacén' });
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