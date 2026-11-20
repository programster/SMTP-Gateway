# Providers

The providers are set under **Settings > Providers** in the web interface, which keeps them in the
`providers` section of `config/settings.yaml`.

## Adding and editing them

* **Add a provider** asks for its type first (SendGrid, Mailgun, Postmark, Brevo, Mailjet, MailerSend,
  Mailtrap, or your own SMTP server), then shows that type's fields: e.g. an API key for SendGrid, an SMTP
  login and password for Mailgun, or a public and secret key for Mailjet. Each field says what it is, and
  where to find it in the provider's dashboard. Its own rate limits are on the same form.
* Each **secret** (a password, key or token) is entered there, and written into `settings.yaml`. It's
  never shown again, and when editing, one left blank keeps the value it has.
* The enabled providers are listed first, and the disabled ones below. Drag an enabled provider by its
  handle (or focus the handle and use the up and down arrow keys) to change the order they're tried in,
  for the overflow and fallback modes, then **Save the order**. Each has a pencil to edit it, and a
  dustbin to remove it, which asks first. The last enabled provider can't be removed, as no email could
  be sent. A provider's type can't be changed: add one of the other type.
* Clicking a provider's name shows its own page: its id, settings (secrets never shown), rate limits,
  attempts, status, where it is in the order, and the email tokens [restricted to
  it](sending-email.md#restricting-the-providers).
* Removing a provider removes its email tokens' links to it, and the dialog that asks first names them,
  and any that would be left with no enabled provider (whose emails would then wait in the queue).
  Disabling one is allowed too, and its page says which tokens it would leave with no enabled provider.
* The **mode** is chosen from a list, each with what it does.
* Every change is checked as the YAML editor's are, and one based on an out-of-date version (as someone
  else changed the providers meanwhile) is refused, rather than overwriting their change.

## Restricting the senders a provider takes

A provider can be restricted to particular senders, e.g. one account that should only ever send for one
of your domains. On its form, under **Senders it takes**, there are two lists: **Domains** and
**Addresses**. It takes an email if its sender is at **any** of the domains, **or** is **any** of the
addresses. For example, with the domain `programster.org` and the address `stuart@stuart.page`, it takes
anything from `@programster.org`, and also anything from `stuart@stuart.page`, but nothing else from
`stuart.page`. A domain doesn't include its subdomains, so `programster.org` doesn't take
`mail.programster.org`. Leave both lists empty (the default) to take any sender.

* An email's **envelope sender** (MAIL FROM, or the API's `from`), and the addresses in its **From** and
  **Sender** headers, must all be taken, so a provider can't be used by giving it an envelope sender it
  takes with a From header it doesn't.
* Each email only goes through the providers that take it, and the mode applies to those, as if they
  were the only providers. With an email token [restricted to particular
  providers](sending-email.md#restricting-the-providers), only those of its providers that take the
  email are used.
* An email that no provider it could use would ever take is **refused** when it's sent: at `MAIL FROM`
  (or after `DATA`, for a From or Sender header) with `550 5.7.1`, or through the API with `403` and
  error code 24. It's recorded under Rejections. A provider that's disabled still counts as one that
  would take it, so the email is queued for it, as it may be enabled again.
* If the providers are changed so that none would take an email that's already queued, it's failed,
  saying so, rather than waiting forever.
* The sender checks only check a provider for the senders it takes, and say it doesn't take the others.
* If no enabled provider takes the address this service sends its own emails from (the system email
  address on the Settings page), the dashboard and the Providers page warn about it, as password reset
  emails would wait in the queue.

## Rate limits

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
  - id: "0b7f1c2e-5a3d-4e8f-9b6a-2c4d6e8f0a1b"   # optional: made for it if it's left out
    name: "sendgrid-main"
    type: "sendgrid"
    enabled: true
    api_key: "SG.xxxx"
    webhook_public_key: "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE..."
    rate_limits:
      - unit: "emails"      # emails (requests to the provider), or recipients
        max: 10
        period_seconds: 1

  - id: "6f1d9e3a-2b4c-4d5e-8f7a-1b2c3d4e5f60"
    name: "mailcow"
    type: "custom"          # an SMTP server of your own
    enabled: true
    host: "mail.example.org"
    port: 587
    security: "tls"         # tls (STARTTLS), ssl (implicit TLS) or none
    username: "relay@example.org"
    password: "xxxx"
    dkim_selector: "dkim"   # optional: the selector it DKIM-signs with, for the sender checks
    allowed_senders:        # optional: it takes any sender without it
      domains: ["programster.org"]          # anyone at these domains,
      addresses: ["stuart@stuart.page"]     # or any of these addresses
    rate_limits: []
```

A fresh install has none. Until the first is added, the dashboard and the Providers page say so, and
emails are accepted and queued, and sent once it's added.

* Each provider has an **id**, a UUID that email tokens restricted to it are linked by, so it stays the
  same when the provider is renamed. It's made for a provider added without one, and saved to
  `settings.yaml` straight away. When editing the YAML (or replacing the providers through the API), a
  provider without an `id` keeps the id of the current provider with the same name, so leaving the ids
  out doesn't unlink anything. To rename a provider there, keep its `id`, or it's taken to be a new
  provider, and its tokens lose their link to the old one. Each id must be unique.
* Settings aren't read from environment variables: a value written as a reference to one (`${NAME}`),
  which an earlier version allowed, is refused, so put the value itself in instead. Secrets are shown as
  `<unchanged>` in the YAML editor and the API, and kept as they are if they're saved that way.
* The **mode** decides how emails are shared between the enabled providers:
  * `round_robin`: take turns, skipping any provider at its rate limits.
  * `overflow`: use the first provider, and the next ones only while the earlier ones are at their
    rate limits.
  * `fallback`: use the first provider, and the next ones only if it can't be connected to, or rejects
    the email. A provider at its rate limits is waited for, rather than skipped.
  * An email token [restricted to particular providers](sending-email.md#restricting-the-providers) only
    uses those of them that are enabled, and the mode applies to them as if they were the only ones.
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
the settings below. The optional API key (or token) is only for the [sender checks](sender-checks.md),
and the webhook's key or secret switches on recording what happens to each email after it's sent (see
[Tracking](tracking.md)). The webhook posts to `PUBLIC_URL/webhooks/<provider name>`.

| Type | Required | Optional | Its webhook proves it's genuine by |
|---|---|---|---|
| [`sendgrid`](#sendgrid) | `api_key` | `webhook_public_key` | a signature |
| [`mailgun`](#mailgun) | `region`, `smtp_username`, `smtp_password` | `api_key`, `webhook_signing_key` | a signature |
| [`postmark`](#postmark) | `server_token`, `message_stream` | `account_token`, `webhook_secret` | HTTP Basic auth |
| [`brevo`](#brevo) | `smtp_login`, `smtp_key` | `api_key`, `webhook_secret` | HTTP Basic auth, or a bearer token |
| [`mailjet`](#mailjet) | `api_key`, `secret_key` | `webhook_secret` | HTTP Basic auth |
| [`mailersend`](#mailersend) | `smtp_username`, `smtp_password` | `api_token`, `webhook_secret` | a signature |
| [`mailtrap`](#mailtrap) | `smtp_token` | `api_token`, `webhook_secret` | a signature |
| [`custom`](#custom) | `host`, `port`, `security` | `username`, `password`, `dkim_selector` | (no events) |

Each type also removes the provider's own control headers that a client sent, as they could change how
the provider sends the email (e.g. add recipients, or post a copy of it somewhere), and adds one carrying
the email's ID, which the provider passes back in its events.

## SendGrid

* **`api_key`**: an API key with **Mail Send** (see [SendGrid's API key](#sendgrids-api-key)), which is
  also its SMTP password.
* **`webhook_public_key`** (optional): the verification key of its signed event webhook (see
  [SendGrid's event webhook](#sendgrids-event-webhook)).
* **Headers**: the client's `X-SMTPAPI` header is replaced with one carrying the email's ID.

### SendGrid's API key

Give the service a restricted key of its own (Settings > API Keys > Create API Key > Restricted
Access), with only what it uses. It sends through SendGrid's SMTP relay, with the key as the password,
which only needs **Mail Send**. The rest is read access for the [sender checks](sender-checks.md),
which are only ever read from SendGrid, so nothing needs more than read access. Each check the key
isn't allowed to make says which permission would allow it.

| Permission                         | Access      | Scope                               | Used for                                                     |
|------------------------------------|-------------|-------------------------------------|--------------------------------------------------------------|
| Mail Send                          | Full Access | `mail.send`                         | Sending email (its **Scheduled Sends** isn't needed).        |
| Sender Authentication              | Read Access | `whitelabel.read`                   | Checking each sender's domain is authenticated.              |
| Mail Settings > Event Notification | Read Access | `user.webhooks.event.settings.read` | Checking the event webhook posts here, signed with its key.  |

Everything else, including **Email Activity** (searching sent email, which the service never does, as
SendGrid's event webhook sends it each email's events instead), **Email Testing** and the rest of
**Mail Settings**, can be left off.

The sender checks also list the senders verified one address at a time (`GET /v3/verified_senders`),
which SendGrid's restricted keys may not have a permission for. Without it, the Providers page notes
"Can't list verified senders", which doesn't matter if your senders' domains are authenticated, as
they're checked by their domain first.

#### Creating the key with SendGrid's API

A key with full access can create one with less, using SendGrid's
[Create API keys](https://www.twilio.com/docs/sendgrid/api-reference/api-keys/create-api-keys)
endpoint. Read the full key in without it being echoed, so it's kept out of your shell's history:

```bash
read -rsp 'Full SendGrid API key: ' SENDGRID_ADMIN_KEY; echo
```

Optionally, list the scopes it can grant, e.g. to see whether there's one for verified senders (whose
name SendGrid doesn't make clear) to add to the list below:

```bash
curl -s https://api.sendgrid.com/v3/scopes \
  -H "Authorization: Bearer $SENDGRID_ADMIN_KEY" \
  | jq -r '.scopes[]' | grep -E 'mail\.send|whitelabel|webhooks\.event|verified'
```

Then create the key, and forget the full one:

```bash
curl -s -X POST https://api.sendgrid.com/v3/api_keys \
  -H "Authorization: Bearer $SENDGRID_ADMIN_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "smtp-gateway",
    "scopes": [
      "mail.send",
      "whitelabel.read",
      "user.webhooks.event.settings.read"
    ]
  }' | jq .

unset SENDGRID_ADMIN_KEY
```

The new key is the response's `api_key`, which SendGrid only ever shows once, so copy it into the
provider's `api_key` straight away. The response also lists the scopes the key was given. Once it's
set, the Providers page shows whether each check could be made.

### SendGrid's event webhook

SendGrid's event webhook posts what happens to each email after it's sent (delivered, bounced, and so
on) to the service, which records it against the email (see [Tracking](tracking.md)). It's optional:
without it, emails are still sent, but nothing after SendGrid accepts them is recorded.

**It reports your whole SendGrid account's email, not just this service's.** A webhook belongs to the
account (or subuser), not to an API key, so SendGrid posts events for every email the account sends,
including those your other systems send with their own keys. The service ignores every event that isn't
about one of its own emails (it tags each email it sends with its ID, which SendGrid sends back), so
they aren't stored or counted, and nothing about another system's email (such as who it went to) is
kept. SendGrid is still told they were received, so it doesn't retry them.

Even so, your other systems' events are still being sent here. To keep them in SendGrid, give this
service a [subuser](https://www.twilio.com/docs/sendgrid/ui/account-and-settings/subusers) of its own
(Settings > Subuser Management, on plans that have subusers), with its own sending domain, API key and
event webhook, so its webhook only reports this service's email. It's the cleaner setup, especially if
the account sends a lot of email from elsewhere, as each of those events is still a request the service
has to check and throw away.

The service only accepts events signed by SendGrid, checked with the provider's `webhook_public_key`.
Until that's set, its webhook address answers 404, so set the webhook up in this order:

1. Find the address to point it at: the provider's page (or the form for adding it) shows it, e.g.
   `https://smtp.example.org/webhooks/sendgrid-main`. It ends with the provider's name, so renaming the
   provider means changing the webhook too.
2. In SendGrid, under **Settings > Mail Settings > Event Webhooks**, create a webhook with the options
   below, and save it.
3. Copy the **Verification Key** it then shows (the public key) into the provider's
   **Webhook public key** (`webhook_public_key`), and save the provider.
4. Check it's set up, and that SendGrid's events reach the service (see
   [Checking the webhook works](#checking-the-webhook-works)).

| Option                          | Choose                                                                              |
|---------------------------------|-------------------------------------------------------------------------------------|
| Friendly name                   | Anything, e.g. `smtp-gateway`.                                                  |
| Post URL                        | The address from step 1.                                                            |
| Authorization method            | **None**. The signature is what proves an event is from SendGrid.                   |
| Deliverability data             | **All of them**: Processed, Dropped, Deferred, Bounced and Delivered.               |
| Engagement data: Spam Reports   | **On** (recommended): someone marked the email as spam, so stop emailing them.      |
| Engagement data: Opened/Clicked | Optional. Only sent if open or click tracking is on (Settings > Tracking).          |
| Engagement data: Unsubscribes   | Optional (Unsubscribed and Group Unsubscribes). Only with subscription tracking.    |
| Group Resubscribes              | Off. They're only recorded as "other" events.                                       |
| Account Status Change           | Off. It's about the account, not an email.                                          |
| Signature Verification          | **On** (required). Every unsigned event is rejected.                                |
| Enabled                         | **On**.                                                                             |

Clients can't change any of this per email: the service replaces any `X-SMTPAPI` header they send.

#### Checking the webhook works

There are two checks: that the webhook is set up right in SendGrid, and that its events actually get
here. The first doesn't prove the second.

**Its settings.** The sender checks on the **Providers** page read the webhook's settings from
SendGrid's API, and show "The event webhook is set up" when one posts to this provider's address, is
switched on, is signed with the key in `webhook_public_key`, and sends the deliverability events.
Otherwise, they say what's wrong. This needs the API key to have read access to **Event Notification**
(see [SendGrid's API key](#sendgrids-api-key)). Without it, they only say "Can't check the event
webhook". This check never sees an event arrive, so it passes even if SendGrid can't reach the
service, e.g. because of a firewall, or a `PUBLIC_URL` that's only reachable on your network.

**Its events arriving.** Use the webhook's **Test Integration** in SendGrid, which posts a set of
made-up events, signed like real ones. SendGrid only says it's sent them, so look at what the service
did with them in nginx's access log:

```bash
docker exec <project>-app grep '/webhooks/' /logs/nginx-access.log | tail
```

`<project>` is your `COMPOSE_PROJECT_NAME`. Look for the `POST /webhooks/<provider name>` sent when you
tested it:

| Status    | Means                                                                                        |
|-----------|----------------------------------------------------------------------------------------------|
| 200       | The events were accepted. Real ones about this service's emails are recorded.                |
| 401       | They're from SendGrid, but `webhook_public_key` isn't the key it signs them with. The **Logs** page also shows "Webhook request with an invalid signature". |
| 404       | `webhook_public_key` isn't saved yet, or the address doesn't end with the provider's name.   |
| (no line) | SendGrid couldn't reach the service: check the address in SendGrid, and that it's reachable from the internet. |

The test events aren't about any email the service sent, so they're ignored, like events about your
other systems' email: a 200 in the log is what shows the webhook works. To see an event recorded, send
an email through the provider, and look at its page a minute or so later.

#### Creating the webhook with SendGrid's API

With a key that has full access, SendGrid's
[Create an Event Webhook](https://www.twilio.com/docs/sendgrid/api-reference/webhooks/create-an-event-webhook)
and
[Toggle signature verification](https://www.twilio.com/docs/sendgrid/api-reference/webhooks/toggle-signature-verification-for-an-event-webhook)
endpoints do the same. Signature verification can't be switched on when it's created, so it's a second
request. Set the address from step 1, and read the key in without it being echoed:

```bash
WEBHOOK_URL='https://smtp.example.org/webhooks/sendgrid-main'
read -rsp 'Full SendGrid API key: ' SENDGRID_ADMIN_KEY; echo
```

Create the webhook, keeping its ID. Turn `open`, `click`, `unsubscribe` and `group_unsubscribe` on too
if you use that tracking:

```bash
WEBHOOK_ID=$(curl -s -X POST https://api.sendgrid.com/v3/user/webhooks/event/settings \
  -H "Authorization: Bearer $SENDGRID_ADMIN_KEY" \
  -H "Content-Type: application/json" \
  -d "{
    \"friendly_name\": \"smtp-gateway\",
    \"url\": \"$WEBHOOK_URL\",
    \"enabled\": true,
    \"processed\": true,
    \"dropped\": true,
    \"deferred\": true,
    \"bounce\": true,
    \"delivered\": true,
    \"spam_report\": true,
    \"open\": false,
    \"click\": false,
    \"unsubscribe\": false,
    \"group_unsubscribe\": false,
    \"group_resubscribe\": false
  }" | jq -r '.id')

echo "$WEBHOOK_ID"
```

If that prints `null`, the webhook wasn't created: run the `curl` again without `| jq -r '.id'` to see
SendGrid's error. Otherwise, switch on signature verification, which gives the public key:

```bash
curl -s -X PATCH "https://api.sendgrid.com/v3/user/webhooks/event/settings/signed/$WEBHOOK_ID" \
  -H "Authorization: Bearer $SENDGRID_ADMIN_KEY" \
  -H "Content-Type: application/json" \
  -d '{"enabled": true}' | jq -r '.public_key'

unset SENDGRID_ADMIN_KEY
```

Put the key it prints in the provider's `webhook_public_key` (step 3). It isn't a secret (it can only
check signatures, not make them), so it can be written into `settings.yaml` as it is.

## Mailgun

* **`region`**: `us` or `eu`.
* **`smtp_username`** and **`smtp_password`**: one of a sending domain's SMTP logins, e.g.
  `postmaster@mg.example.org`. Mailgun DKIM-signs everything sent with a login as its domain, so each
  domain you send as needs a provider of its own.
* **`api_key`** (optional): for the sender checks. It only needs to read domains and webhooks, e.g. an
  "Analyst" key, where Mailgun offers them.
* **`webhook_signing_key`** (optional): the account's HTTP webhook signing key (Settings > API
  Security). Point the domain's webhooks (Sending > Webhooks) at `PUBLIC_URL/webhooks/<provider name>`.
* **Headers**: every `X-Mailgun-*` header the client sent is removed (e.g. `X-Mailgun-Archive-To`
  posts a copy of the email to any URL), and `X-Mailgun-Variables` is set to the email's ID. Mailgun
  leaves that header in the delivered email.

## Postmark

* **`server_token`**: a server's API token, which is also its SMTP login.
* **`message_stream`**: the stream to send through (`outbound` by default).
* **`account_token`** (optional): an account API token, which Postmark needs for reading its domains
  and sender signatures, for the sender checks.
* **`webhook_secret`** (optional): Postmark doesn't sign its webhooks, so they use HTTP Basic auth
  instead. Add a webhook to the stream (Servers > the server > the stream > Webhooks) that posts to
  `PUBLIC_URL/webhooks/<provider name>`, with the username `webhook` and this secret as the password. It
  must be at least 24 characters, e.g. from `openssl rand -hex 24`.
* **Headers**: every `X-PM-*` header the client sent is removed (they choose the stream, and switch
  tracking on or off), and replaced with the stream, and an `X-PM-Metadata-*` header with the email's
  ID, which Postmark doesn't deliver.

## Brevo

* **`smtp_login`** and **`smtp_key`**: the login Brevo shows under SMTP & API > SMTP, and an SMTP key
  (not an API key).
* **`api_key`** (optional): for the sender checks. Brevo's API keys can't be limited to reading.
* **`webhook_secret`** (optional): Brevo doesn't sign its webhooks either. Add a transactional webhook
  (Transactional > Settings > Webhook) that posts to
  `https://webhook:<webhook_secret>@<your host>/webhooks/<provider name>`, or give the secret as its
  bearer token.
* **Headers**: every `X-Mailin-*` and `X-Sib-*` header the client sent is removed (e.g. `X-Sib-Sandbox`
  stops it being sent), and `X-Mailin-custom` is set to the email's ID, which Brevo leaves in the
  delivered email.

## Mailjet

* **`api_key`** and **`secret_key`**: from Account settings > API keys. They're also its SMTP login, and
  read its senders for the sender checks.
* **`webhook_secret`** (optional): Mailjet doesn't sign its event callbacks. Point each event type's
  callback (Account settings > Event tracking) at
  `https://webhook:<webhook_secret>@<your host>/webhooks/<provider name>`.
* **Headers**: every `X-MJ-*` and `X-Mailjet-*` header the client sent is removed (e.g.
  `X-MJ-TemplateErrorReporting` sends a copy to another address), and `X-MJ-CustomID` is set to the
  email's ID.

## MailerSend

* **`smtp_username`** and **`smtp_password`**: one of the account's SMTP users (Domains > the domain >
  SMTP).
* **`api_token`** (optional): for the sender checks. It needs read access to domains, and full access
  to webhooks to check them too, as MailerSend has no read-only access to them.
* **`webhook_secret`** (optional): the signing secret of a webhook (Integrations > Webhooks) that posts
  to `PUBLIC_URL/webhooks/<provider name>`, with version 2 payloads.
* **Headers**: MailerSend drops every custom header except `X-MailerSend-Tags`, so the email's ID is
  sent as a tag, replacing any the client set.

## Mailtrap

* **`smtp_token`**: one of a sending domain's API tokens, which is its SMTP password.
* **`api_token`** (optional): with at least viewer access to the account, for the sender checks.
* **`webhook_secret`** (optional): the signing secret of a webhook (Settings > Webhooks, with the JSON
  payload format) that posts to `PUBLIC_URL/webhooks/<provider name>`.
* **Headers**: every `X-MT-*` header the client sent is removed, and `X-MT-Custom-Variables` is set to
  the email's ID.

## Custom

An SMTP server of your own, e.g. mailcow or Postfix. It can't report events.

* **`host`**, **`port`** and **`security`**: where it is, and `tls` (STARTTLS), `ssl` (implicit TLS)
  or `none`.
* **`username`** and **`password`** (optional): its login, if it needs one.
* **`dkim_selector`** (optional): for the sender checks, to check each sender's domain publishes a DKIM
  key with it.

Its saved password is only ever sent to the server it was saved for: changing the `host`, `port`,
`security` or `username` means entering the password again, so nobody can have it sent to a server of
their own just by changing the host. The web session that entered it is the exception, so a typo in the
host can be fixed straight away. On the form, any other session has to type it again, as does a change
through the YAML editor or the API that keeps it as `<unchanged>`.
