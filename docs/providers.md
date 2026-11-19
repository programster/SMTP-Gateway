# Providers

The providers are set under **Settings > Providers** in the web interface, which keeps them in the
`providers` section of `config/settings.yaml`.

## Adding and editing them

* **Add a provider** asks for its type first (SendGrid, Mailgun, Postmark, Brevo, Mailjet, MailerSend,
  Mailtrap, or your own SMTP server), then shows that type's fields: e.g. an API key for SendGrid, an SMTP
  login and password for Mailgun, or a public and secret key for Mailjet. Each field says what it is, and
  where to find it in the provider's dashboard. Its own rate limits are on the same form.
* Each **secret** (a password, key or token) can be entered there, which writes it into
  `settings.yaml`, or read from an environment variable, by its name, which writes a `${NAME}`
  reference and keeps the secret out of the file (add the variable to `.env`, and restart). A secret is
  never shown again, and when editing, one left blank keeps the value it has.
* The enabled providers are listed first, and the disabled ones below. Drag an enabled provider by its
  handle (or focus the handle and use the up and down arrow keys) to change the order they're tried in,
  for the overflow and fallback modes, then **Save the order**. Each has a pencil to edit it, and a
  dustbin to remove it, which asks first. The last enabled provider can't be removed, as no email could
  be sent. A provider's type can't be changed: add one of the other type.
* Clicking a provider's name shows its own page: its settings (secrets never shown), rate limits,
  attempts, status, and where it is in the order.
* The **mode** is chosen from a list, each with what it does.
* Every change is checked as the YAML editor's are, and one based on an out-of-date version (as someone
  else changed the providers meanwhile) is refused, rather than overwriting their change.

## When a provider's own rate limit is reached

When a provider refuses an email as over its own rate limit (or a daily or monthly quota), e.g.
`421 4.7.0 Rate limit exceeded` or `550 5.7.1 Daily sending quota exceeded`, that's told apart from
other refusals by the reply's wording, as providers don't share a code for it. In every mode, the email
then goes through another provider (or waits, if there's none), rather than failing, and the provider
is skipped for a minute. The attempt shows as "over its rate limit" on the email's page.

As the rate limits set for it here allowed the email, they may be higher than the provider's, so a
warning is logged, with how many emails were sent through it in the last minute, hour, day and 30 days,
and the most each kind of limit could be (e.g. "at most 120 emails per hour, or 950 emails per day"),
to set under its rate limits. It can't be certain: if other systems send through the same account with
the provider, they use up its limit too, without this service knowing, and the limits here may be right.

## As YAML

The providers can also be edited as YAML, under **Advanced** on the same page, or through the API:

```yaml
mode: "overflow"            # round_robin, overflow or fallback

providers:
  - name: "sendgrid-main"
    type: "sendgrid"
    enabled: true
    api_key: "${SENDGRID_API_KEY}"
    webhook_public_key: "${SENDGRID_WEBHOOK_PUBLIC_KEY}"
    rate_limits:
      - unit: "emails"      # emails (requests to the provider), or recipients
        max: 10
        period_seconds: 1

  - name: "mailcow"
    type: "custom"          # an SMTP server of your own
    enabled: true
    host: "mail.example.org"
    port: 587
    security: "tls"         # tls (STARTTLS), ssl (implicit TLS) or none
    username: "${MAIL_SERVER_USERNAME}"
    password: "${MAIL_SERVER_PASSWORD}"
    dkim_selector: "dkim"   # optional: the selector it DKIM-signs with, for the sender checks
    rate_limits: []
```

A fresh install has none. Until the first is added, the dashboard and the Providers page say so, and
emails are accepted and queued, and sent once it's added.

* A setting written as `${NAME}` is read from the environment variable `NAME` (put it in `.env`), so the
  file can be committed without any secrets in it. After referring to a new variable, restart.
* The **mode** decides how emails are shared between the enabled providers:
  * `round_robin`: take turns, skipping any provider at its rate limits.
  * `overflow`: use the first provider, and the next ones only while the earlier ones are at their
    rate limits.
  * `fallback`: use the first provider, and the next ones only if it can't be connected to, or rejects
    the email. A provider at its rate limits is waited for, rather than skipped.
* In every mode, a provider that can't be connected to (or fails temporarily) is skipped for 60
  seconds, and the email goes straight to the next one.
* They can be viewed and edited under **Providers** in the web interface, or through the API
  (`/api/v1/providers`, whose `config` is `null` before any have been added). They're checked before
  they're saved, and a save based on an out-of-date version is refused, so two people can't overwrite
  each other's changes. Secrets written out in full are shown as `<unchanged>`, and kept unless
  replaced. The queue workers use changed providers from their next email, without a restart.
* `GET /api/v1/providers/export` gives the providers as YAML that's safe to commit (see **Download
  settings.yaml**, under [Settings](configuration.md#settings), for the whole file).

## Types of provider

Every type sends through the provider's SMTP relay (rather than its own send API), so each email is
passed on exactly as the client sent it. Besides `name`, `type`, `enabled` and `rate_limits`, each has
its own settings:

* **`sendgrid`**: `api_key` (with **Mail Send**), and optionally `webhook_public_key`, for its signed
  event webhook. The client's `X-SMTPAPI` header is replaced with one carrying the email's ID.
* **`mailgun`**: `region` (`us` or `eu`), `smtp_username` (one of a sending domain's SMTP logins, e.g.
  `postmaster@mg.example.org`) and `smtp_password`. Optionally `api_key`, for the sender checks (it
  only needs to read domains and webhooks, e.g. an "Analyst" key, where Mailgun offers them), and
  `webhook_signing_key`, the account's HTTP webhook signing key (Settings > API Security), which
  switches its webhooks on. Point Mailgun's webhooks for the domain (Sending > Webhooks) at
  `PUBLIC_URL/webhooks/<provider name>`. Mailgun DKIM-signs everything sent with the login as its
  domain, so each domain you send as needs a provider of its own. Every `X-Mailgun-*` header the client
  sent is removed (they control how Mailgun sends the email, e.g. `X-Mailgun-Archive-To` posts a copy of
  it to any URL), and `X-Mailgun-Variables` is set to the email's ID, which Mailgun passes back in its
  events. Mailgun leaves that header in the delivered email.
* **`postmark`**: `server_token` (a server's API token, which is also its SMTP login), `message_stream`
  (defaults to `outbound`), and optionally `account_token` (an account API token, which Postmark needs
  for reading its domains and sender signatures, for the sender checks) and `webhook_secret`. Postmark
  doesn't sign its webhooks, so they authenticate with HTTP Basic auth instead: add a webhook to the
  stream (Servers > the server > the stream > Webhooks) that posts to
  `PUBLIC_URL/webhooks/<provider name>`, with the username `webhook` and the `webhook_secret` (at least
  24 characters, e.g. from `openssl rand -hex 24`) as the password. Every `X-PM-*` header the client sent
  is removed (they choose the stream, and switch tracking on or off), and replaced with the stream, and
  an `X-PM-Metadata-*` header with the email's ID, which Postmark doesn't deliver.
* **`brevo`**: `smtp_login` (the login Brevo shows under SMTP & API > SMTP) and `smtp_key` (an SMTP key,
  not an API key), and optionally `api_key`, for the sender checks (Brevo's API keys can't be limited to
  reading), and `webhook_secret`. Brevo doesn't sign its webhooks either: add a transactional webhook
  (Transactional > Settings > Webhook) that posts to
  `https://webhook:<webhook_secret>@<your host>/webhooks/<provider name>` (or give the secret as its
  bearer token). Every `X-Mailin-*` and `X-Sib-*` header the client sent is removed (e.g. `X-Sib-Sandbox`
  stops it being sent), and `X-Mailin-custom` is set to the email's ID, which Brevo passes back in its
  events, and leaves in the delivered email.
* **`mailjet`**: `api_key` and `secret_key` (Account settings > API keys), which are also its SMTP login
  and read its senders for the sender checks, and optionally `webhook_secret`. Mailjet doesn't sign its
  event callbacks: point each event type's callback (Account settings > Event tracking) at
  `https://webhook:<webhook_secret>@<your host>/webhooks/<provider name>`. Every `X-MJ-*` and
  `X-Mailjet-*` header the client sent is removed (e.g. `X-MJ-TemplateErrorReporting` sends a copy to
  another address), and `X-MJ-CustomID` is set to the email's ID, which Mailjet passes back in its events.
* **`mailersend`**: `smtp_username` and `smtp_password` (one of the account's SMTP users, under Domains >
  the domain > SMTP), and optionally `api_token`, for the sender checks (read access to domains, and full
  access to webhooks to check them too, as MailerSend has no read-only access to them), and
  `webhook_secret`: the signing secret of a webhook (Integrations > Webhooks) that posts to
  `PUBLIC_URL/webhooks/<provider name>`, with version 2 payloads. MailerSend drops every custom header
  except `X-MailerSend-Tags`, so the email's ID is sent as a tag (replacing any the client set).
* **`mailtrap`**: `smtp_token` (one of a sending domain's API tokens, its SMTP password), and optionally
  `api_token` (with at least viewer access to the account), for the sender checks, and
  `webhook_secret`: the signing secret of a webhook (Settings > Webhooks, with the JSON payload format)
  that posts to `PUBLIC_URL/webhooks/<provider name>`. Every `X-MT-*` header the client sent is removed,
  and `X-MT-Custom-Variables` is set to the email's ID, which Mailtrap passes back in its events.
* **`custom`**: an SMTP server of your own (e.g. mailcow or Postfix): `host`, `port`, `security`,
  `username`, `password`, and optionally `dkim_selector`, for the sender checks. It can't report
  events.
