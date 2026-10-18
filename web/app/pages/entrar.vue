<template>
  <section class="entrar">
    <h1>Gestión de Almacén</h1>

    <p class="entrar__intro">
      El acceso es con la cuenta corporativa. Si no puedes entrar, avisa al
      administrador del almacén: el rol que decides en qué puedes trabajar
      se configura ahí.
    </p>

    <p v-if="error" class="entrar__error">{{ error }}</p>

    <a class="boton boton--principal" href="/api/v1/acceso">Entrar con la cuenta corporativa</a>

    <p class="entrar__nota">
      El acceso se hace en el servidor de identidad de Hefesto Studio.
      Esta aplicación no guarda contraseñas.
    </p>
  </section>
</template>

<script setup lang="ts">
const ruta = useRoute();

const error = computed(() => {
  const valor = ruta.query.error;

  return typeof valor === 'string' ? valor : null;
});

useHead({ title: 'Entrar · Gestión de Almacén' });
</script>

<style scoped>
.entrar {
  max-width: 34rem;
  margin: 3rem auto;
  text-align: center;
}

.entrar__intro {
  color: #52606d;
  line-height: 1.6;
}

.entrar__error {
  padding: 0.75rem 1rem;
  border: 1px solid #f5c2c7;
  background: #fff5f5;
  border-radius: 0.375rem;
  color: #821a1a;
}

.entrar__nota {
  margin-top: 2rem;
  font-size: 0.8rem;
  color: #7b8794;
}
</style>