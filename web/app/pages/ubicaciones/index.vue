<template>
  <section>
    <h1>Ubicaciones</h1>

    <p class="intro">
      Cada fila es una dirección física: pasillo, rack, nivel y posición. El
      código de barras se imprime en la etiqueta del rack y se escanea en
      piso. Las direcciones son únicas dentro de la empresa, no del mundo.
    </p>

    <form class="filtros" @submit.prevent="cargar">
      <input v-model="pasillo" type="search" placeholder="Filtrar por pasillo" aria-label="Filtrar por pasillo">
      <button type="submit" class="boton">Filtrar</button>
      <span class="conteo">{{ ubicaciones.length }} ubicación(es)</span>
    </form>

    <div v-if="pendienteCarga" class="aviso">Cargando…</div>

    <table v-else class="tabla">
      <thead>
        <tr>
          <th>Código</th>
          <th>Dirección</th>
          <th>Código de barras</th>
          <th>Zona</th>
          <th>Estado</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="ubicacion in ubicaciones" :key="ubicacion.id">
          <td><strong>{{ ubicacion.codigo }}</strong></td>
          <td>{{ ubicacion.etiqueta }}</td>
          <td><code>{{ ubicacion.codigo_barras }}</code></td>
          <td>{{ ubicacion.zona ?? '—' }}</td>
          <td>{{ ubicacion.activo ? 'Activa' : 'Desactivada' }}</td>
        </tr>
        <tr v-if="ubicaciones.length === 0">
          <td colspan="5" class="vacio">No hay ubicaciones para ese filtro.</td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<script setup lang="ts">
import type { Ubicacion } from '~/tipos/api';

const pasillo = ref('');
const pendienteCarga = ref(true);
const ubicaciones = ref<Ubicacion[]>([]);

async function cargar() {
  pendienteCarga.value = true;

  const respuesta = await $fetch<{ ubicaciones: Ubicacion[] }>('/api/v1/ubicaciones', {
    query: pasillo.value ? { pasillo: pasillo.value } : {},
  });

  ubicaciones.value = respuesta.ubicaciones;
  pendienteCarga.value = false;
}

await cargar();

useHead({ title: 'Ubicaciones · Gestión de Almacén' });
</script>

<style scoped>
.intro {
  color: #52606d;
  max-width: 52rem;
  line-height: 1.6;
}

.filtros {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  margin-bottom: 1rem;
}

.filtros input {
  padding: 0.5rem 0.75rem;
  border: 1px solid #cbd2d9;
  border-radius: 0.375rem;
}

.conteo {
  color: #7b8794;
  font-size: 0.85rem;
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
}

.tabla th {
  font-size: 0.8rem;
  text-transform: uppercase;
  color: #627d98;
}

.vacio {
  color: #7b8794;
  font-style: italic;
}
</style>