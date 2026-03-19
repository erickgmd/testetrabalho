# Usa PHP com Apache
FROM php:8.2-apache

# Instala extensões necessárias (PostgreSQL para Supabase)
RUN docker-php-ext-install pdo pdo_pgsql

# Ativa mod_rewrite (opcional, mas útil)
RUN a2enmod rewrite

# Copia arquivos para o servidor
COPY . /var/www/html/

# Permissões (importante no Render)
RUN chown -R www-data:www-data /var/www/html

# Porta padrão do Apache
EXPOSE 80

# Inicia o Apache
CMD ["apache2-foreground"]