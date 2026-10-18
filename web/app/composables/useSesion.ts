import type { Usuario } from '~/tipos/api';

/**
 * Sesion de la persona que esta operando el almacen.
 *
 * El rol decide que se ve en el menu, pero NO decide que se puede hacer: la
 * autorizacion real la hace la API. Esto es solo para no mostrar botones
 * que van a ser rechazados: es una cortesia, no una proteccion.
 */
export function useSesion() {
  const usuario = useState<Usuario | null>('sesion:usuario', () => null);
  const cargado = useState<boolean>('sesion:cargada', () => false);

  async function cargar(): Promise<Usuario | null> {
    if (cargado.value) {
      return usuario.value;
    }

    try {
      usuario.value = await $fetch<Usuario>('/api/v1/yo');
    } catch {
      usuario.value = null;
    }

    cargado.value = true;

    return usuario.value;
  }

  async function salir(): Promise<void> {
    await $fetch('/api/v1/salir', { method: 'POST' });

    usuario.value = null;
    cargado.value = true;

    await navigateTo('/entrar');
  }

  return {
    usuario,
    cargado,
    cargar,
    salir,
    esAdministrador: computed(() => usuario.value?.puede_administrar === true),
    esJefe: computed(() => usuario.value?.puede_cerrar_conteos === true),
    puedeAnular: computed(() => usuario.value?.puede_anular === true),
  };
}