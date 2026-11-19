# Tracking

Every email accepted, queued, sent, failed or rejected is recorded, and shown on the dashboard (per
email token and overall, with an hourly chart), on the **Providers** page (per provider), and through the API.

With any provider but `custom`, deliveries, bounces, drops, deferrals, opens, clicks, spam reports and
unsubscribes can be recorded against each email too, through its webhook at
`https://<host>/webhooks/<the provider's name>` (see
[Types of provider](providers.md#types-of-provider) for how to set each up, and
[Checking senders](sender-checks.md), which checks it's set up). For SendGrid, see
[SendGrid's event webhook](providers.md#sendgrids-event-webhook), which has which options to choose,
and the `curl` commands for creating it with SendGrid's API.

Only events about this service's own emails are recorded. A provider's webhook can also report email
sent by other systems through the same account (SendGrid's does, for the whole account), and those are
ignored, so they aren't counted, and nothing about them is kept.

A provider's webhook stays switched off (404) until it has its key or secret (`webhook_public_key`,
`webhook_signing_key` or `webhook_secret`), as events that can't be checked can't be trusted.
