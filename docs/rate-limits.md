# Rate limits and the queue

There are three kinds of rate limit, and an email is only sent once every one that applies allows it:

* **global** limits (under **Global rate limits**), on everything sent, across every email token and provider,
* **per-token** limits, set when the email token is created, and
* **per-provider** limits, set with the providers, e.g. to stay within each provider's plan.

The global and per-token limits count each email once. The per-provider limits count every time the
provider was handed an email, failed attempts included, as each one was a request to it: so if a
provider fails and the email moves on to another, the attempt still counts towards the first. Each
email's page (and `GET /api/v1/messages/{id}`) lists its attempts: which provider, when, and how each
one ended.

Each limit counts either **emails** (each email handed to a provider is one request, however many
recipients it has) or **recipients** (To, Cc and Bcc alike), over a sliding window: per second, minute,
hour, day, week or month (a sliding 30 days) in the web interface, or any number of seconds up to 31
days through the API. There are no limits until you add some.

An email over a limit isn't refused. It's queued, and sent as soon as every limit allows. A token's
emails are sent in the order they came in, and a token that has hit its own limit doesn't hold up the
others.

Each email token has a **priority**, from 1 (the lowest, and the default) to 9998, set when it's created. When
there's room under the limits, the highest priority email goes first, and emails of the same priority go
in the order they came in. An email waiting for room under the global limits keeps that room for itself,
so no lower priority email can take it. One waiting for room under the providers' limits keeps the room
under the providers it could use (its token's, if it's [restricted to particular
providers](sending-email.md#restricting-the-providers)), so a lower priority email can still go
through any others. Priority never gets an email past a limit. The
service's own emails (password reset links) have priority 9999, so they always go first. The only exception is an email with more recipients than a recipient limit allows, which could
never be sent: over SMTP, the extra recipients are refused at `RCPT TO` with `452 4.5.3`, which tells
the client to send them in another email, and through the API, the request is refused.

`QUEUE_WORKERS` workers (4 by default) do all the sending, under supervisor. Each keeps its connections
to the providers open between emails, which makes sending far faster, and they're woken by PostgreSQL
`NOTIFY` as soon as an email arrives. They never claim the same email, and between them never send more
than the limits allow. When every provider has failed temporarily, an email is retried after 1, 5 and 15
minutes, then 1 and 4 hours, before being marked as failed. Revoking an email token cancels any of its emails
still in the queue.

Rate limits control how fast emails are sent, not how fast they're accepted. A token can queue far more
than its limits will send for a long time, and each queued email is stored in full until it's sent, so
a leaked token can use a lot of disk space (see [Disk space](retention.md#disk-space)). Revoking it frees that space.
