# ---------------------------------------------------------------------------
# Imagen de la API (Laravel + php-fpm)
# ---------------------------------------------------------------------------
# Las versiones estan fijadas por tag exacto. Un tag como `8.4` puede cambiar
# el contenido sin avisar; una etiqueta exacta con version, no.
#
# Tarea pendiente antes de produccion: fijar tambien por digest SHA256. Ver
# docs/10.
#
# ---------------------------------------------------------------------------

# ---------------------------------------------------------------------------
# Etapa 1: dependencias con el autoloader optimizado.
# ---------------------------------------------------------------------------
FROM php:8.4.26-cli-alpine3.23 AS dependencias

# `pcntl` lo necesita el worker de colas. `bcmath` lo usa la comparacion de
# cantidades, para que "12 cajas" y "12 unidades" nunca se comparen con
# coma flotante. `intl` lo usa Laravel para formatear.
RUN apk add --no-cache git unzip libpq-dev icu-dev oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl bcmath intl

# OJO: la imagen `composer:2` NO esta fijada por version exacta ni por digest,
# porque su version no se pudo verificar al escribir este archivo. Antes de
# produccion hay que reemplazarla por `composer:<version>@sha256:<digest>`
# verificados. Ver docs/10.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Se copian solo los manifiestos primero para que la capa se reutilice cuando
# no cambian las versiones del composer.json.
COPY composer.json composer.lock* ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-dev

# ---------------------------------------------------------------------------
# Etapa 2: la aplicacion.
# ---------------------------------------------------------------------------
FROM php:8.4.26-fpm-alpine3.23 AS app

# `nginx` es el servidor interno de la imagen para las rutas de salud. El
# servidor publico es Caddy (deploy/caddy/Caddyfile). `curl` se instala solo
# para las comprobaciones de salud del orquestador.
RUN apk add --no-cache nginx supervisor curl \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl bcmath intl opcache \
    && rm -rf /var/cache/apk/*

WORKDIR /var/www/html

COPY --from=dependencias /var/www/html /var/www/html

COPY docker/php.ini /usr/local/etc/php/conf.d/almacen.ini
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# El usuario de php-fpm no es root.
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]