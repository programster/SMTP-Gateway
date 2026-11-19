# The web interface

Its pages are in the side panel on the left. A section of it (e.g. **Settings**) opens to show its pages
when it's clicked, and the section of the page you're on starts open.

* **Dashboard**: how much is coming through, from which email tokens, and what happened to it. Each
  count links to what it counted. Its widgets show the most recent logins, the API keys and email tokens
  used last (each with when, and from which IP address), and the email tokens that sent the most over the
  last day, week, month or year (counting only the email records still kept).
* **Activity**: **Emails** and **Rejections**: what came through, and what was turned away and why.
* **Global rate limits**: the limits on everything sent, across every email token and provider. Each
  provider's own limits are set on its form, under **Settings > Providers**.
* **Access**:
  * **API keys**: the keys for the management API, each restricted to IP addresses. Each key's age is
    shown in days, in yellow from 9 months old and red from a year, as a reminder to replace it.
  * **Email tokens**: create them (each is only shown once, as only a hash of its secret is stored), see
    their restrictions and activity, edit them, and revoke them. Editing changes a token's name,
    restrictions, priority and rate limits, but never its ID or secret, so whatever uses it carries on
    working (see [Changing an email token](sending-email.md#changing-an-email-token)).
  * **Users**: the accounts that can log in to the web interface. There can be any number of them.
    A new account is emailed a link to choose its own password (which works for 72 hours, and needs
    `PUBLIC_URL` and the address in the Sending part of **Settings > General**), or given one by whoever adds it. A
    password given to it has to be changed when it first logs in, unless that's unticked: until then, it
    can only reach **My account**. Each account has a page, with its last login, where its email address
    can be changed. Accounts can be blocked, archived and deleted (see [Blocking, archiving and deleting
    accounts](#blocking-archiving-and-deleting-accounts)).
* **Logs**: the application's logs (stored in the database), a page at a time (50 by default), sorted
  by time or level, and filtered by time, by message (with an optional regular expression), by level,
  and by category: changes to the service, logins and account security, email, or the system. Times are
  shown as how long ago, as well as when. Clicking an entry shows it in full, with its context.
* **Settings**: a page for each part of the settings: **General** (the time zone, how dates and
  times are written, and the sending settings), **Providers** (the providers, how much each has sent,
  whether any is being skipped, and an editor for them), **Security**, **Proxies**, **Email retention** (how
  long emails and logs are kept) and **SSO** (logging in with Google): see
  [Settings](configuration.md#settings). Also **Server resources** (see
  [Running on a small server](small-servers.md)).
* **Documentation**: this documentation, and the API reference.
* **My account** (at the bottom): change your password, and set up two-factor authentication, with an
  authenticator app (TOTP), a security key such as a YubiKey (WebAuthn), or both.

Two-factor authentication is required for every account by default: an account without a second
factor can log in with its password, but can only reach **My account** until it has set one up, and it
can never remove its last one. Untick it under **Settings > Security** to make it optional.

Security keys only work over HTTPS (or on `localhost`), and only on the hostname they were registered
on.

### Backup codes

When an account sets up its first second factor, it's given six backup codes, shown once, to print,
download or copy. Each one can be used once, instead of the second factor, when logging in ("Lost your
authenticator app? Use a backup code" on the second factor step), for when the authenticator app or
security key is lost. Only a hash of each is stored. Logging in with one is logged as a warning, with
how many are left.

**My account** shows how many are left, and **Generate new backup codes** (which needs the account's
password) replaces them with six new ones: the old ones stop working, used or not. Do that when most
have been used, or if someone else may have seen them. Removing an account's last second factor
deletes its backup codes too, and it's given new ones when it sets one up again.

If someone loses their second factor and their backup codes, remove them with the command below
(while a second factor is required, they're asked to set up a new one the next time they log in):

```bash
docker exec -u www-data <container> php /var/www/site/scripts/reset-mfa.php <email>
```

## Blocking, archiving and deleting accounts

Each account's row on the **Users** page (and its own page) has buttons to block or unblock it and to
archive it. Nobody can do any of these to their own account, and the last account that can log in can't
be blocked or archived.

* **Blocking** stops the account logging in (by password, Google or a reset link), and logs it out
  everywhere, until it's unblocked. It stays in the list, marked "blocked". API keys and email tokens it
  created keep working, as they belong to the service.
* **Archiving** blocks it, and removes its email address, password, authenticator app, security keys,
  backup codes and reset links, keeping only its ID: a delete that's good enough for a GDPR erasure
  request. The service's own emails to it (e.g. password reset links) have its address replaced with
  `user <ID>`. It asks for your password. Archived accounts are only listed with **Show archived
  accounts** switched on, where each can be:
  * **restored**: given its email address again, and a password (or a link to choose one), keeping its
    ID, so its logs are still its own; or
  * **deleted** for good, with everything linked to it. This also asks for your password. Only an
    archived account can be deleted, so deleting always takes two steps.

The logs never record an account's email address, only its ID (`user_id` for the account an entry is
about, and `by_user_id` for the account that did it). On an entry's page, each account ID links to the
account, showing its email address, "Archived account", or "Deleted account". An account's page links to
the logs about it. So nothing in the logs has to change when an account is archived or deleted. Entries
from before this, naming accounts by email address, were converted where the address still belonged to
an account.

The same can be done through the management API (`/api/v1/users`), apart from restoring. Deleting an
account that isn't archived is refused with `409 Conflict` (error code 40).

## Forgotten passwords

Accounts log in with their email address. When `PUBLIC_URL` (in `.env`) and the address the service's
own emails are sent from (in the Sending part of **Settings > General**) are set, the login
page has a **Forgot your password?** link, which emails a link for choosing a new password. It goes
through the queue with the system priority (see [Rate limits and the queue](rate-limits.md)),
so ahead of every email token's emails, and counts towards the global and per-provider rate limits. It's listed
under **Emails** as sent by the service, but its content is never kept, or downloadable, as it holds the
link.
The link works once, for an hour, and at most one is sent to an account every five minutes. The page says
the same thing whether or not there's an account for the address, so it can't be used to find out which
addresses have one. Resetting the password logs the account out everywhere, but leaves its second factor
as it is, so someone who can read the account's email can't get in with that alone.

If email isn't working, reset a password on the server instead. This sets a new random password, and
prints it:

```bash
docker exec -u www-data <container> php /var/www/site/scripts/reset-password.php <email>
```

## Logging in with Google

Switch it on under **Settings > SSO**, with `PUBLIC_URL` set in
`.env`, and the ID and secret of an OAuth client of type "Web application" from the Google Cloud
console (**APIs & Services > Credentials**). The secret is never shown again once saved. Add the
redirect URI the page shows (`PUBLIC_URL`
followed by `/login/google/callback`, e.g. `https://smtp.example.org/login/google/callback`) to the
client's authorised redirect URIs. The login page then has a **Log in with Google** button, straight
away. Only existing accounts can log in this way, when Google has verified that the Google account has
the same email address.

By default ("never"), Google only replaces the password: an account with a second factor still has to
give it, and requiring a second factor still applies. Choose "always" to let Google be enough on its
own. Only do that if every Google account that can log in has two-step verification turned on (e.g.
enforced for your Google Workspace domain), as this service can't check. "Auto" only skips the second
factor when Google confirms the person used two-step verification (an `amr` claim including `mfa`). Google's ID tokens don't say how someone logged in at the moment, so for now `auto` is the same
as `never`, which errs on the safe side. Logging in with the password still needs the second factor
whichever it is.
