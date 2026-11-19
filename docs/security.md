# Security notes

* API keys and email tokens are each a prefix (`ak_` or `et_`), an ID of 8 random bytes, and a secret of
  32 random bytes, hex encoded. Only the SHA-256 hash of the secret is stored. A slow password hash isn't
  needed for random secrets of that length.
* Web passwords are hashed with PHP's `password_hash()`. Each TOTP code can only be used once.
* The SMTP server starts as root only to open ports 465 and 587, and drops to `www-data` before
  accepting any connection. Each connection runs in its own process.
* By default, the content of each email is deleted from the database as soon as the provider has
  accepted it (or it has failed). Only its record (sender, recipients, subject, size, status) is kept.
  Under **Settings > Email retention**, the content can be kept for a number of days (or for as long as the
  record), so it can be downloaded as a `.eml` file from the web interface or the API, and its
  attachments too (see [How long emails are kept](retention.md)).
* Every web form, and the web interface's JSON endpoints, check a CSRF token, and the
  Content-Security-Policy forbids inline JavaScript. Only `index.php` can be run by nginx.
