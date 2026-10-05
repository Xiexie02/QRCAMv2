FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev \
    && docker-php-ext-install curl mbstring \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY --chown=www-data:www-data . /var/www/html/
COPY docker-entrypoint.sh /usr/local/bin/qrcam-entrypoint
RUN chmod 755 /usr/local/bin/qrcam-entrypoint

ENV PORT=10000
EXPOSE 10000

CMD ["/usr/local/bin/qrcam-entrypoint"]
