# Adding a type of provider

A provider ("driver") is a class implementing
[`SmtpProviderInterface`](../site/providers/SmtpProviderInterface.php), which is given each email in its
raw form (so nothing is lost on the way through) and either sends it, or throws to say why it couldn't.
To add a type:

1. Implement `SmtpProviderInterface`. Extending `SmtpRelayProvider` is the quickest way, for any
   provider with an SMTP relay, and gives you connections kept open between emails.
   `SendGridProvider` is an example.
2. Optionally implement `ProviderWebhookInterface`, if the provider can report events.
3. Add a case to `ProviderType`, a settings class implementing `ProviderSettingsInterface`, and read
   it in `ProvidersConfigParser`.
4. Return a [`SenderCheckerInterface`](../site/services/senders/SenderCheckerInterface.php) from the
   settings class's `fetchSenderChecker()`, which asks the provider's API whether it can send as each
   sender (see [Checking senders](sender-checks.md)). `SendGridSenderChecker` is an example: it reads
   the provider's authenticated domains and verified addresses with `JsonApiClient`, and hands them to
   `IdentityChecks`, which describes each sender in the provider's own terms (see `ProviderProfile`).
   `CustomSenderChecker` checks a server that has no API to ask.
