<template>
  <section>
    <h1>Artículos</h1>

    <p class="intro">
      Cada artículo muestra tres cifras, no una: lo que hay en el rack, lo que
      ya está comprometido a un pedido, y lo que se puede vender. La tercera
      es la diferencia de las dos primeras.
    </p>

    <form class="filtros" @submit.prevent="cargar">
      <input
        v-model="buscar"
        type="search"
        placeholder="Buscar por código o nombre"
        aria-label="Buscar artículos"
      >
      <button type="submit" class="boton">Buscar</button>
    </form>

    <div v-if="pendienteCarga" class="aviso">Cargando…</div>

    <table v-else class="tabla">
      <thead>
        <tr>
          <th>Código</th>
          <th>Artículo</th>
          <th class="numero">En el rack</th>
          <th class="numero">Reservado</th>
          <th class="numero">Disponible</th>
          <th>En unidad de venta</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="fila in articulos" :key="fila.id">
          <td><NuxtLink :to="`/articulos/${fila.id}`">{{ fila.sku }}</NuxtLink></td>
          <td>{{ fila.nombre }}</td>
          <td class="numero">{{ fila.stock.fisico }}</td>
          <td class="numero reservado">{{ fila.stock.reservado }}</td>
          <td class="numero disponible">{{ fila.stock.disponible }}</td>
          <td>{{ fila.en_venta }}</td>
        </tr>
        <tr v-if="articulos.length === 0">
          <td colspan="6" class="vacio">No hay artículos que coincidan.</td>
        </tr>
      </tbody>
    </table>

    <p v-if="pagina" class="paginacion">
      {{ pagina.total }} artículo(s) en total.
    </p>
  </section>
</template>

<script setup lang="ts">
import type { Articulo, Pagina } from '~/tipos/api';

const buscar = ref('');
const pendienteCarga = ref(true);
const articulos = ref<Articulo[]>([]);
const pagina = ref<Pagina<Articulo> | null>(null);

async function cargar() {
  pendienteCarga.value = true;

  const respuesta = await $fetch<Pagina<Articulo>>('/api/v1/articulos', {
    query: {
      solo_activos: true,
      por_pagina: 50,
      ...(buscar.value ? { buscar: buscar.value } : {}),
    },
  });

  articulos.value = respuesta.data;
  pagina.value = respuesta;
  pendienteCarga.value = false;
}

await cargar();

useHead({ title: 'Artículos · Gestión de Almacén' });
</script>

<style scoped>
.intro {
  color: #52606d;
  max-width: 48rem;
  line-height: 1.6;
}

.filtros {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.filtros input {
  flex: 1;
  padding: 0.5rem 0.75rem;
  border: 1px solid #cbd2d9;
  border-radius: 0.375rem;
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

.numero {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.disponible {
  font-weight: 600;
}

.reservado {
  color: #8a6d1f;
}

.vacio {
  color: #7b8794;
  font-style: italic;
}

.paginacion {
  color: #7b8794;
  font-size: 0.85rem;
}
</style>