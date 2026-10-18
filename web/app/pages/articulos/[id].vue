<template>
  <section>
    <div v-if="pendienteCarga" class="aviso">Cargando…</div>

    <template v-else-if="articulo">
      <h1>{{ articulo.sku }} — {{ articulo.nombre }}</h1>

      <p v-if="articulo.descripcion" class="intro">{{ articulo.descripcion }}</p>

      <div class="cifras">
        <div class="cifra">
          <span class="cifra__etiqueta">En el rack</span>
          <strong class="cifra__valor">{{ articulo.stock.fisico }}</strong>
          <span class="cifra__pie">{{ articulo.unidad_base }}</span>
        </div>
        <div class="cifra">
          <span class="cifra__etiqueta">Reservado</span>
          <strong class="cifra__valor cifra__valor--reservado">{{ articulo.stock.reservado }}</strong>
          <span class="cifra__pie">Comprometido a pedidos</span>
        </div>
        <div class="cifra cifra--destacada">
          <span class="cifra__etiqueta">Disponible</span>
          <strong class="cifra__valor">{{ articulo.stock.disponible }}</strong>
          <span class="cifra__pie">{{ articulo.en_venta }}</span>
        </div>
      </div>

      <h2>Unidades</h2>

      <dl class="definiciones">
        <div><dt>Se compra en</dt><dd>{{ articulo.unidad_compra }}</dd></div>
        <div><dt>Se vende en</dt><dd>{{ articulo.unidad_venta }}</dd></div>
        <div><dt>Stock guardado en</dt><dd>{{ articulo.unidad_base }}</dd></div>
        <div>
          <dt>Una unidad de compra son</dt>
          <dd>{{ articulo.factor_compra_venta }} unidad(es) de venta</dd>
        </div>
      </dl>

      <h2>Dónde está</h2>

      <p class="intro">
        Esta es la consulta que vale el sistema: no solo cuánto hay, sino en
        qué rack. Las ubicaciones vienen en el orden en que conviene
        recorrelas.
      </p>

      <table class="tabla">
        <thead>
          <tr>
            <th class="numero">Ubicación</th>
            <th>Código de barras</th>
            <th>Zona</th>
            <th class="numero">En el rack</th>
            <th class="numero">Reservado</th>
            <th class="numero">Disponible</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="fila in ubicaciones" :key="fila.saldo_id">
            <td class="numero">
              <strong>{{ fila.codigo }}</strong><br>
              <small>{{ fila.etiqueta }}</small>
            </td>
            <td><code>{{ fila.codigo_barras }}</code></td>
            <td>{{ fila.zona ?? '—' }}</td>
            <td class="numero">{{ fila.fisico }}</td>
            <td class="numero reservado">{{ fila.reservado }}</td>
            <td class="numero disponible">{{ fila.disponible }}</td>
          </tr>
          <tr v-if="ubicaciones.length === 0">
            <td colspan="6" class="vacio">Este artículo no está en ninguna ubicación.</td>
          </tr>
        </tbody>
      </table>

      <h2>Movimientos</h2>

      <p class="intro">
        El saldo se explica con los movimientos. Si el número no cuadra, la
        respuesta está en esta lista, no en el saldo.
      </p>

      <table class="tabla">
        <thead>
          <tr>
            <th>Cuándo</th>
            <th>Tipo</th>
            <th class="numero">Cantidad</th>
            <th>Motivo</th>
            <th>Referencia</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="movimiento in movimientos" :key="movimiento.id">
            <td>{{ formatearFecha(movimiento.creado_en) }}</td>
            <td>{{ movimiento.tipo }}</td>
            <td class="numero" :class="movimiento.cantidad_base.startsWith('-') ? 'negativo' : ''">
              {{ movimiento.cantidad_base }}
            </td>
            <td>{{ movimiento.motivo?.nombre ?? '—' }}</td>
            <td>{{ movimiento.referencia ?? '—' }}</td>
          </tr>
        </tbody>
      </table>
    </template>

    <p v-else class="vacio">No se encontró el artículo.</p>
  </section>
</template>

<script setup lang="ts">
import type { Articulo, UbicacionConStock } from '~/tipos/api';

interface Movimiento {
  id: string;
  tipo: string;
  cantidad_base: string;
  referencia: string | null;
  creado_en: string;
  motivo: { nombre: string } | null;
  ubicacion: { codigo: string } | null;
}

const ruta = useRoute();
const id = String(ruta.params.id);

const pendienteCarga = ref(true);
const articulo = ref<Articulo | null>(null);
const ubicaciones = ref<UbicacionConStock[]>([]);
const movimientos = ref<Movimiento[]>([]);

function formatearFecha(valor: string): string {
  return new Date(valor).toLocaleString('es-CL');
}

const { data } = await useAsyncData(`articulo-${id}`, async () => {
  const [ficha, donde, historia] = await Promise.all([
    $fetch<Articulo>(`/api/v1/articulos/${id}`),
    $fetch<{ ubicaciones: UbicacionConStock[] }>(`/api/v1/articulos/${id}/ubicaciones`),
    $fetch<{ data: Movimiento[] }>(`/api/v1/articulos/${id}/movimientos?por_pagina=25`),
  ]);

  return { ficha, donde: donde.ubicaciones, historia: historia.data };
});

if (data.value) {
  articulo.value = data.value.ficha;
  ubicaciones.value = data.value.donde;
  movimientos.value = data.value.historia;
}

pendienteCarga.value = false;

useHead({ title: 'Artículo · Gestión de Almacén' });
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

.cifras {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
  margin: 1.5rem 0;
}

.cifra {
  flex: 1;
  min-width: 12rem;
  padding: 1rem;
  border: 1px solid #d9e2ec;
  border-radius: 0.5rem;
  background: #ffffff;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.cifra--destacada {
  border-color: #2b6cb0;
  background: #f0f7ff;
}

.cifra__etiqueta {
  font-size: 0.8rem;
  text-transform: uppercase;
  color: #627d98;
}

.cifra__valor {
  font-size: 1.8rem;
  font-variant-numeric: tabular-nums;
}

.cifra__valor--reservado {
  color: #8a6d1f;
}

.cifra__pie {
  font-size: 0.8rem;
  color: #7b8794;
}

.definiciones {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
  gap: 0.75rem;
  margin-bottom: 2rem;
}

.definiciones div {
  padding: 0.75rem;
  border-left: 3px solid #d9e2ec;
}

.definiciones dt {
  font-size: 0.8rem;
  color: #627d98;
}

.definiciones dd {
  margin: 0.25rem 0 0;
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

.disponible {
  font-weight: 600;
}

.reservado {
  color: #8a6d1f;
}

.negativo {
  color: #b02a37;
}

.vacio {
  color: #7b8794;
  font-style: italic;
}
</style>