/**
 * Protege las pantallas del almacen.
 *
 * Si no hay sesion, manda a la pantalla de entrada. No es una proteccion
 * de seguridad: la seguridad esta en la API, que devuelve 404 o 403 y no
 * entrega datos. Esto es solo para que la persona no vea una pantalla
 * vacia sin saber que hacer.
 */
export default defineNuxtRouteMiddleware(async (to) => {
  const { usuario, cargar } = useSesion();

  const quien = await cargar();

  if (!quien && to.path !== '/entrar') {
    return navigateTo('/entrar');
  }

  if (quien && to.path === '/entrar') {
    return navigateTo('/');
  }

  return usuario.value !== null || to.path === '/entrar';
});