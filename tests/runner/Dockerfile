# The test runner's image (see tests/runner/run.php): PHP, with the packages the runner uses, Python for the
# integration suites, and the Docker CLI and Compose plugin, to start each suite's stack through the host's Docker.
# run-tests.sh builds it, and runs it with the host's Docker socket, its network, and the repository mounted at the
# same path as on the host, so the stacks' bind mounts and ports are the same as if it ran on the host.
FROM php:8.5-cli-trixie

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev python3 unzip \
    && docker-php-ext-install pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=docker:29-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker:29-cli /usr/local/libexec/docker/cli-plugins/docker-compose \
    /usr/local/libexec/docker/cli-plugins/docker-compose
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

# The packages, installed outside the repository, so the runner's code can be mounted from it as it is.
COPY composer.json composer.lock /opt/runner/
RUN cd /opt/runner && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --no-progress \
    && chmod -R a+rX /opt/runner
