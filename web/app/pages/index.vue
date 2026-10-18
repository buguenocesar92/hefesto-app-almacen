<template>
  <section>
    <h1>Tablero</h1>

    <p class="intro">
      Lo que hay que mirar primero en un almacén: qué se está agotando y qué
      zonas llevan mucho tiempo sin contarse. Las diferencias de inventario no
      se descubren solas.
    </p>

    <div v-if="pendienteCarga" class="aviso">Cargando…</div>

    <template v-else>
      <h2>Artículos bajo el mínimo</h2>

      <p v-if="bajoMinimo.length === 0" class="vacio">
        Ningún artículo está bajo su mínimo de reposición.
      </p>

      <table v-else class="tabla">
        <thead>
          <tr>
            <th>Código</th>
            <th>Artículo</th>
            <th class="numero">Mínimo</th>
            <th class="numero">Disponible</th>
            <th>En unidad de venta</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="fila in bajoMinimo" :key="fila.id">
            <td><NuxtLink :to="`/articulos/${fila.id}`">{{ fila.sku }}</NuxtLink></td>
            <td>{{ fila.nombre }}</td>
            <td class="numero">{{ fila.minimo }}</td>
            <td class="numero negativo">{{ fila.disponible }}</td>
            <td>{{ fila.en_venta }}</td>
          </tr>
        </tbody>
      </table>

      <h2>Zonas para contar</h2>

      <p class="intro">
        Conteo cíclico: conviene pasar por la zona que más tiempo lleva sin
        contarse. El sistema avisa, no bloquea: la rotación es decisión del
        jefe de almacén.
      </p>

      <table class="tabla">
        <thead>
          <tr>
            <th>Zona</th>
            <th>Nombre</th>
            <th>Último conteo</th>
            <th class="numero">Días</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="zona in zonasPendientes" :key="zona.id">
            <td><strong>{{ zona.codigo }}</strong></td>
            <td>{{ zona.nombre }}</td>
            <td>{{ zona.ultimo_conteo ? formatearFecha(zona.ultimo_conteo) : 'Nunca' }}</td>
            <td class="numero" :class="{ negativo: zona.vence }">{{ zona.dias_desde ?? '—' }}</td>
            <td>
              <NuxtLink :to="`/conteos?zona=${zona.id}`">Contar</NuxtLink>
            </td>
          </tr>
        </tbody>
      </table>

      <h2>Conteos abiertos</h2>

      <p v-if="conteosAbiertos.length === 0" class="vacio">
        No hay conteos abiertos. Mientras un conteo está abierto, su zona no
        admite movimientos: eso es lo que hace que la comparación signifique algo.
      </p>

      <ul v-else class="lista">
        <li v-for="conteo in conteosAbiertos" :key="conteo.id">
          <NuxtLink :to="`/conteos/${conteo.id}`">{{ conteo.nombre }}</NuxtLink>
          — zona {{ conteo.zona ?? 'sin zona' }}
          ({{ conteo.resumen.contadas }} de {{ conteo.resumen.paradas }} contadas)
        </li>
      </ul>
    </template>
  </section>
</template>

<script setup lang="ts">
interface FilaBajoMinimo {
  id: string;
  sku: string;
  nombre: string;
  minimo: string | null;
  disponible: string;
  en_venta: string;
}

interface ZonaPendiente {
  id: string;
  codigo: string;
  nombre: string;
  ultimo_conteo: string | null;
  dias_desde: number | null;
  vence: boolean;
}

interface ConteoAbierto {
  id: string;
  nombre: string;
  zona: string | null;
  resumen: { paradas: number; contadas: number; diferencias: number };
}

const pendienteCarga = ref(true);
const bajoMinimo = ref<FilaBajoMinimo[]>([]);
const zonasPendientes = ref<ZonaPendiente[]>([]);
const conteosAbiertos = ref<ConteoAbierto[]>([]);

function formatearFecha(valor: string): string {
  return new Date(valor).toLocaleDateString('es-CL');
}

onMounted(async () => {
  const [minimos, zonas, conteos] = await Promise.all([
    $fetch<{ articulos: FilaBajoMinimo[] }>('/api/v1/articulos/bajo-minimo'),
    $fetch<{ zonas: ZonaPendiente[] }>('/api/v1/conteos/pendientes'),
    $fetch<{ data: ConteoAbierto[] }>('/api/v1/conteos?estado=abierto&por_pagina=20'),
  ]);

  bajoMinimo.value = minimos.articulos;
  zonasPendientes.value = zonas.zonas;
  conteosAbiertos.value = conteos.data;

  pendienteCarga.value = false;
});

useHead({ title: 'Tablero · Gestión de Almacén' });
</script>

<style scoped>
.intro {
  color: #52606d;
  max-width: 48rem;
  line-height: 1.6;
}

.aviso {
  padding: 1rem;
  background: #f0f4f8;
  border-radius: 0.375rem;
}

.vacio {
  color: #7b8794;
  font-style: italic;
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
  letter-spacing: 0.03em;
  color: #627d98;
}

.numero {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.negativo {
  color: #b02a37;
  font-weight: 600;
}

.lista {
  padding-left: 1.25rem;
  line-height: 1.8;
}
</style>