# PHP + Apache
FROM php:8.2-apache

# Instala dependências do sistema (PostgreSQL)
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Ativa mod_rewrite (opcional)
RUN a2enmod rewrite

# Copia os arquivos
COPY . /var/www/html/

# Permissões
RUN chown -R www-data:www-data /var/www/html

# Porta
EXPOSE 80

# Start
CMD ["apache2-foreground"]