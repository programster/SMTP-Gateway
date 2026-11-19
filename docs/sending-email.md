# Sending email

## SMTP

The SMTP server is always encrypted:

* port **465** uses TLS from the start (implicit TLS, "SMTPS"), and
* port **587** requires `STARTTLS` before it will accept a login.

Log in with an email token: its ID as the username, and the whole token as the password (`AUTH PLAIN` or
`AUTH LOGIN`). An email token is one string, e.g. `et_8af651aadbe3ff25` followed by 64 hex characters,
whose first 19 characters are its ID. It's shown once, when it's created, as only a hash of its secret is
stored.

Every email is sent by the queue workers. When an email arrives, the service estimates how long until
a worker would start sending it, from the rate limits and the emails queued ahead of it. If that's
within the wait set in the Sending part of **Settings > General** (5 seconds by default), the connection waits for the outcome, and the reply says what happened:

```
250 2.0.0 Ok: sent as <id>      (the provider accepted it)
554 5.0.0 The email was refused (every provider it could use rejected it)
```

Otherwise (or if it isn't picked up in time, or a provider failed temporarily), the reply comes
straight away:

```
250 2.0.0 Ok: queued as <id>    (it will be sent as soon as the rate limits and providers allow)
```

Both `250` replies mean the email was accepted, so the client never needs to retry it. SMTP has no
standard way to tell them apart, so a client that cares has to read the text.

The SMTP server needs a TLS certificate. Put these two files, in PEM format, in the `./tls` folder,
which is mounted read-only at `/tls`, in both the production and development compose files:

| File | Contains |
|---|---|
| `./tls/fullchain.pem` | The certificate, followed by its intermediate certificate(s) |
| `./tls/privkey.pem` | Its private key, unencrypted (no passphrase) |

These are the names Let's Encrypt and certbot give them, so their files can be copied straight in (copy
the files themselves: certbot's `live` folder holds symlinks, which break when only that folder is
mounted). To use other names, set `SMTP_TLS_CERT_FILE` and `SMTP_TLS_KEY_FILE` to them (e.g.
`cert.pem`, with or without `./tls/` in front), and they're looked for in `./tls`. To use another
mounted folder, give full paths inside the container (e.g. `/certs/cert.pem`): anything starting with
`/` is used as it is. Set both or neither. The files are checked hourly, so a renewed
certificate is picked up without a restart.

Without a certificate, the service won't start. The only exception is development (`ENVIRONMENT=dev`),
where a self-signed certificate is generated if there isn't one: connections are still encrypted, but
clients have to be told not to verify the certificate. Putting the two files in `./tls` uses them in
development too.

## JSON API

```bash
curl \
  --header 'Authorization: Bearer <email token>' \
  --header 'Content-Type: application/json' \
  --data '{
    "from": {"email": "noreply@example.org", "name": "Example"},
    "to": [{"email": "someone@example.org"}],
    "subject": "Hello",
    "text": "Hello in plain text",
    "html": "<p>Hello in HTML</p>"
  }' \
  https://<host>/api/v1/send
```

It responds `201` if the email was sent while the request waited, `202` if it was queued, or `502` if
it was refused. Cc, Bcc, Reply-To and attachments are supported too. It takes the email token as a bearer
token, the same string as the SMTP password. The API doesn't take HTTP Basic auth. See the API
documentation.

## Restricting an email token

An email token can be restricted by any of four conditions, each optional. A condition that's left out
doesn't restrict anything, so a token with none can send as anyone, to anyone, from anywhere:

| Condition (`field`) | Restricts |
|---|---|
| `clientIp` | the IP addresses or CIDR ranges the token may be used from |
| `sender` | who emails may be from: the envelope sender, and the From and Sender headers |
| `replyTo` | what the Reply-To header may contain (an email without one is always fine) |
| `recipient` | who emails may be sent to: To, Cc and Bcc alike |

On the web form, each is a list, and leaving a list empty leaves the condition out. The IP address list
starts with the address you're using. Leaving it empty isn't recommended, as a leaked token would then
work from anywhere. Through the API, they're the `conditions` list, each condition given at most once:

```json
{
  "name": "Billing",
  "conditions": [
    {"field": "clientIp", "allowed": ["10.0.0.0/8", "192.168.1.20"]},
    {"field": "sender", "allowed": [{"type": "address", "value": "noreply@example.org"}]},
    {"field": "recipient", "allowed": [{"type": "domain", "value": "example.org"}]}
  ]
}
```

An address is allowed if any of its condition's rules allows it:

| Rule (form) | Rule (API) | Allows |
|---|---|---|
| `*@example.org` | `{"type": "domain", "value": "example.org"}` | anyone at exactly `example.org` (not its subdomains) |
| `someone@example.org` | `{"type": "address", "value": "someone@example.org"}` | just that address (ignoring case) |

Regular expressions aren't supported on purpose: they're too easy to get subtly wrong, e.g. an
unescaped `.` or a missing `$` letting through far more than intended. There's no rule for "anyone":
leave the condition out instead. An empty `allowed` list is refused, rather than taken to mean
anyone (or nobody), as it's ambiguous.

The checks happen as early as SMTP allows: the IP address and secret at login, the envelope sender at
`MAIL FROM`, each recipient at `RCPT TO`, and the From, Sender and Reply-To headers after `DATA`.
Headers that can't be parsed are rejected, rather than skipped. A client-supplied `X-SMTPAPI` header
(which SendGrid would otherwise use to add recipients) is always removed.

### Changing an email token

A token's name, conditions, priority and rate limits can be changed on its page in the web interface
(**Edit**), or through the management API, with `PUT` or `PATCH /api/v1/email-tokens/<id>`. Its ID and
secret never change, so whatever uses it carries on working, with its new restrictions from its next
request to the JSON API, or its next SMTP login. An SMTP connection that's already logged in keeps the
restrictions it logged in with until it ends. The new rate limits apply to its emails already in the
queue too, as they're checked when each is sent, but a new priority only applies to emails queued from
then on, as each email keeps the priority it was queued with. A revoked token can't be changed (the API
answers 409).

`PUT` replaces the whole token, so it needs every one of `name`, `conditions`, `rateLimits` and
`priority`, with an empty list for no conditions or no rate limits. Leaving one out is refused, rather
than taken to mean "no restriction", so a `PUT` meant to rename a token can't remove its restrictions:

```json
{
  "name": "Billing",
  "conditions": [
    {"field": "clientIp", "allowed": ["10.0.0.0/8"]},
    {"field": "sender", "allowed": [{"type": "address", "value": "noreply@example.org"}]}
  ],
  "rateLimits": [{"unit": "emails", "max": 10, "periodSeconds": 1}],
  "priority": 1
}
```

`PATCH` changes only what it's given, and keeps the rest. `name`, `rateLimits` and `priority` replace
the token's. Each condition in `conditions` replaces the token's condition on that field, and its other
conditions are kept, so this changes who it may send as, and keeps its IP addresses and recipients:

```json
{"conditions": [{"field": "sender", "allowed": [{"type": "domain", "value": "example.org"}]}]}
```

To remove a condition, so that field has no restriction, give it `"allowed": null`, e.g.
`{"conditions": [{"field": "replyTo", "allowed": null}]}`. Both answer with the token as it is now, as
`GET` does.

Creating a token (`POST`), `PUT` and `PATCH` all refuse a field a token doesn't have, e.g. a misspelt
`conditons`, which would otherwise be ignored, creating a token with no restrictions. They also refuse
the fields the service sets, such as `id` and `createdAt`.

