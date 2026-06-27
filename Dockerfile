# ============================================================
# STAGE 1 — Builder : clone + dépendances + assets + cache chaud
# ============================================================
FROM php:8.4-apache-bookworm AS builder

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /app

# Outils de build + extensions PHP — absents de l'image finale
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        curl \
        unzip \
        libicu-dev \
        libpq-dev \
        libzip-dev \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        pdo_pgsql \
        pgsql \
        zip \
    && docker-php-ext-enable opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Arguments de build — token jamais dans environment:
ARG GIT_REPO=https://github.com/AlexandreMonchain/toolbox.git
ARG GIT_BRANCH=main
ARG GIT_USERNAME=""
ARG GIT_TOKEN=""
# Incrémenter pour invalider le cache Docker layer du clone
ARG CACHE_BUST=1

RUN echo "Cache bust: ${CACHE_BUST}"

# Clone public ou privé via GIT_ASKPASS (token jamais dans l'URL)
RUN if [ -n "$GIT_TOKEN" ]; then \
        printf '#!/bin/sh\ncase "$1" in\n  *Username*) echo "%s";;\n  *) echo "%s";;\nesac\n' \
            "$GIT_USERNAME" "$GIT_TOKEN" > /tmp/git-askpass.sh \
        && chmod 700 /tmp/git-askpass.sh \
        && GIT_ASKPASS=/tmp/git-askpass.sh GIT_TERMINAL_PROMPT=0 \
           git clone --branch "${GIT_BRANCH}" --depth 1 "${GIT_REPO}" . \
        && rm -f /tmp/git-askpass.sh; \
    else \
        git clone --branch "${GIT_BRANCH}" --depth 1 "${GIT_REPO}" .; \
    fi

# .env minimal pour le build — sans secrets réels
RUN printf '%s\n' \
    'APP_ENV=prod' \
    'APP_DEBUG=0' \
    'APP_SECRET=build-placeholder-not-used-at-runtime' \
    'APP_ENCRYPTION_KEY=0000000000000000000000000000000000000000000000000000000000000000' \
    'APP_SHARE_DIR=var/share' \
    'DATABASE_URL=postgresql://placeholder:placeholder@localhost:5432/app?serverVersion=17' \
    'MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0' \
    'MAILER_DSN=null://null' \
    'DEFAULT_URI=https://toolbox.alexandremonchain.fr' \
    > /app/.env

# Dépendances de prod uniquement.
#
# IMPORTANT — rate limit GitHub : ~133 des archives "dist" du composer.lock sont
# servies par api.github.com. Non authentifié, GitHub limite à 60 req/h par IP →
# le téléchargement échoue (HTTP 403) et `composer install` casse pendant le build.
# Authentifié via un PAT, la limite passe à 5000 req/h.
#
# On réutilise GIT_TOKEN (le même que pour le clone) comme token GitHub pour composer.
# auth.json est écrit dans /root du stage builder — jamais copié dans l'image runtime
# (seul /app l'est) → le token ne fuit pas dans l'image finale.
#
# -v : trace les téléchargements pour diagnostiquer un éventuel échec réseau au build.
RUN if [ -n "$GIT_TOKEN" ]; then \
        composer config -g github-oauth.github.com "$GIT_TOKEN"; \
    fi \
    && composer install \
        --no-dev \
        --prefer-dist \
        --optimize-autoloader \
        --no-interaction \
        --no-scripts \
        --no-progress \
        -v

# Assets AssetMapper : compilation des fichiers statiques (assets/ → public/assets/)
# Pas d'importmap:install : l'app ne charge aucun module via importmap()/Stimulus,
# tout est servi en fichiers statiques (bootstrap, app.css, favicon) → aucune dépendance jspm.io.
RUN php bin/console asset-map:compile

# Cache chaud dans l'image — pas à chaque démarrage du container
RUN mkdir -p var/cache var/log \
    && php bin/console cache:warmup --env=prod --no-debug


# ============================================================
# STAGE 2 — Runtime : image finale sans outils de build
# ============================================================
FROM php:8.4-apache-bookworm AS runtime

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    APACHE_DOCUMENT_ROOT=/app/public

WORKDIR /app

# Copier les extensions PHP compilées depuis le builder (évite de recompiler)
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# Librairies runtime uniquement — pas git/curl/unzip/composer ni les -dev
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libicu72 \
        libpq5 \
        libzip4 \
    && a2enmod rewrite headers \
    && echo "ServerTokens Prod" >> /etc/apache2/conf-available/security.conf \
    && echo "ServerSignature Off" >> /etc/apache2/conf-available/security.conf \
    && a2enconf security \
    && rm -rf /var/lib/apt/lists/*

# Config OPcache production
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Code + vendor + assets compilés + cache chaud
COPY --from=builder --chown=www-data:www-data /app /app

# Config Apache
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://localhost/ || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
