# Configuration

How the service is deployed is set in `.env` (see [`.env.example`](../.env.example), which explains each
setting), and how it behaves is set in the web interface, on its **Settings** and **Providers** pages,
which keep it in `config/settings.yaml`. The main settings in `.env` are:

| Setting | What it's for |
|---|---|
| `ADMIN_EMAIL`, `ADMIN_PASSWORD_HASH` | The default web interface account, created if it doesn't exist, and its password's hash (from `password_hash()`, base64 encoded), so the password itself isn't kept in `.env`. |
| `PUBLIC_URL` | The address the web interface is reached at, for links in emails and logging in with Google. |
| `SMTP_TLS_CERT_FILE`, `SMTP_TLS_KEY_FILE` | The SMTP server's certificate and key, if not `fullchain.pem` and `privkey.pem`: names in `./tls`, or full paths inside the container. |
| `SMTP_MAX_MESSAGE_BYTES` | The largest email accepted. 20 MiB by default. |
| `QUEUE_WORKERS`, `PHP_FPM_MAX_CHILDREN`, `SMTP_MAX_CONNECTIONS` | How much can run at once, which caps the memory used. |
| `HTTP_MAX_BODY_SIZE`, `PHP_MEMORY_LIMIT` | The largest request nginx and PHP accept, and the most memory a PHP process can use. |
| `DB_MEMORY_LIMIT`, `DB_MAX_CONNECTIONS`, `DB_SHARED_BUFFERS`, ... | The bundled PostgreSQL's memory limit and settings. |

The limits all default to values that suit a VPS with 1 GB of memory and 2 vCPUs. See
[Running on a small server](small-servers.md) for setting them for a bigger one.

Any secret that `settings.yaml` refers to as `"${NAME}"` (e.g. a provider's API key) goes in `.env`
too, with any name, followed by a restart.

## Settings

The **Settings** section of the side panel has a page for each part of `settings.yaml` but the
providers (which have a page of their own), each saved on its own, and applying straight away, without a
restart:

| Section | Settings | Default |
|---|---|---|
| General | The time zone times are shown in. Times are stored in UTC. | UTC |
| Security | Whether every web account must use two-factor authentication, and what a password must have: a minimum length (8 to 64), and optionally a capital letter, a number and a symbol, checked whenever a password is set | Required, and at least 12 characters, with a capital letter, a number and a symbol |
| Proxies | Whether the site is behind Cloudflare (see below), and your own reverse proxies, whose `X-Forwarded-For` header is believed | Not behind Cloudflare, and none |
| Sending (on the General page) | The address password reset emails are sent from, and how long a client waits to be told an email was sent (0 to 60 seconds) | None, and 5 seconds |
| Email retention | How long emails and logs are kept: see [How long emails are kept](retention.md) | Records and logs 90 days, content not kept |
| SSO | Logging in with Google: see [Logging in with Google](web-interface.md#logging-in-with-google) | Off |

Every save is checked first (e.g. a trusted proxy range covering every address, which would let any
client fake its IP address, is refused), and logged with who made it. A save based on an out-of-date
version of its section is refused, so two people can't overwrite each other's changes, but saving one
section never conflicts with a change to another. Any setting (or section) that's left out of the file
has its default.

`settings.yaml` can also be edited by hand. A section that's then invalid has its defaults (each the
safe choice, e.g. a second factor required), and the problem is shown at the top of the Settings page,
except for how long emails are kept: while that's invalid, nothing is deleted until it's fixed. A file
that isn't valid YAML at all can be fixed as a whole on the Providers page.

## Behind Cloudflare

When the site is behind Cloudflare, tick **This site is behind Cloudflare** under **Settings > Proxies**
(`behind_cloudflare: true` in the proxies section of `settings.yaml`). Requests from Cloudflare then
have their `CF-Connecting-IP` header believed as the client's IP address, which is what IP restrictions
(on API keys and email tokens), logins and the logs see.

Cloudflare's own addresses are handled separately from the trusted proxies, so don't add them there.
They're fetched from Cloudflare's published lists (`https://www.cloudflare.com/ips-v4` and `ips-v6`),
and kept in the database rather than `settings.yaml`, as they're Cloudflare's to change. They're
fetched on startup (in case the service has been down a while), every 6 hours, when the setting is
turned on, and with **Fetch them now** on the Proxies page, which also says when they were last
fetched. If a fetch fails, or gets something that isn't a list of ranges, the ones fetched before are
kept. Until they've been fetched once, `CF-Connecting-IP` isn't believed.

The trusted proxies still apply alongside Cloudflare: they're your own proxies between Cloudflare and
this service (e.g. Cloudflare sends to Traefik, which sends here). Their `X-Forwarded-For` header is
followed back to Cloudflare, and the client is still the one Cloudflare says. `CF-Connecting-IP` is
only believed when the request really came from one of Cloudflare's addresses, so a client can't fake
its address by sending the header itself. Responses go back the way the request came, through your
proxy and Cloudflare, as they're sent over the same connection.

Only the web interface and the HTTP API are affected: Cloudflare doesn't proxy SMTP, so the SMTP
server always sees the address that connected to it.

`config/settings.yaml` itself is git-ignored, as it may contain secrets. **Download settings.yaml**, on
the Providers page, gives a copy that's safe to commit: secrets written out in full are replaced with
`${ENV_VAR}` references, with a comment saying which variable to put each in.

## Upgrading from providers.yaml

Earlier versions kept the providers in `config/providers.yaml`, and the other settings in `.env`
(`TIMEZONE`, `REQUIRE_MFA`, `TRUSTED_PROXIES`, `SYSTEM_EMAIL_FROM`, `SYNC_WAIT_SECONDS`, the
`EMAIL_*_RETENTION_DAYS`, `LOG_RETENTION_DAYS` and the `GOOGLE_SSO_*`). On the first start after
upgrading, they're carried over to `config/settings.yaml` (the Google client secret written in full),
and `providers.yaml` is renamed to `providers.yaml.migrated`. Then those settings can be removed from
`.env`, as they're no longer read. Settings that can't be carried over (e.g. they were invalid) are
reported in the container's log, and nothing is changed, so it's tried again on the next start.
