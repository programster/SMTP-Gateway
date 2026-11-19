# Stage 1: compile the front-end TypeScript to plain JavaScript (ES modules, no bundler).
FROM node:22-bookworm-slim AS typescript
WORKDIR /build
COPY site/package.json site/tsconfig.json ./
RUN npm install --no-audit --no-fund
COPY site/ts ./ts
RUN npx tsc -p tsconfig.json


# Stage 2: the application itself.
FROM debian:12

# Set the timezone, to fix possible timezone issue
ENV TZ=Europe/London
ENV DEBIAN_FRONTEND=noninteract

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN apt-get update && apt-get dist-upgrade -y

# Add an admin user that will own things like the logs.
RUN adduser admin

# Upgrade and install ondrej PPA
RUN apt-get update && apt-get dist-upgrade -y \
    && apt-get install -y apt-transport-https ca-certificates curl git gpg

# Add the repository for later versions of PHP.
RUN curl -fsSL https://packages.sury.org/php/apt.gpg \
  | gpg --dearmor -o /usr/share/keyrings/sury-php.gpg \
  && echo "deb [arch=amd64 signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ bookworm main" \
  > /etc/apt/sources.list.d/php.list

# Expose port 80 for the web requests, and the SMTP server's ports that services send their email through: 465
# (implicit TLS) and 587 (STARTTLS). Both are always encrypted.
EXPOSE 80
EXPOSE 465
EXPOSE 587

# Install the relevant packages
RUN apt-get update && apt-get install vim nginx curl php8.5-fpm git unzip supervisor cron \
    php8.5-cli php8.5-xml php8.5-mbstring php8.5-curl php8.5-bcmath  \
    php8.5-pgsql php8.5-zip php8.5-gd php8.5-yaml -y

# Use Composer from its official image, as Debian 12's packaged version is too old for PHP 8.5 (and for current
# lock files).
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

# add the www-data user to the admin group.
RUN usermod --append www-data --groups admin

# Configure PHP-FPM (which runs the web interface and the API, behind nginx) for a small server.
COPY docker/php-overrides.ini /etc/php/8.5/fpm/conf.d/99-overrides.ini
RUN rm /etc/php/8.5/fpm/pool.d/www.conf
COPY docker/php-fpm-pool.conf /etc/php/8.5/fpm/pool.d/www.conf

# Give PHP on the command line (the cron jobs, and scripts run by hand) a memory limit, as it has none by default.
COPY docker/php-cli-overrides.ini /etc/php/8.5/cli/conf.d/99-overrides.ini

# Add the site's code to the container.
# When in development, use a volume to overwrite this area.
COPY --chown=admin:www-data --chmod=u=rwX,g=rX,o= site /var/www/site

# The documentation (the README and docs/), which the web interface shows under Documentation.
COPY --chown=admin:www-data --chmod=u=rwX,g=rX,o= README.md /var/www/documentation/README.md
COPY --chown=admin:www-data --chmod=u=rwX,g=rX,o= docs /var/www/documentation/docs

# Add the compiled JavaScript from the first stage.
COPY --from=typescript --chown=admin:www-data --chmod=u=rwX,g=rX,o= /build/public/js /var/www/site/public/js

# Running this manually to set sticky bit on the directory as setting
# 2750 on the previous copy does not seem to work.
RUN chmod g+s /var/www/site

# Install PHP packages
RUN  cd /var/www/site && umask 027 && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction

# nginx serves the site, passing requests for anything but the static files to PHP-FPM. TLS for the web interface
# is expected to be handled by a reverse proxy (e.g. Traefik) in front of the container.
COPY docker/nginx.conf /etc/nginx/nginx.conf
RUN rm -f /etc/nginx/sites-enabled/default

# Use the crontab file.
ADD docker/crons.conf /root/crons.conf
RUN crontab /root/crons.conf && rm /root/crons.conf

# Add supervisor config
COPY --chown=root:root docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy the script across that will create the .env file on startup for the web user to use.
COPY docker/create-env-file.php /root/create-env-file.php

# Add the remap-user script to the container.
COPY docker/remap-user.sh /root/remap-user.sh

# Add the script that puts the SMTP server's TLS certificate in place.
COPY docker/prepare-smtp-tls.sh /root/prepare-smtp-tls.sh

# Add the startup script to the container
COPY docker/startup.sh /root/startup.sh

# Change the workdir to /var/www/site so that when devs enter the container, they are at the site
WORKDIR /var/www/site

# Execute the containers startup script which will start many processes/services
CMD ["/bin/bash", "/root/startup.sh"]
