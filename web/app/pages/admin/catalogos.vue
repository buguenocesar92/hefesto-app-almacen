<template>
  <section>
    <h1>Administración</h1>

    <p class="intro">
      Zonas, motivos de movimiento y unidades de medida. Los tres cambian el
      modo en que funciona el resto del sistema, así que se escriben con
      calma y una sola vez.
    </p>

    <h2>Zonas</h2>

    <form class="formulario" @submit.prevent="crearZona">
      <input v-model="zona.codigo" type="text" placeholder="Código" required aria-label="Código de la zona">
      <input v-model="zona.nombre" type="text" placeholder="Nombre" required aria-label="Nombre de la zona">
      <input
        v-model="zona.pasillo_desde"
        type="text"
        placeholder="Primer pasillo"
        aria-label="Primer pasillo de la zona"
      >
      <input
        v-model="zona.pasillo_hasta"
        type="text"
        placeholder="Último pasillo"
        aria-label="Último pasillo de la zona"
      >
      <button type="submit" class="boton">Crear zona</button>
    </form>

    <table class="tabla">
      <thead>
        <tr>
          <th>Código</th>
          <th>Nombre</th>
          <th>Pasillos</th>
          <th class="numero">Ubicaciones</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="fila in zonas" :key="fila.id">
          <td><strong>{{ fila.codigo }}</strong></td>
          <td>{{ fila.nombre }}</td>
          <td>
            {{ fila.pasillo_desde ?? '—' }}
            <template v-if="fila.pasillo_hasta"> a {{ fila.pasillo_hasta }}</template>
          </td>
          <td class="numero">{{ fila.ubicaciones ?? 0 }}</td>
        </tr>
      </tbody>
    </table>

    <h2>Motivos de movimiento</h2>

    <p class="intro">
      Todo movimiento lleva motivo. Los marcados con «exige justificación»
      obligan a escribir el porqué antes de cerrar un conteo con diferencia.
    </p>

    <table class="tabla">
      <thead>
        <tr>
          <th>Código</th>
          <th>Nombre</th>
          <th>Sentido</th>
          <th>Justificación</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="fila in motivos" :key="fila.id">
          <td><code>{{ fila.codigo }}</code></td>
          <td>{{ fila.nombre }}</td>
          <td>{{ fila.sentido }}</td>
          <td>{{ fila.exige_justificacion ? 'Sí' : 'No' }}</td>
        </tr>
      </tbody>
    </table>

    <h2>Unidades de medida</h2>

    <p class="intro">
      El factor dice cuántas unidades base trae una unidad. Vive en la
      <em>unidad</em>, no en el artículo: si cada artículo guardara su
      propio factor, habría dos verdades para la misma caja.
    </p>

    <form class="formulario" @submit.prevent="crearUnidad">
      <input v-model="unidad.codigo" type="text" placeholder="Código (CAJA-12)" required aria-label="Código de la unidad">
      <input v-model="unidad.nombre" type="text" placeholder="Nombre (Caja de 12)" required aria-label="Nombre de la unidad">
      <input
        v-model.number="unidad.unidades_por_base"
        type="number"
        min="1"
        step="1"
        placeholder="Unidades por unidad base"
        required
        aria-label="Unidades por unidad base"
      >
      <button type="submit" class="boton">Crear unidad</button>
    </form>

    <table class="tabla">
      <thead>
        <tr>
          <th>Código</th>
          <th>Nombre</th>
          <th class="numero">Unidades por base</th>
          <th>¿Es unidad base?</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="fila in unidades" :key="fila.id">
          <td><code>{{ fila.codigo }}</code></td>
          <td>{{ fila.nombre }}</td>
          <td class="numero">{{ fila.unidades_por_base }}</td>
          <td>{{ fila.es_base ? 'Sí' : 'No' }}</td>
        </tr>
      </tbody>
    </table>

    <p v-if="mensaje" class="mensaje">{{ mensaje }}</p>
    <p v-if="error" class="error">{{ error }}</p>
  </section>
</template>

<script setup lang="ts">
interface Zona {
  id: string;
  codigo: string;
  nombre: string;
  pasillo_desde: string | null;
  pasillo_hasta: string | null;
  ubicaciones: number | null;
}

interface Motivo {
  id: string;
  codigo: string;
  nombre: string;
  sentido: string;
  exige_justificacion: boolean;
}

interface Unidad {
  id: string;
  codigo: string;
  nombre: string;
  unidades_por_base: string;
  es_base: boolean;
}

const zonas = ref<Zona[]>([]);
const motivos = ref<Motivo[]>([]);
const unidades = ref<Unidad[]>([]);
const mensaje = ref('');
const error = ref('');

const zona = reactive({ codigo: '', nombre: '', pasillo_desde: '', pasillo_hasta: '' });
const unidad = reactive({ codigo: '', nombre: '', unidades_por_base: 1 });

const { data } = await useAsyncData('catalogos', async () => {
  const [catZonas, catMotivos, catUnidades] = await Promise.all([
    $fetch<{ zonas: Zona[] }>('/api/v1/admin/zonas'),
    $fetch<{ motivos: Motivo[] }>('/api/v1/admin/motivos'),
    $fetch<{ unidades: Unidad[] }>('/api/v1/admin/unidades'),
  ]);

  return { zonas: catZonas.zonas, motivos: catMotivos.motivos, unidades: catUnidades.unidades };
});

zonas.value = data.value?.zonas ?? [];
motivos.value = data.value?.motivos ?? [];
unidades.value = data.value?.unidades ?? [];

async function recargar() {
  const [catZonas, catUnidades] = await Promise.all([
    $fetch<{ zonas: Zona[] }>('/api/v1/admin/zonas'),
    $fetch<{ unidades: Unidad[] }>('/api/v1/admin/unidades'),
  ]);

  zonas.value = catZonas.zonas;
  unidades.value = catUnidades.unidades;
}

async function crearZona() {
  error.value = '';
  mensaje.value = '';

  try {
    await $fetch('/api/v1/admin/zonas', {
      method: 'POST',
      body: {
        codigo: zona.codigo,
        nombre: zona.nombre,
        ...(zona.pasillo_desde ? { pasillo_desde: zona.pasillo_desde } : {}),
        ...(zona.pasillo_hasta ? { pasillo_hasta: zona.pasillo_hasta } : {}),
      },
    });

    zona.codigo = '';
    zona.nombre = '';
    zona.pasillo_desde = '';
    zona.pasillo_hasta = '';

    await recargar();

    mensaje.value = 'Zona creada.';
  } catch (fallo) {
    const detalle = fallo as { data?: { mensaje?: string } };

    error.value = detalle.data?.mensaje ?? 'No se pudo crear la zona.';
  }
}

async function crearUnidad() {
  error.value = '';
  mensaje.value = '';

  try {
    await $fetch('/api/v1/admin/unidades', {
      method: 'POST',
      body: {
        codigo: unidad.codigo,
        nombre: unidad.nombre,
        unidades_por_base: unidad.unidades_por_base,
      },
    });

    unidad.codigo = '';
    unidad.nombre = '';
    unidad.unidades_por_base = 1;

    await recargar();

    mensaje.value = 'Unidad creada.';
  } catch (fallo) {
    const detalle = fallo as { data?: { mensaje?: string } };

    error.value = detalle.data?.mensaje ?? 'No se pudo crear la unidad.';
  }
}

useHead({ title: 'Administración · Gestión de Almacén' });
</script>

<style scoped>
.intro {
  color: #52606d;
  max-width: 52rem;
  line-height: 1.6;
}

.formulario {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}

.formulario input {
  padding: 0.5rem 0.75rem;
  border: 1px solid #cbd2d9;
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

.mensaje {
  padding: 0.75rem 1rem;
  background: #f0fff4;
  border: 1px solid #9ae6b4;
  border-radius: 0.375rem;
  color: #22543d;
}

.error {
  padding: 0.75rem 1rem;
  background: #fff5f5;
  border: 1px solid #f5c2c7;
  border-radius: 0.375rem;
  color: #821a1a;
}
</style>