# Security notes

* API keys and email tokens are each a prefix (`ak_` or `et_`), an ID of 8 random bytes, and a secret of
  32 random bytes, hex encoded. Only the SHA-256 hash of the secret is stored. A slow password hash isn't
  needed for random secrets of that length.
* Web passwords are hashed with PHP's `password_hash()`. The default account's password is never in `.env`:
  only its hash is (`ADMIN_PASSWORD_HASH`, base64 encoded). Each TOTP code can only be used once.
* The SMTP server starts as root only to open ports 465 and 587, and drops to `www-data` before
  accepting any connection. Each connection runs in its own process.
* By default, the content of each email is deleted from the database as soon as the provider has
  accepted it (or it has failed). Only its record (sender, recipients, subject, size, status) is kept.
  Under **Settings > Email retention**, the content can be kept for a number of days (or for as long as the
  record), so it can be downloaded as a `.eml` file from the web interface or the API, and its
  attachments too (see [How long emails are kept](retention.md)).
* Every web form, and the web interface's JSON endpoints, check a CSRF token, and the
  Content-Security-Policy forbids inline JavaScript. Only `index.php` can be run by nginx.
* Failed logins are limited per IP address: after 5 wrong passwords, authenticator codes or backup codes
  within 15 minutes, every login attempt from that address is refused, for any account, until the 15
  minutes are up. It's never per account, so nobody can lock you out of your own account by failing to
  log in to it from somewhere else. An IPv6 address counts as its `/64`, as one machine usually has all
  of those. Each attempt is counted before it's checked (and given back if it's right), so a burst of
  attempts sent at once can't get round it, and only a completed login (not just the right password)
  clears an address's count. The first refusal is logged, under **Logs** (access). As it goes by IP
  address, it relies on the service seeing the real one: behind a reverse proxy, or Cloudflare, set them
  under **Settings > Proxies**, or every login would seem to come from the proxy.
* Settings can't be read from environment variables (as a `${NAME}` reference). A provider's settings
  are sent to wherever it says to, so anyone who could change the providers could otherwise have any
  variable the service can read (e.g. the database's password, or another provider's key) sent to their
  own server. Secrets are entered in the web interface, kept in `settings.yaml`, and never shown again.
* A custom provider's saved password can't be moved to another server (by changing its host, port,
  security or username) without being entered again, except by the web session that entered it. So
  someone who can change the providers, but doesn't know the password, can't have it sent to their own
  server. The other types only send their secrets to their own provider's servers.
