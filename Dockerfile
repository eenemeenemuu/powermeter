FROM php:8.2-apache
ENV TZ=Europe/Berlin
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone \
    && echo "date.timezone = Europe/Berlin" > /usr/local/etc/php/conf.d/timezone.ini
RUN apt-get update && apt-get install -y cron && rm -rf /var/lib/apt/lists/*
RUN a2enmod rewrite
COPY . /var/www/html/
RUN mkdir -p /var/www/html/data && chown www-data:www-data /var/www/html/data
RUN echo '* * * * * www-data cd /var/www/html && /usr/local/bin/php /var/www/html/log.php >> /dev/null 2>&1' > /etc/cron.d/powermeter && chmod 0644 /etc/cron.d/powermeter
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
VOLUME /var/www/html/data
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
