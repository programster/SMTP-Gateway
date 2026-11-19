#!/bin/bash
# Please do not manually call this file!
# This script is run by the docker container when it is "run"

# Stop if anything fails
set -e


# remap the user so things like logs can be accessed on the host easily.
/bin/bash /root/remap-user.sh


# Ensure the logs directory for supervisor exists.
mkdir -p /logs/supervisor \
  && chown --recursive admin:admin /logs \
  && chmod --recursive 775 /logs


# Make sure cron isn't running, just in case. Supervisor will manage it (and nginx and PHP-FPM).
service cron stop
mkdir -p /run/php


# Create the .env file
php /root/create-env-file.php /.env


# Put the SMTP server's TLS certificate in place (or generate a self-signed one).
/bin/bash /root/prepare-smtp-tls.sh


# Check the configuration, so a mistake shows in the log straight away.
php /var/www/site/scripts/check-config.php


# Run migrations after waiting for the database to be available.
php /var/www/site/scripts/wait-for-database.php
php /var/www/site/scripts/migrate.php
php /var/www/site/scripts/create-default-admin.php

# Fetch Cloudflare's address ranges, if the site is behind Cloudflare, in case they've changed while the service was
# down. If they can't be fetched, the ones fetched before are kept, so starting up carries on.
php /var/www/site/scripts/update-cloudflare-networks.php || echo "Carrying on with Cloudflare's ranges as they were."


# Let www-data (the web interface, and the API) save settings.yaml, and keep the files the host user's. On the first
# start after upgrading, carry an earlier version's settings (providers.yaml, and the settings that were environment
# variables) over to it.
mkdir -p /config
chgrp admin /config && chmod 2775 /config

# A settings file put in place by hand may only be readable by the group, so make it writable too, so the web
# interface can save it.
for settingsFile in /config/*.yaml
do
    if [ -f "$settingsFile" ]
    then
        chgrp admin "$settingsFile" && chmod g+rw "$settingsFile"
    fi
done

su --shell /bin/sh www-data --command "php /var/www/site/scripts/migrate-settings.php"


# Apply the limits on the memory and CPU the app uses: the largest request nginx and PHP accept, PHP's memory limit,
# how many queue workers supervisor runs (see supervisord.conf), and the most PHP-FPM processes (see
# php-fpm-pool.conf). They're read from .env, through the app's Config, and the defaults suit a VPS with 1 GB of
# memory and 2 vCPUs. The Settings page suggests values for this server.
php /var/www/site/scripts/write-runtime-config.php /run/nginx-limits.conf /run/runtime-limits.sh
source /run/runtime-limits.sh

# Say in the log if the limits don't fit together, or don't fit the server. This never stops the container.
php /var/www/site/scripts/check-resources.php


# Start supervisord to manage all processes and run in foreground
exec /usr/bin/supervisord -n
