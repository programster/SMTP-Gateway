# SMTP API Service

* [What it does](#what-it-does)
* [Quick start](#quick-start)
* [Documentation](#documentation)
* [License](#license)

## What it does

A self-hosted gateway that your other services send email through, instead of each one holding the
credentials for your email providers. Each service gets its own **email token**, restricted to:

* the IP addresses or ranges it may connect from,
* the addresses it may send as (the envelope sender, and the From and Sender headers),
* the addresses it may use as the Reply-To,
* who it may send to (anyone, anyone at a domain, or a list of addresses), and
* how fast it may send: any mix of limits on emails and on recipients, e.g. 10 emails per second and
  50,000 recipients per month.

Services send through it with **SMTP** (so an existing service only needs its SMTP host, username and
password changed) or a **JSON API**. It sends through any number of **providers** (SendGrid, or any
SMTP server), each with its own rate limits, in round robin, overflow or fallback mode.

Everything is recorded, so the dashboard shows how much is coming through, from which email tokens, through
which providers, what was rejected, and (with SendGrid's event webhook) what was delivered, bounced,
opened or clicked.

## Quick start

```bash
cp .env.example .env
# Fill in at least DB_USER, DB_PASSWORD, DB_NAME, ADMIN_EMAIL and ADMIN_PASSWORD_HASH (the hash of
# the password, base64 encoded: .env.example says how to make it).
docker compose build
docker compose up -d
```

Then open the web interface (port 80 by default), log in with `ADMIN_EMAIL` and the password you hashed, and
set up two-factor authentication (you'll be taken straight there, as it's required by default). A fresh
install has no providers, so add one under **Providers** (e.g. SendGrid, with its API key in `.env` as
`SENDGRID_API_KEY`, referred to as `"${SENDGRID_API_KEY}"`). Emails sent before then are queued, and
sent once it's added. Then create an email token under **Email tokens**, and look through **Settings**.

## Documentation

The full documentation is in [docs/](docs/README.md), and is also shown in the web interface, under
**Documentation** in the side panel:

* [Configuration](docs/configuration.md): `.env`, the Settings pages, and settings.yaml.
* [Providers](docs/providers.md): the providers email is sent through, and how to set up each type.
* [Checking senders](docs/sender-checks.md): the checks that each sender will be accepted and trusted.
* [Sending email](docs/sending-email.md): over SMTP or the JSON API, and restricting an email token.
* [Rate limits and the queue](docs/rate-limits.md): the three kinds of rate limit, priorities and retries.
* [Tracking](docs/tracking.md): what's recorded, and the providers' event webhooks.
* [The web interface](docs/web-interface.md): its pages, forgotten passwords, and logging in with Google.
* [The management API](docs/management-api.md): managing the service with an API key.
* [Running on a small server](docs/small-servers.md): the memory and CPU limits, and the values suggested for a server.
* [How long emails are kept](docs/retention.md): retention, and the disk space emails take.
* [Adding a type of provider](docs/adding-a-provider.md): writing a provider of your own.
* [Development](docs/development.md): the development stack, and running the tests.
* [Security notes](docs/security.md): how credentials, passwords and sessions are protected.

## License

[MIT](LICENSE)
