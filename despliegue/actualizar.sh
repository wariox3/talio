#!/bin/bash
# Actualiza Talio en el servidor con lo que haya en origin/main.
# Uso (como root): /var/www/html/talio/despliegue/actualizar.sh
set -e

cd /var/www/html/talio

echo "== Código"
sudo -u www-data git pull origin main

echo "== Dependencias"
sudo -u www-data composer install --no-dev --optimize-autoloader

echo "== Caché"
sudo -u www-data php bin/console cache:clear

echo "== Listo: $(sudo -u www-data git log -1 --format='%h %s')"
