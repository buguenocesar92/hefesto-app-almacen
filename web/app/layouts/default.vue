<template>
  <div class="almacen">
    <header class="almacen__cabecera">
      <div class="almacen__marca">
        <strong>Gestión de Almacén</strong>
        <span v-if="usuario" class="almacen__empresa">{{ usuario.empresa }}</span>
      </div>

      <nav v-if="usuario" class="almacen__menu">
        <NuxtLink to="/">Tablero</NuxtLink>
        <NuxtLink to="/articulos">Artículos</NuxtLink>
        <NuxtLink to="/ubicaciones">Ubicaciones</NuxtLink>
        <NuxtLink to="/documentos">Recepción y despacho</NuxtLink>
        <NuxtLink to="/pedidos">Pedidos</NuxtLink>
        <NuxtLink to="/oleadas">Picking</NuxtLink>
        <NuxtLink to="/conteos">Inventario físico</NuxtLink>
        <NuxtLink v-if="esAdministrador" to="/admin/catalogos">Administración</NuxtLink>
      </nav>

      <div v-if="usuario" class="almacen__usuario">
        <span>{{ usuario.nombre }} ({{ usuario.rol }})</span>
        <button type="button" class="boton boton--discreto" @click="salir">Salir</button>
      </div>
    </header>

    <main class="almacen__contenido">
      <slot />
    </main>

    <footer class="almacen__pie">
      <p>
        Aplicación de Hefesto Studio. El stock de este almacén no se edita: se mueve.
        Toda diferencia queda registrada como movimiento, con su motivo.
      </p>
    </footer>
  </div>
</template>

<script setup lang="ts">
const { usuario, esAdministrador, cargar, salir } = useSesion();

// El menu depende de quien esta conectado. Se carga una sola vez por visita:
// el resto de las paginas ya la piden por su cuenta con el middleware.
await callOnce('cargar-sesion', cargar);
</script>

<style scoped>
.almacen {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  color: #1f2933;
}

.almacen__cabecera {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  flex-wrap: wrap;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid #d9e2ec;
  background: #ffffff;
}

.almacen__marca {
  display: flex;
  flex-direction: column;
}

.almacen__empresa {
  font-size: 0.85rem;
  color: #627d98;
}

.almacen__menu {
  display: flex;
  gap: 1rem;
  flex-wrap: wrap;
}

.almacen__menu a {
  color: #334e68;
  text-decoration: none;
  padding: 0.25rem 0;
  border-bottom: 2px solid transparent;
}

.almacen__menu a.router-link-active {
  border-bottom-color: #2b6cb0;
  font-weight: 600;
}

.almacen__usuario {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  font-size: 0.9rem;
}

.almacen__contenido {
  flex: 1;
  width: 100%;
  max-width: 72rem;
  margin: 0 auto;
  padding: 1.5rem;
}

.almacen__pie {
  border-top: 1px solid #d9e2ec;
  padding: 1rem 1.5rem;
  font-size: 0.8rem;
  color: #627d98;
}
</style>