# Checking senders

A provider refuses email from a sender it hasn't verified, and receivers put email that fails DMARC in
spam, or refuse it. So that's found before it happens, each email token's page (and the page shown when one
is created) checks every one of its allowed senders, and the **Providers** page checks every active token's
senders and each provider's account. Each check is marked OK, Note, Warning or Problem, with what to do
about it. They're fetched once the page has loaded, as they ask DNS and the providers' APIs, which takes a
few seconds. **Check again** runs them again, e.g. after adding a DNS record.

For each sender's domain, its own DNS:

* **DMARC**: a warning if there's no record (Gmail and Yahoo require one from bulk senders), a note if
  it's only monitoring (`p=none`), and a problem if there's more than one, or it's invalid (receivers
  then ignore it). A subdomain without its own record is covered by its parent's (using its `sp=`).
* **SPF**: a problem if there's more than one record (SPF then fails for everything sent as the domain),
  and a warning for `+all`.
* Whether it **can receive email** (an MX record, other than a "null MX", or an address record).
* Whether it's a **free email domain**, such as gmail.com, which can never be authenticated with a
  provider, as only its owner controls its DNS.

For each enabled **SendGrid** provider:

* Whether the sender's domain is **authenticated** (Settings > Sender Authentication), and if it is,
  whether SendGrid found all its DNS records, listing any it didn't. A subdomain is covered by its
  parent's authentication, unless its DMARC record asks for strict DKIM alignment (`adkim=s`).
* Otherwise, whether the address is a **verified single sender**. That's a warning, not OK: SendGrid
  accepts it, but signs it as sendgrid.net, so it fails DMARC, and it's a problem if the domain's DMARC
  policy is `quarantine` or `reject`.
* That the API key has **Mail Send**.
* That an **event webhook** posts to `PUBLIC_URL/webhooks/<provider name>`, is switched on, is signed
  with the public key in `webhook_public_key`, and sends the events that are recorded.

For each enabled **Mailgun** provider, which sends from any sender, but signs everything as the domain
it logs in as:

* Whether each sender's domain **aligns** with that domain: it's the same domain, or the same
  organization's (e.g. `example.org` for `mg.example.org`), unless its DMARC record asks for strict
  alignment. Otherwise the email fails DMARC, which is a problem if its policy is `quarantine` or
  `reject`.
* With an `api_key`: that the domain is in the account, **verified** (listing any DNS records Mailgun
  didn't find), not disabled, and not a sandbox domain, and that its **webhooks** for deliveries and
  bounces post here.

For each enabled **Postmark** provider:

* With an `account_token`, whether each sender's domain has its **DKIM** verified, or failing that, the
  address is a confirmed **sender signature** (a warning, as Postmark then signs it as pm.mtasv.net, so
  it fails DMARC), as for SendGrid. A domain whose **Return-Path** isn't verified is noted.
* That SMTP is switched on for the server, that it isn't in **sandbox mode** (which never delivers
  anything), and that the stream's **webhook** posts here, with the `webhook_secret` as its password, for
  deliveries and bounces.

For each enabled **Brevo** provider, with an `api_key`: whether each sender's domain is authenticated
(listing the records Brevo hasn't found), or the address is a verified sender (a warning), as for
SendGrid, that the account's SMTP relay is switched on, and that a transactional webhook posts here
with the `webhook_secret`, for deliveries and bounces.

For each enabled **Mailjet** provider: whether each sender's domain has its DKIM record found by
Mailjet (listing it if not), or the address is an active sender (a warning, as for SendGrid), and that
its event callbacks post here with the `webhook_secret`, for deliveries, bounces and blocks.

For each enabled **MailerSend** provider, with an `api_token`: whether each sender's domain is verified
with its DKIM records (MailerSend only sends from its verified domains), and that a webhook posts here,
signed with the `webhook_secret`, for deliveries and bounces.

For each enabled **Mailtrap** provider, with an `api_token`: whether each sender's domain is one of its
sending domains with its DNS verified (listing the records that aren't), whether Mailtrap has reviewed
each domain (until then, it only sends to the account's owner), and that a webhook posts here for
deliveries and bounces. Mailtrap's API doesn't show a webhook's signing secret, so that isn't checked.

Every provider's API is only ever read from (GET requests). SendGrid's API key only needs **Mail Send**
to send email, so the rest is optional: a check the key isn't allowed to make says which permission
would allow it. Giving it read access to **Sender Authentication** (`whitelabel.read`), the verified
senders, and the event webhook settings (`user.webhooks.event.settings.read`) lets everything be
checked. [SendGrid's API key](providers.md#sendgrids-api-key) has how to create one with just those.

For each enabled **custom** provider (an SMTP server of your own, e.g. mailcow), which can't be asked
which senders it accepts, what receivers check about the server itself:

* That each of the addresses its `host` resolves to has **reverse DNS** (a PTR record) whose name
  resolves back to it, which Gmail, Yahoo and Microsoft require.
* That each sender's domain's **SPF** record lets those addresses send as it. This follows `include:`,
  `redirect=`, `a`, `mx`, `ip4`, `ip6` and `exists`, with SPF's limit of 10 DNS lookups. A record that
  needs a macro (e.g. `%{i}`) to decide is only noted.
* If `dkim_selector` is set, that each sender's domain publishes a **DKIM key** at
  `<selector>._domainkey.<domain>`, and it hasn't been revoked (an empty `p=`). This checks the key is
  published, not that the server signs with it.

These assume the server sends from the address its hostname resolves to. If `host` resolves to a
private address (e.g. a server on the same network, or Mailpit), its public address isn't known, so
they're only noted. A server that hands its email on to another one (a "smarthost") needs that one
checked instead.
