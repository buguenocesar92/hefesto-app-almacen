// Configuracion de Nuxt (capa web) de Gestion de Almacen.
//
// ARQUITECTURA BFF
// ----------------
// Esta aplicacion es un "backend for frontend": la API de Laravel es la
// autoridad de la sesion y el navegador nunca habla directo con ella.
//
// El motivo es concreto y tiene que ver con el almacen, no con la moda: la
// sesion vive en una cookie HttpOnly. Si el navegador llamara a otra
// origen, habria que resolver CORS con credenciales, que duplica el
// riesgo de CSRF justo en la aplicacion que mueve mercaderia. Al pasar
// todo por el mismo origen, la cookie se mantiene en una sola parte y el
// token CSRF se reenvia como cabecera `X-XSRF-TOKEN`.
//
// Ver docs/01, seccion "Arquitectura BFF".

export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',

  ssr: true,

  devtools: { enabled: false },

  // Estilos comunes: botones grandes, numeros alineados y contraste alto,
  // pensados para usar la pantalla con guantes y con apuro.
  css: ['~/assets/css/main.css'],

  runtimeConfig: {
    // URL de la API de Laravel. Es config PRIVADA: solo la usa el servidor.
    // Nunca debe exponerse al navegador.
    apiBase: process.env.NUXT_API_BASE || 'http://localhost:8000',

    public: {
      // Origen de entrada del almacen, por ejemplo
      // https://almacen.hefestostudio.cl
      basePublico: process.env.NUXT_PUBLIC_BASE || 'http://localhost:3000',
    },
  },

  app: {
    head: {
      htmlAttrs: { lang: 'es-CL' },
      title: 'Gestión de Almacén',
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
        {
          name: 'description',
          content: 'Gestión de almacén: ubicaciones, stock, picking por oleadas e inventario físico.',
        },
      ],
    },
  },

  // Las solicitudes de datos pasan por el BFF de `server/api`, no por el
  // navegador. El cliente solo llama a rutas del mismo origen.
  nitro: {
    routeRules: {
      '/api/**': { cors: false },
    },
  },
});