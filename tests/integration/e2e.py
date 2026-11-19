"""
The main paths through the service, from logging in for the first time (and being made to set up a second factor),
through API keys and keys, sending over SMTP on both ports and through the send endpoint, to the stats, logs and
revoking a key.

This must be the first suite run against a fresh stack: it counts every email Mailpit has caught, and sets up the
admin account's second factor, which the other suites log in with.
"""

import base64
import html
import os
import re
import smtplib
import time

from lib import stack
from lib.harness import Suite
from lib.service import SmtpReply, create_tls_context, send_data
from lib.web import (WebClient, basic, bearer, call_api, create_unused_totp_code, find_csrf_token,
                     save_totp_secret)

suite = Suite('End to end')
web = WebClient()


def create_email(header_from: str = 'noreply@example.org', extra_headers: str = '') -> str:
    """An email with a line starting with a dot, which SMTP has to escape and unescape."""
    return (
        f'From: Service <{header_from}>\r\nTo: someone@example.com\r\nSubject: Test {time.time()}\r\n{extra_headers}'
        '\r\nHello\r\n.leading dot line\r\n'
    )


def send(connection: smtplib.SMTP, email: str) -> SmtpReply:
    """Send an email from noreply@example.org to someone@example.com, on a connection that's logged in."""
    connection.mail('noreply@example.org')
    code, text = connection.rcpt('someone@example.com')
    reply = SmtpReply(code, text.decode())

    if code == 250:
        reply = send_data(connection, email)
    else:
        connection.rset()

    return reply


# Logging in for the first time, which has to set up a second factor (REQUIRE_MFA is on by default).
response = web.request('GET', '/login')
suite.check('login page loads', response.get_status() == 200)
form = {'csrf_token': find_csrf_token(response.get_body()), 'email': stack.ADMIN_EMAIL, 'password': 'wrong-password-1'}
suite.check('wrong password refused', web.request('POST', '/login', form).get_status() == 401)
form = {'csrf_token': find_csrf_token(web.get('/login')), 'email': stack.ADMIN_EMAIL, 'password': stack.ADMIN_PASSWORD}
response = web.request('POST', '/login', form)
suite.check('login succeeds', response.get_status() == 303 and response.get_header('Location') == '/', response)
response = web.request('GET', '/')
suite.check('MFA required: dashboard sends to /account', response.get_header('Location') == '/account')
response = web.request('GET', '/email-tokens')
suite.check('MFA required: other pages send to /account', response.get_header('Location') == '/account')
response = web.request('GET', '/account')
account_page = response.get_body()
explained = 'Two-factor authentication is required' in account_page
suite.check('MFA required: account page explains', response.get_status() == 200 and explained)
headers = {'X-CSRF-Token': find_csrf_token(account_page), 'Accept': 'application/json'}
response = web.request('POST', '/account/security-keys/options', body={}, headers=headers)
is_allowed = response.get_status() == 200 and 'publicKey' in response.get_body()
suite.check('MFA required: security key set up still allowed', is_allowed, response.get_body()[:200])

totp_page = web.get('/account/totp')
secret = re.search(r'by hand:\s+<code>([A-Z2-7 ]+)</code>', totp_page).group(1).replace(' ', '')
response = web.request('POST', '/account/totp', {'csrf_token': find_csrf_token(totp_page), 'code': '000000'})
suite.check('wrong TOTP code refused at set up', response.get_status() == 400)
used_code = create_unused_totp_code(stack.ADMIN_EMAIL, secret)
response = web.request('POST', '/account/totp', {'csrf_token': find_csrf_token(totp_page), 'code': used_code})
suite.check('TOTP enabled', response.get_status() == 303)
save_totp_secret(stack.ADMIN_EMAIL, secret)
form = {'csrf_token': find_csrf_token(web.get('/account')), 'current_password': stack.ADMIN_PASSWORD}
response = web.request('POST', '/account/totp/remove', form)
is_refused = response.get_status() == 400 and 'can&apos;t be removed' in response.get_body()
suite.check('MFA required: last second factor can\'t be removed', is_refused, response.get_status())
response = web.request('GET', '/')
suite.check('dashboard loads', response.get_status() == 200 and 'Dashboard' in response.get_body())
suite.check('CSP header set', "script-src 'self'" in response.get_header('Content-Security-Policy'))
response = web.request('POST', '/api-keys', {'name': 'x', 'allowed_ips_0': '0.0.0.0/0'})
suite.check('POST without CSRF token refused', response.get_status() == 403, response.get_status())

# API keys, for the management API: one string, its 19-character ID (ak_ and 16 hex) then a 64-hex secret.
page = web.get('/api-keys')
has_rows = 'name="allowed_ips_0"' in page and 'data-role="add-row"' in page and 'name="allowed_ips"' not in page
suite.check('the API key form has its allowed IP addresses as rows', has_rows)
suite.check('with the rows\' script', 'src="/js/ApiKeysPage.js"' in page)
form = {'name': 'e2e', 'allowed_ips_0': '0.0.0.0/0', 'allowed_ips_1': '::/0'}
response = web.post_form('/api-keys', '/api-keys', form)
match = re.search(r'id="api-key" value="(ak_[0-9a-f]{16})([0-9a-f]{64})"', response.get_body())
suite.check('API key created, as its ID then its secret', response.get_status() == 200 and match is not None)
api_key = match.group(1) + match.group(2)
auth = bearer(api_key)
suite.check('its ID is shown on the page', f'<code>{match.group(1)}</code>' in response.get_body())
example_pattern = r"<pre>curl \\\n  --header 'Authorization: Bearer [^']+' \\\n  (http\S+)/api/v1/email-tokens</pre>"
example = re.search(example_pattern, response.get_body())
suite.check('its curl example has an option per line, and the site\'s address', example is not None
            and example.group(1) == os.environ.get('PUBLIC_URL', example.group(1)), response.get_body()[-900:])
response = web.post_form('/api-keys', '/api-keys', {'name': 'restricted', 'allowed_ips_0': '10.99.99.0/24'})
restricted = re.search(r'id="api-key" value="(ak_[0-9a-f]{80})"', response.get_body()).group(1)
response = call_api('GET', '/email-tokens', bearer(restricted))
is_refused = response.get_status() == 403 and response.get_error_code() == 13
suite.check('API key refused from a disallowed IP', is_refused, response.get_body())
response = call_api('GET', '/email-tokens', '')
suite.check('API without an API key is 401 JSON', response.get_status() == 401 and response.get_error_code() == 10)
response = call_api('GET', '/email-tokens', bearer(api_key[:-1] + ('0' if api_key[-1] != '0' else '1')))
suite.check('API key with the wrong secret refused', response.get_status() == 401 and response.get_error_code() == 11)
response = call_api('GET', '/email-tokens', basic(api_key[:19], api_key))
suite.check('API key as Basic auth refused', response.get_status() == 401 and response.get_error_code() == 10)
form = {'name': 'bad', 'allowed_ips_0': '10.1.2.3', 'allowed_ips_1': '10.0.0.5/8'}
response = web.post_form('/api-keys', '/api-keys', form)
body = response.get_body()
is_helpful = response.get_status() == 400 and 'Did you mean' in body and 'row 2' in body
suite.check('bad CIDR gives a helpful error, naming its row', is_helpful, response.get_status())
suite.check('and the rows are kept', 'value="10.1.2.3"' in body and 'value="10.0.0.5/8"' in body)
response = web.post_form('/api-keys', '/api-keys', {'name': 'none', 'allowed_ips_0': ''})
is_refused = response.get_status() == 400 and 'At least one allowed IP address' in response.get_body()
suite.check('an API key with no allowed IP addresses is refused', is_refused, response.get_status())

# Email tokens, through the management API.
policy = {
    'name': 'e2e service',
    'conditions': [
        {'field': 'clientIp', 'allowed': ['0.0.0.0/0', '::/0']},
        {'field': 'sender', 'allowed': [{'type': 'address', 'value': 'noreply@example.org'}]},
        {'field': 'recipient', 'allowed': [{'type': 'domain', 'value': 'example.com'}]},
    ],
    'rateLimits': [{'unit': 'emails', 'max': 3, 'periodSeconds': 60}],
}

response = call_api('POST', '/email-tokens', auth, policy)
suite.check('email token created', response.get_status() == 201, response.get_body())
created_conditions = response.get_json()['emailToken']['conditions'] if response.get_status() == 201 else []
key_id = response.get_json()['emailToken']['id']
key_token = response.get_json()['token']
is_formed = re.fullmatch(r'et_[0-9a-f]{80}', key_token) is not None and key_token.startswith(key_id)
suite.check('the token is its ID then a 64-hex secret', is_formed and len(key_id) == 19, key_token)
response = call_api('GET', '/email-tokens', bearer(key_token))
is_refused = response.get_status() == 401 and 'That\'s an email token' in response.get_body()
suite.check('an email token given to the management API is refused, saying so', is_refused, response.get_body())
fields = [condition['field'] for condition in created_conditions]
suite.check('only the conditions given are returned', fields == ['clientIp', 'sender', 'recipient'], fields)

refusals = {
    'an empty "allowed" list': ([{'field': 'recipient', 'allowed': []}], 'leave out'),
    'a field given twice': ([{'field': 'sender', 'allowed': [{'type': 'domain', 'value': 'example.org'}]}] * 2,
                            'second "sender"'),
    'an unknown field': ([{'field': 'subject', 'allowed': ['x']}], '"field" of clientIp'),
    'an "any" rule': ([{'field': 'replyTo', 'allowed': [{'type': 'any'}]}], 'leave the condition out'),
    'an IP that isn\'t one': ([{'field': 'clientIp', 'allowed': ['10.0.0.300']}], 'conditions[0].allowed[0]'),
}

for description, (bad_conditions, expected_message) in refusals.items():
    response = call_api('POST', '/email-tokens', auth, policy | {'conditions': bad_conditions})
    is_refused = response.get_status() == 400 and expected_message in response.get_json()['error']['message']
    suite.check(f'a condition with {description} is refused', is_refused, response.get_body())

response = call_api('POST', '/email-tokens', auth, {'name': 'no conditions'})
unrestricted = response.get_json() if response.get_status() == 201 else {}
is_created = unrestricted.get('emailToken', {}).get('conditions') == []
suite.check('an email token can have no conditions at all', is_created, response.get_body())
response = web.post_form('/email-tokens/new', '/email-tokens', {'name': 'form without conditions'})
suite.check('the form with every list left empty creates one too', response.get_status() == 200, response.get_status())
listed = call_api('GET', '/email-tokens', auth).get_json()['emailTokens']
from_form = [token for token in listed if token['name'] == 'form without conditions']
suite.check('with no conditions', len(from_form) == 1 and from_form[0]['conditions'] == [], from_form)
page = web.get(f"/email-tokens/{from_form[0]['id']}") if from_form else ''
suite.check('and its page says it allows anyone', 'Anyone' in page and 'Any IP address' in page)
limits = [{'unit': 'emails', 'max': 1, 'periodSeconds': 60}, {'unit': 'emails', 'max': 5, 'periodSeconds': 60}]
response = call_api('POST', '/email-tokens', auth, policy | {'rateLimits': limits})
is_refused = response.get_status() == 400 and 'same period' in response.get_body()
suite.check('duplicate unit and period refused', is_refused, response.get_body())
response = call_api('POST', '/email-tokens', auth, policy | {'rateLimits': [{'max': 1, 'periodSeconds': 60}]})
is_refused = response.get_status() == 400 and '"unit"' in response.get_json()['error']['message']
suite.check('rate limit without a unit refused', is_refused, response.get_body())

limits = [
    {'unit': 'emails', 'max': 10, 'periodSeconds': 1},
    {'unit': 'recipients', 'max': 50000, 'periodSeconds': 2592000},
    {'unit': 'recipients', 'max': 100, 'periodSeconds': 1},
]

response = call_api('POST', '/email-tokens', auth, policy | {'name': 'mixed', 'rateLimits': limits})
is_created = response.get_status() == 201 and len(response.get_json()['emailToken']['rateLimits']) == 3
suite.check('emails and recipients limits can be mixed', is_created, response.get_body())
no_limits = {name: value for name, value in policy.items() if name != 'rateLimits'}
response = call_api('POST', '/email-tokens', auth, no_limits | {'name': 'no limits'})
is_unlimited = response.get_status() == 201 and response.get_json()['emailToken']['rateLimits'] == []
suite.check('rate limits default to none', is_unlimited, response.get_body())
response = call_api('GET', '/rate-limits', auth)
is_unlimited = response.get_status() == 200 and response.get_json()['rateLimits'] == []
suite.check('global rate limits default to none', is_unlimited, response.get_body())
response = call_api('GET', '/email-tokens', auth)
listed = [key['id'] for key in response.get_json()['emailTokens']]
suite.check('email tokens listed', response.get_status() == 200 and key_id in listed)

# SMTP: the submission port (587), which only allows logging in after STARTTLS.
smtp = smtplib.SMTP('localhost', stack.SUBMISSION_PORT, timeout=20)
smtp.ehlo('test')
suite.check('587 advertises STARTTLS but not AUTH before TLS', smtp.has_extn('starttls') and not smtp.has_extn('auth'))
code, text = smtp.docmd('AUTH', 'PLAIN ' + base64.b64encode(f'\0{key_id}\0{key_token}'.encode()).decode())
suite.check('AUTH refused before STARTTLS', code == 538, (code, text))
smtp.starttls(context=create_tls_context())
smtp.ehlo('test')
suite.check('AUTH offered after STARTTLS', smtp.has_extn('auth'))



def check_login_refused(name: str, username: str, password: str, text: bytes) -> None:
    """
    Check an SMTP login is refused with a 535, and a reply containing the text given. Each is on a connection of its
    own, as smtplib tries each mechanism (PLAIN, then LOGIN) when one is refused, and the third failure on a
    connection closes it.
    """
    connection = smtplib.SMTP_SSL('localhost', stack.SMTPS_PORT, timeout=20, context=create_tls_context())

    try:
        connection.login(username, password)
        suite.check(name, False, 'it was accepted')
    except smtplib.SMTPAuthenticationError as error:
        suite.check(name, error.smtp_code == 535 and text in error.smtp_error, error.smtp_error)

    connection.close()


check_login_refused('wrong secret refused', key_id, key_id + 'f' * 64, b'Invalid credentials')
check_login_refused('a username that isn\'t the token\'s ID refused', 'et_0000000000000000', key_token, b'username')
check_login_refused('an API key as the password refused, saying so', api_key[:19], api_key, b'That\'s an API key')
smtp.login(key_id, key_token)

for number in range(1, 4):
    reply = send(smtp, create_email())
    suite.check(f'587 email {number} sent immediately', reply.is_sent(), reply)

reply = send(smtp, create_email())
suite.check('587 email 4 queued (rate limit 3 a minute)', reply.is_queued(), reply)
code, text = smtp.mail('evil@example.org')
smtp.rset()
suite.check('disallowed envelope sender refused', code == 550, (code, text))
smtp.mail('noreply@example.org')
code, text = smtp.rcpt('someone@gmail.com')
smtp.rset()
suite.check('disallowed recipient refused at RCPT', code == 550, (code, text))
reply = send(smtp, create_email(header_from='ceo@example.org'))
suite.check('disallowed From header refused after DATA', reply.get_code() == 550, reply)
reply = send(smtp, create_email(extra_headers='From: ceo@bank.example (comment)\r\n'))
suite.check('extra From header with comment still checked', reply.get_code() == 550, reply)
smtp.quit()

# SMTP: the implicit TLS port (465).
smtp = smtplib.SMTP_SSL('localhost', stack.SMTPS_PORT, timeout=20, context=create_tls_context())
smtp.ehlo('test')
suite.check('465 implicit TLS offers AUTH', smtp.has_extn('auth'))
smtp.login(key_id, key_token)
reply = send(smtp, create_email())
suite.check('465 email queued behind the earlier one', reply.is_queued(), reply)
smtp.quit()

# The JSON send endpoint.
key_auth = bearer(key_token)

email = {
    'from': {'email': 'noreply@example.org', 'name': 'Svc'},
    'to': [{'email': 'a@example.com'}],
    'bcc': [{'email': 'b@example.com'}],
    'subject': 'API test',
    'text': 'Hello',
    'html': '<p>Hello</p>',
    'attachments': [{'filename': 'a.txt', 'content': base64.b64encode(b'hi').decode(), 'contentType': 'text/plain'}],
}

response = call_api('POST', '/send', key_auth, email)
is_queued = response.get_status() == 202 and response.get_json()['status'] == 'queued'
suite.check('API send accepted (queued, as rate limited)', is_queued, response.get_body())
response = call_api('POST', '/send', key_auth, email | {'bcc': [{'email': 'x@gmail.com'}]})
is_refused = response.get_status() == 403 and response.get_error_code() == 22
suite.check('API send to disallowed bcc refused', is_refused, response.get_body())
response = call_api('POST', '/send', bearer(key_id + 'f' * 64), email)
is_refused = response.get_status() == 401 and response.get_error_code() == 11
suite.check('API send with wrong secret refused', is_refused, response.get_body())
response = call_api('POST', '/send', basic(key_id, key_token), email)
is_refused = response.get_status() == 401 and response.get_error_code() == 10
suite.check('API send with Basic auth refused', is_refused, response.get_body())
response = call_api('POST', '/send', auth, email)
is_refused = response.get_status() == 401 and 'That\'s an API key' in response.get_body()
suite.check('API send with an API key refused, saying so', is_refused, response.get_body())

# Stats and messages.
response = call_api('GET', '/stats', auth)
activity = response.get_json()['activity']
suite.check('stats show accepted emails', activity['acceptedLastHour'] >= 6, activity)
suite.check('stats show queued emails', activity['queuedNow'] >= 1, activity)
suite.check('stats show rejections', activity['rejectedLast24Hours'] >= 4, activity)
response = call_api('GET', f'/messages?emailTokenId={key_id}&status=sent', auth)
is_filtered = response.get_status() == 200 and len(response.get_json()['messages']) == 3
suite.check('messages filtered by status', is_filtered, response.get_body()[:300])
suite.check('bad status filter is 400', call_api('GET', '/messages?status=bogus', auth).get_status() == 400)
suite.check('stats show the rejections in the last hour and week too', activity['rejectedLastHour'] >= 4
            and activity['rejectedLast7Days'] >= activity['rejectedLast24Hours'], activity)

# The dashboard's tiles link to what they counted.
page = web.get('/')
links = ['href="/messages?within=7d"', 'href="/messages?status=queued"', 'href="/rejections?by=service&amp;within=24h"',
         'href="/rejections?by=provider&amp;within=24h"', 'href="/messages?progress=failed_queued&amp;within=24h"',
         'href="/messages?progress=retrying"', 'href="/messages?event=delivered&amp;within=7d"']
suite.check('the dashboard\'s tiles link to the lists of what they counted', all(link in page for link in links),
            [link for link in links if link not in page])
suite.check('what wasn\'t sent is broken down, each with a line saying what it is', 'Turned away before being'
            ' accepted' in page and 'Queued, then no provider took it' in page)
has_help = 'data-dialog="not-sent-help"' in page and 'Each email is counted once, however many' in page
suite.check('and a help dialog explaining each in full', has_help and '/js/HelpDialogsOnPage.js' in page)
rejected = int(stack.sql("SELECT count(*) FROM rejected_request WHERE reason <> 'provider_rejected'"
                         " AND created_at > now() - interval '24 hours'"))
tile = re.search(r'tile-value">([\d,]+)</span><span\s+class="tile-label">Rejected by this service', page)
suite.check('the requests this service turned away are counted', tile is not None
            and int(tile.group(1).replace(',', '')) == rejected, (tile and tile.group(1), rejected))
page = web.get('/rejections?by=service&within=24h')
suite.check('and listed, without any a provider refused', '>provider_rejected</span>' not in page
            and 'badge-failed' in page)
page = web.get('/messages?progress=retrying')
suite.check('the emails being retried are filtered', 'badge-sent' not in page and 'badge-failed' not in page)
page = web.get('/messages?within=7d')
suite.check('the emails of the last 7 days are listed', f'/email-tokens/{key_id}' in page)
page = web.get('/messages?within=minute&status=queued')
suite.check('the queued filter shows only queued emails', 'badge-sent' not in page)
page = web.get('/rejections?within=24h')
suite.check('the rejected requests of the last 24 hours are listed', 'badge-failed' in page)
page = web.get('/rejections?within=minute&email_token=et_0000000000000000')
suite.check('and filtered by email token', 'None.' in page)
page = web.get(f'/email-tokens/{key_id}')
suite.check('an email token\'s tiles filter by it too', f'href="/messages?email_token={key_id}&amp;within=7d"' in page)
is_shown = re.search(r'Last used: [^<]+, from\s+<span', page) is not None
suite.check('and its page says where it was last used from', is_shown)

# The dashboard's widgets: who and what was used last, from where, and the most used email tokens.
page = web.get('/')
admin_id = stack.sql(f"SELECT id FROM admin_user WHERE email = '{stack.ADMIN_EMAIL}'")
suite.check('the dashboard lists the recent logins, linking to the user', f'href="/admin/users/{admin_id}"' in page
            and '<a href="/admin/users">Recent logins</a>' in page)
suite.check('with where they logged in from', page.count('from <span class="mono">') >= 3)
suite.check('and the API keys and email tokens used last', '<a href="/api-keys">API keys last used</a>' in page
            and f'<a href="/email-tokens/{key_id}">e2e service</a>' in page)
suite.check('and the most used email tokens, over a week', 'id="most-used-email-tokens"' in page
            and '?usage=week#most-used-email-tokens" aria-current="true"' in page)
suite.check('each linking to the emails it counted', f'/messages?email_token={key_id}&amp;within=7d' in page)
page = web.get('/?usage=year')
suite.check('over a year, saying only the records kept are counted', f'within=365d' in page and 'are kept for' in page)
suite.check('a mistyped period is a week', 'usage=week#most-used-email-tokens" aria-current' in web.get('/?usage=x'))
recorded = stack.sql(f"SELECT last_login_ip IS NOT NULL FROM admin_user WHERE id = '{admin_id}'")
suite.check('a login records where it was from', recorded == 't')
suite.check('the users page shows it', re.search(r'from\s+<span class="mono">', web.get('/admin/users')) is not None)
suite.check('so does the API keys page', re.search(r'from\s+<span class="mono">', web.get('/api-keys')) is not None)
stack.sql("UPDATE api_key SET created_at = now() - interval '300 days' WHERE name = 'restricted'")
stack.sql("UPDATE api_key SET created_at = now() - interval '400 days' WHERE name = 'e2e'")
page = web.get('/api-keys')
suite.check('each API key\'s age is shown in days', '<th>Age</th>' in page and 'class="key-age' in page)
suite.check('in yellow from 9 months', 'age-warning" title="Nearly a year old">300 days</span>' in page)
suite.check('and in red from a year', 'class="key-age age-danger"' in page and '>400 days</span>' in page)
last_used = r'(\w+ ago|just now)\s*<br><span class="hint">\d\d:\d\d:\d\d \d\d-\d\d-\d{4}, from'
suite.check('last used says how long ago, then when', re.search(last_used, page) is not None)
suite.check('and the email tokens page', re.search(r'from\s+<span class="mono">', web.get('/email-tokens')) is not None)

# What Mailpit received.
time.sleep(1)
caught = stack.call_mailpit(stack.PRIMARY_MAILPIT, 'GET', '/messages')
suite.check('Mailpit received 3 emails', caught['total'] == 3, caught['total'])
raw = stack.get_mailpit_raw(caught['messages'][0]['ID'])
suite.check('dot-stuffed line survived', '\n.leading dot line' in raw)

# Sent after counting Mailpit's emails above, so it doesn't change the count.
anything = {name: value for name, value in email.items() if name != 'bcc'}
anything = anything | {'from': {'email': 'whoever@elsewhere.example'}, 'to': [{'email': 'x@gmail.com'}]}
response = call_api('POST', '/send', bearer(unrestricted.get('token', '')), anything)
is_sent = response.get_status() in (201, 202)
suite.check('an email token with no conditions sends as anyone, to anyone', is_sent, response.get_body())

# Global rate limits.
limits = [{'unit': 'emails', 'max': 10, 'periodSeconds': 1}, {'unit': 'recipients', 'max': 3000, 'periodSeconds': 3600}]
response = call_api('PUT', '/rate-limits', auth, {'rateLimits': limits})
is_replaced = response.get_status() == 200 and len(response.get_json()['rateLimits']) == 2
suite.check('global rate limits replaced', is_replaced, response.get_body())
call_api('PUT', '/rate-limits', auth, {'rateLimits': []})

# Every page renders.
pages = ['/email-tokens', f'/email-tokens/{key_id}', '/messages', '/rejections', '/rate-limits', '/api-keys',
         '/providers', '/admin/users', '/admin/logs', '/account', '/email-tokens/new', '/docs', '/docs/swagger']

for path in pages:
    response = web.request('GET', path)
    is_rendered = response.get_status() == 200 and 'Whoops' not in response.get_body()
    suite.check(f'page {path} renders', is_rendered, (response.get_status(), response.get_body()[:200]))

suite.check('unknown email token is a 404 page', web.request('GET', '/email-tokens/not-a-uuid').get_status() == 404)

# The documentation, in the side panel.
page = web.get('/documentation')
suite.check('the documentation\'s overview is the README', '<h1 id="smtp-api-service">SMTP API Service</h1>' in page)
page = web.get('/documentation/rate-limits')
suite.check('a page of the documentation is shown', 'id="rate-limits-and-the-queue"' in page)
suite.check('its links go to the other pages', 'href="/documentation/retention#disk-space"' in page)
is_marked = 'href="/documentation/rate-limits" aria-current="page"' in page and page.count('aria-current="page"') == 1
suite.check('and the side panel marks it, and only it', is_marked)
open_section = r'<details class="nav-section" open>\s*<summary>(?:(?!</summary>).)*<span>Documentation</span>'
suite.check('in the Documentation section, which is open', re.search(open_section, page, re.S) is not None)
suite.check('an unknown page is a 404', web.request('GET', '/documentation/nonsense').get_status() == 404)
suite.check('as is any other file', web.request('GET', '/documentation/..%2F.env.example').get_status() == 404)

# The sender checks, which the key and providers pages fetch as JSON. The test stack's provider is plain SMTP
# (Mailpit), which can't be asked what it allows, so it only notes that.
json_headers = {'Accept': 'application/json'}

for path in [f'/email-tokens/{key_id}/sender-checks', '/providers/sender-checks']:
    response = web.request('GET', path, headers=json_headers)
    groups = response.get_json()['groups'] if response.get_status() == 200 else []
    sources = {check['source'] for group in groups for check in group['checks']}
    is_checked = len(groups) > 0 and 'DNS' in sources and len(sources) > 1
    suite.check(f'{path} checks DNS and the providers', is_checked, response.get_body()[:500])

response = web.request('GET', '/email-tokens/not-a-uuid/sender-checks', headers=json_headers)
is_json_404 = response.get_status() == 404 and 'error' in response.get_json()
suite.check('sender checks of an unknown key are a JSON 404', is_json_404, response.get_body()[:200])

# The log viewer.
page = web.get('/admin/logs?search=WEB+LOGIN')
suite.check('log search is case-insensitive contains', 'Failed web login' in page and 'email token created' not in page)
suite.check('log regex search', 'Failed web login' in web.get('/admin/logs?search=%5Efailed+web&regex=1'))
response = web.request('GET', '/admin/logs?search=%28unclosed&regex=1')
is_refused = response.get_status() == 400 and 'valid regular expression' in response.get_body()
suite.check('invalid regex gives an error', is_refused, response.get_status())
suite.check('log "from" filter alone works', 'No log entries' in web.get('/admin/logs?from=2099-01-01T00:00'))
suite.check('log "to" filter alone works', 'No log entries' in web.get('/admin/logs?to=2000-01-01T00:00'))

# Pages of the logs: 50 by default, or as many as chosen, sorted by time or level, and filtered by level. Some more
# entries are logged first, so there's always more than one page.
stack.sql(
    "INSERT INTO log (id, message, level, context, created_at) SELECT gen_random_uuid(), 'Paging test ' || n, 'debug',"
    " '{}', extract(epoch FROM now()) - n FROM generate_series(1, 30) AS n"
)

total = int(stack.sql('SELECT count(*) FROM log'))
page = web.get('/admin/logs')
suite.check('the logs show 50 to a page', page.count('class="clickable-row"') == min(50, total), total)
page = web.get('/admin/logs?per_page=25&page=2')
shown = page.count('class="clickable-row"')
is_paged = shown == min(25, total - 25) and f'of\n            {total:,}' in page
suite.check('or as many as chosen, a page at a time', is_paged, (shown, total))
suite.check('with links to the other pages', 'rel="prev"' in page and 'aria-current="page">2</span>' in page)
is_relative = re.search(r'<td>(\d+(\.\d)? \w+ ago|just now)<br>', page) is not None
suite.check('each entry\'s time is how long ago it was', is_relative, page[page.find('<tbody>'):][:300])
page = web.get('/admin/logs?level=warning')
levels = set(re.findall(r'class="badge badge-(\w+)"', page))
suite.check('the logs are filtered by level', levels == {'warning'}, levels)
page = web.get('/admin/logs?sort=severe&per_page=25')
first_level = re.search(r'class="badge badge-(\w+)"', page).group(1)
most_severe = stack.sql("SELECT max(level)::text FROM log")
suite.check('and sorted by level, the most severe first', first_level == most_severe, (first_level, most_severe))
suite.check('which the level\'s heading switches', 'href="/admin/logs?sort=mild&amp;per_page=25"' in page
            and 'aria-sort="descending"' in page)
page = web.get('/admin/logs?sort=oldest')
oldest = stack.sql('SELECT message FROM log ORDER BY created_at, id LIMIT 1')
first_link = page.find('class="row-link"')
suite.check('and by time, the oldest first', -1 < first_link < page.find(html.escape(oldest, quote=False)))

# Each entry has a category, kept in its context, which the logs are filtered by.
page = web.get('/admin/logs?category=admin')
messages = re.findall(r'class="row-link" href="[^"]+">([^<]+)</a>', page)
is_admin_only = len(messages) > 0 and 'API key created' in messages and 'Web login' not in messages
suite.check('the logs are filtered by category, e.g. only changes to the service', is_admin_only, messages[:10])
suite.check('which each entry shows', '<td class="hint">Changes to the service</td>' in page)
recorded = stack.sql("SELECT context->>'category' FROM log WHERE message = 'Web login' LIMIT 1")
suite.check('a login is logged as access', recorded == 'access', recorded)
page = web.get('/admin/logs?per_page=25&level=info')
suite.check('the page size is chosen below the logs, keeping the filter', 'id="page-size-form"' in page
            and '<input type="hidden" name="level" value="info">' in page and 'name="per_page" data-role' in page)

# Filtering by a name in the context, and a value it contains.
page = web.get('/admin/logs?context_name=api_key_id&per_page=500')
messages = set(re.findall(r'class="row-link" href="[^"]+">([^<]+)</a>', page))
suite.check('the logs are filtered by a name in their context', 'API key created' in messages
            and 'Web login' not in messages, messages)
admin_id = stack.sql(f"SELECT id FROM admin_user WHERE email = '{stack.ADMIN_EMAIL}'")
part = admin_id[:8].upper()
page = web.get(f'/admin/logs?context_name=user_id&context_value={part}&per_page=500')
shown = page.count('class="clickable-row"')
expected = int(stack.sql(f"SELECT count(*) FROM log WHERE context->>'user_id' ILIKE '%{part}%'"))
suite.check('and a value it contains, ignoring case', shown == min(expected, 500) and shown > 0, (shown, expected))
response = web.request('GET', '/admin/logs?context_value=x')
is_refused = response.get_status() == 400 and 'Give the name' in response.get_body()
suite.check('a value without a name is refused', is_refused)
response = web.request('GET', '/admin/logs?context_name=bad%20name')
suite.check('as is a name that can\'t be one', response.get_status() == 400)

# Each entry is a link to its page, with its context, rather than the context being in the list.
entry_id = stack.sql("SELECT id FROM log WHERE message = 'Web login' ORDER BY created_at DESC LIMIT 1")
page = web.get('/admin/logs')
suite.check('each entry links to its own page', f'class="row-link" href="/admin/logs/{entry_id}"' in page
            and '<summary>Context</summary>' not in page)
page = web.get(f'/admin/logs/{entry_id}')
suite.check('which shows its context', 'Web login' in page and '&quot;client_ip&quot;' in page)
suite.check('each value linking to the logs with it', '/admin/logs?context_name=user_id&amp;context_value=' in page)
suite.check('an account is named by its ID, linking to it', f'href="/admin/users/{admin_id}">{stack.ADMIN_EMAIL}</a>'
            in page and '&quot;email&quot;' not in page)
emails_logged = stack.sql("SELECT count(*) FROM log WHERE context ? 'email' OR context ? 'by'")
suite.check('no log entry names an account by its email address', emails_logged == '0', emails_logged)
suite.check('an unknown entry is a 404', web.request('GET', '/admin/logs/nonsense').get_status() == 404)

# Accounts, through the management API.
form = {'email': 'api-user@example.org', 'password_source': 'set', 'password': 'api-users-password',
        'confirm_password': 'api-users-password'}
web.post_form('/admin/users', '/admin/users', form)
user_id = stack.sql("SELECT id FROM admin_user WHERE email = 'api-user@example.org'")
listed = call_api('GET', '/users', auth).get_json()['users']
suite.check('accounts are listed', user_id in [user['id'] for user in listed] and all(user['status'] != 'archived'
                                                                                    for user in listed), listed)
response = call_api('POST', f'/users/{user_id}/block', auth)
suite.check('an account is blocked', response.get_status() == 200 and response.get_json()['user']['status']
            == 'blocked', response.get_body())
response = call_api('POST', f'/users/{user_id}/unblock', auth)
suite.check('and unblocked', response.get_json()['user']['status'] == 'active', response.get_body())
response = call_api('DELETE', f'/users/{user_id}', auth)
is_refused = response.get_status() == 409 and response.get_error_code() == 40
suite.check('a live account can\'t be deleted (409, archive it first)', is_refused, response.get_body())
response = call_api('POST', f'/users/{user_id}/archive', auth)
archived = response.get_json()['user'] if response.get_status() == 200 else {}
suite.check('it\'s archived, without its email address', archived.get('status') == 'archived'
            and archived.get('email') is None, response.get_body())
listed = [user['id'] for user in call_api('GET', '/users', auth).get_json()['users']]
suite.check('archived accounts are only listed when asked for', user_id not in listed and user_id in
            [user['id'] for user in call_api('GET', '/users?archived=true', auth).get_json()['users']])
response = call_api('POST', f'/users/{user_id}/unblock', auth)
suite.check('an archived account can\'t be unblocked', response.get_status() == 409 and response.get_error_code() == 41)
response = call_api('DELETE', f'/users/{user_id}', auth)
suite.check('once archived, it\'s deleted', response.get_status() == 204, response.get_status())
response = call_api('GET', f'/users/{user_id}', auth)
suite.check('and gone', response.get_status() == 404, response.get_status())
response = call_api('POST', f'/users/{admin_id}/block', auth)
is_refused = response.get_status() == 409 and response.get_error_code() == 42
suite.check('the last account that can log in can\'t be blocked', is_refused, response.get_body())

# Logging in again, with the second factor.
web.request('POST', '/logout', {'csrf_token': find_csrf_token(web.get('/'))})
form = {'csrf_token': find_csrf_token(web.get('/login')), 'email': stack.ADMIN_EMAIL, 'password': stack.ADMIN_PASSWORD}
response = web.request('POST', '/login', form)
suite.check('password step goes to second factor', response.get_header('Location') == '/login/second-factor')
suite.check('not logged in before second factor', web.request('GET', '/').get_status() == 303)
form = {'csrf_token': find_csrf_token(web.get('/login/second-factor')), 'code': used_code}
suite.check('replayed TOTP code refused', web.request('POST', '/login/second-factor/totp', form).get_status() == 401)
# This waits for the next 30 second step, as the code from setting it up can't be used again.
code = create_unused_totp_code(stack.ADMIN_EMAIL, secret)
form = {'csrf_token': find_csrf_token(web.get('/login/second-factor')), 'code': code}
response = web.request('POST', '/login/second-factor/totp', form)
suite.check('fresh TOTP code logs in', response.get_header('Location') == '/', response)
suite.check('logged in after TOTP', web.request('GET', '/').get_status() == 200)

# Revoking the email token.
response = call_api('DELETE', f'/email-tokens/{key_id}', auth)
revoked = response.get_json()
is_revoked = revoked['cancelledQueuedEmails'] >= 1 and revoked['emailToken']['revokedAt'] is not None
suite.check('revoke cancels queued emails', response.get_status() == 200 and is_revoked, response.get_body())

try:
    smtp = smtplib.SMTP_SSL('localhost', stack.SMTPS_PORT, timeout=20, context=create_tls_context())
    smtp.login(key_id, key_token)
    suite.check('revoked email token refused', False, 'it was accepted')
except smtplib.SMTPAuthenticationError as error:
    is_refused = error.smtp_code == 535 and b'revoked' in error.smtp_error
    suite.check('revoked email token refused', is_refused, error.smtp_error)

response = call_api('POST', '/send', key_auth, email)
is_refused = response.get_status() == 401 and response.get_error_code() == 12
suite.check('and refused by the send endpoint', is_refused, (response.get_status(), response.get_body()))

suite.finish()
