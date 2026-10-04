FROM php:8.3-apache

RUN docker-php-ext-install pdo pdo_mysql \
  && a2enmod rewrite

COPY apache.conf /etc/apache2/sites-available/000-default.conf
COPY frontend/ /var/www/html/
COPY api/ /var/www/html/api/
COPY sql/ /var/www/html/sql/

COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh \
  && chown -R www-data:www-data /var/www/html

EXPOSE 80
CMD ["/docker-entrypoint.sh"]
