<template>
  <section>
    <h1>Pedidos</h1>

    <p class="intro">
      Reservar compromete stock disponible y le pone fecha de vencimiento. Si
      el pedido no se confirma, la reserva se libera sola: es la garantía de
      que el almacén no prometa mercadería que ya salió.
    </p>

    <div v-if="pendienteCarga" class="aviso">Cargando…</div>

    <table v-else class="tabla">
      <thead>
        <tr>
          <th>Número</th>
          <th>Destinatario</th>
          <th class="numero">Prioridad</th>
          <th class="numero">Líneas</th>
          <th>Entrega</th>
          <th>Estado</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="pedido in pedidos" :key="pedido.id">
          <td><NuxtLink :to="`/pedidos/${pedido.id}`">{{ pedido.numero }}</NuxtLink></td>
          <td>{{ pedido.destinatario ?? '—' }}</td>
          <td class="numero">{{ pedido.prioridad }}</td>
          <td class="numero">{{ pedido.items_count ?? 0 }}</td>
          <td>{{ pedido.fecha_entrega ?? '—' }}</td>
          <td>{{ pedido.estado }}</td>
          <td>
            <button
              v-if="pedido.estado === 'pendiente'"
              type="button"
              class="boton boton--discreto"
              @click="reservar(pedido)"
            >
              Reservar
            </button>
          </td>
        </tr>
        <tr v-if="pedidos.length === 0">
          <td colspan="7" class="vacio">Todavía no hay pedidos.</td>
        </tr>
      </tbody>
    </table>

    <p v-if="mensaje" class="mensaje">{{ mensaje }}</p>
  </section>
</template>

<script setup lang="ts">
interface PedidoListado {
  id: string;
  numero: string;
  destinatario: string | null;
  prioridad: number;
  estado: string;
  fecha_entrega: string | null;
  items_count?: number;
}

const pendienteCarga = ref(true);
const pedidos = ref<PedidoListado[]>([]);
const mensaje = ref('');

const { data } = await useAsyncData('pedidos', () =>
  $fetch<{ data: PedidoListado[] }>('/api/v1/pedidos?abiertos=1&por_pagina=30'),
);

pedidos.value = data.value?.data ?? [];
pendienteCarga.value = false;

async function reservar(pedido: PedidoListado) {
  try {
    await $fetch(`/api/v1/pedidos/${pedido.id}/reservar`, { method: 'POST' });

    mensaje.value = `Pedido ${pedido.numero} reservado. La reserva vence sola si nadie lo confirma.`;

    const lista = await $fetch<{ data: PedidoListado[] }>('/api/v1/pedidos?abiertos=1&por_pagina=30');
    pedidos.value = lista.data;
  } catch (error) {
    const detalle = error as { data?: { mensaje?: string } };

    mensaje.value = detalle.data?.mensaje ?? 'No se pudo reservar el pedido.';
  }
}

useHead({ title: 'Pedidos · Gestión de Almacén' });
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