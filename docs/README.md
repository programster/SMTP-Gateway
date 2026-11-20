# Documentation

The documentation for the SMTP Gateway. The [README](../README.md) has what it does, and a quick
start.

* [Configuration](configuration.md): `.env`, the Settings pages, and settings.yaml.
* [Providers](providers.md): the providers email is sent through, and how to set up each type.
* [Checking senders](sender-checks.md): the checks that each sender will be accepted and trusted.
* [Sending email](sending-email.md): over SMTP or the JSON API, and restricting an email token.
* [Rate limits and the queue](rate-limits.md): the three kinds of rate limit, priorities and retries.
* [Tracking](tracking.md): what's recorded, and the providers' event webhooks.
* [The web interface](web-interface.md): its pages, forgotten passwords, and logging in with Google.
* [The management API](management-api.md): managing the service with an API key.
* [Running on a small server](small-servers.md): the memory and CPU limits, and the values suggested for a server.
* [How long emails are kept](retention.md): retention, and the disk space emails take.
* [Adding a type of provider](adding-a-provider.md): writing a provider of your own.
* [Development](development.md): the development stack, publishing the image, and running the tests.
* [Security notes](security.md): how credentials, passwords and sessions are protected.
