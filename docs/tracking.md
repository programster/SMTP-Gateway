# Tracking

Every email accepted, queued, sent, failed or rejected is recorded, and shown on the dashboard (per
email token and overall, with an hourly chart), on the **Providers** page (per provider), and through the API.

With any provider but `custom`, deliveries, bounces, drops, deferrals, opens, clicks, spam reports and
unsubscribes can be recorded against each email too, through its webhook at
`https://<host>/webhooks/<the provider's name>` (see
[Types of provider](providers.md#types-of-provider) for how to set each up, and
[Checking senders](sender-checks.md), which checks it's set up). For SendGrid:

1. In SendGrid, under **Settings > Mail Settings > Event Webhook**, set the URL to
   `https://<host>/webhooks/<the provider's name>`, choose the events, and turn on
   **Signed Event Webhook**.
2. Give the provider the public key it shows, as its `webhook_public_key` (e.g. through
   `SENDGRID_WEBHOOK_PUBLIC_KEY`).

A provider's webhook stays switched off (404) until it has its key or secret (`webhook_public_key`,
`webhook_signing_key` or `webhook_secret`), as events that can't be checked can't be trusted.
