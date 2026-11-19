# Running on a small server

The service is built to run on a server with 1 GB of memory. nginx serves the web interface and API
with a small PHP-FPM pool that starts processes as requests arrive and stops them when idle, and each
part has a cap on how much can run at once. Measured with the default settings:

| | Idle | Under load |
|---|---|---|
| The app container | about 115 MB | about 265 MB |
| PostgreSQL | about 45 MB | about 140 MB |

The load was 30 SMTP connections at once (the default `SMTP_MAX_CONNECTIONS`), each sending emails one
after another, with 16 API clients making requests at the same time: over 70 emails a second, each
waited for until sent. That was with small emails: a process handling an email holds several copies
of it, so each email near the size limit can take far more (see `PHP_MEMORY_LIMIT`).

Every limit is set in `.env` (see [`.env.example`](../.env.example)), and defaults to a value that suits a
VPS with 1 GB of memory and 2 vCPUs. Nothing sizes itself. Instead, the **Server resources** page, under
**Settings**, shows:

* the server's memory and vCPUs, and how much of the memory is left for the app, once the operating
  system and PostgreSQL have theirs,
* where PostgreSQL is, worked out from `DB_HOST`: the bundled `db` service, on the same server (e.g. the
  Docker host), or on another server, whose memory isn't counted,
* each limit's current value, and two suggested values: one if you change every limit to its
  suggestion, and one if you only change that limit, keeping the others as they are (so if you need one
  larger than suggested, you can still see what the others should be with it),
* the suggestions as lines to paste into `.env`, and
* warnings about values that don't fit together, e.g. an `HTTP_MAX_BODY_SIZE` too small for
  `SMTP_MAX_MESSAGE_BYTES`, or more database connections than PostgreSQL accepts. They're also written
  to the container's log when it starts.

The bundled PostgreSQL's memory is capped at `DB_MEMORY_LIMIT` (384 MB by default), so it can't take
memory the app needs. The cron jobs and scripts run by hand have a fixed limit of 256 MB (in
[`docker/php-cli-overrides.ini`](../docker/php-cli-overrides.ini)), enough for emails of up to about 76 MB (the
Server resources page warns if `SMTP_MAX_MESSAGE_BYTES` is larger). The suggestions assume the provided compose
file.

TLS for the web interface is expected to be handled by a reverse proxy in front of the container (e.g.
Traefik). Add its address to the trusted proxies under **Settings > Proxies**, so the client IP addresses it
passes on are believed.
