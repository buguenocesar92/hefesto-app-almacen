<template>
  <section>
    <h1>Recepción y despacho</h1>

    <p class="intro">
      Un documento en borrador no mueve nada. El stock se mueve cuando el
      documento se confirma, y se mueve por movimientos. Anular un documento
      confirmado no borra nada: escribe el movimiento de sentido contrario.
    </p>

    <div v-if="pendienteCarga" class="aviso">Cargando…</div>

    <table v-else class="tabla">
      <thead>
        <tr>
          <th>Número</th>
          <th>Tipo</th>
          <th>Fecha</th>
          <th>Referencia</th>
          <th>Estado</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="documento in documentos" :key="documento.id">
          <td><strong>{{ documento.numero }}</strong></td>
          <td>{{ documento.tipo }}</td>
          <td>{{ documento.fecha_documento }}</td>
          <td>{{ documento.referencia_externa ?? '—' }}</td>
          <td>{{ documento.estado }}</td>
          <td>
            <button
              v-if="documento.estado === 'borrador'"
              type="button"
              class="boton boton--discreto"
              @click="confirmar(documento)"
            >
              Confirmar
            </button>
          </td>
        </tr>
        <tr v-if="documentos.length === 0">
          <td colspan="6" class="vacio">Todavía no hay documentos.</td>
        </tr>
      </tbody>
    </table>

    <p v-if="mensaje" class="mensaje">{{ mensaje }}</p>
  </section>
</template>

<script setup lang="ts">
interface DocumentoListado {
  id: string;
  numero: string;
  tipo: string;
  estado: string;
  fecha_documento: string | null;
  referencia_externa: string | null;
}

const pendienteCarga = ref(true);
const documentos = ref<DocumentoListado[]>([]);
const mensaje = ref('');

const { data } = await useAsyncData('documentos', () =>
  $fetch<{ data: DocumentoListado[] }>('/api/v1/documentos?por_pagina=30'),
);

documentos.value = data.value?.data ?? [];
pendienteCarga.value = false;

async function confirmar(documento: DocumentoListado) {
  try {
    await $fetch(`/api/v1/documentos/${documento.id}/confirmar`, { method: 'POST' });

    mensaje.value = `Documento ${documento.numero} confirmado: el stock se movió.`;

    const lista = await $fetch<{ data: DocumentoListado[] }>('/api/v1/documentos?por_pagina=30');
    documentos.value = lista.data;
  } catch (error) {
    const detalle = error as { data?: { mensaje?: string } };

    mensaje.value = detalle.data?.mensaje ?? 'No se pudo confirmar el documento.';
  }
}

useHead({ title: 'Recepción y despacho · Gestión de Almacén' });
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