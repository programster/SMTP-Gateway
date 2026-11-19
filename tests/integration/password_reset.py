"""
Resetting a password by emailed link, the command line reset, and other sessions being logged out by a password
change.
"""

import re
import time

from lib import stack
from lib.harness import Suite
from lib.web import Response, WebClient, find_csrf_token, forget_used_totp_code

suite = Suite('Password reset')
new_password = 'A-brand-new-password-2'
stack.clear_mailpits()
# Only one reset email is sent per account every 5 minutes, so any earlier one (e.g. the priority suite's) is moved
# out of the way.
stack.sql("UPDATE password_reset_token SET created_at = now() - interval '10 minutes'")
# The log's IDs are UUIDv7s, which sort by time, so later entries are those after this one. On a fresh stack the log
# may still be empty, so then every entry counts, as every UUIDv7 sorts after the nil UUID.
log_start = stack.sql('SELECT id FROM log ORDER BY id DESC LIMIT 1') or '00000000-0000-0000-0000-000000000000'


def ask_for_reset(client: WebClient, email: str) -> tuple[Response, float]:
    """Ask for a reset link, and get the response and how long it took."""
    started = time.time()
    response = client.post_form('/forgot-password', '/forgot-password', {'email': email})
    return response, time.time() - started


def find_reset_path() -> str:
    """Get the path in the newest reset email Mailpit has caught."""
    found = stack.search_mailpit(f'to:{stack.ADMIN_EMAIL} subject:"Reset your password"')
    text = stack.get_mailpit_text(found['messages'][0]['ID'])
    return re.search(r'/reset-password/[0-9a-f]{64}', text).group(0)


def log_in(password: str = stack.ADMIN_PASSWORD) -> WebClient:
    """
    Log in as the admin, with the second factor, then let the next login use the same TOTP code, so this suite's
    logins don't each wait up to 30 seconds for a new one.
    """
    client = WebClient.create_logged_in(password=password)
    forget_used_totp_code(stack.ADMIN_EMAIL)
    return client


def try_logging_in(password: str) -> int:
    """Try the password step of logging in as the admin, and get the response's status."""
    client = WebClient()
    form = {'csrf_token': find_csrf_token(client.get('/login')), 'email': stack.ADMIN_EMAIL, 'password': password}
    return client.request('POST', '/login', form).get_status()


# A session that the reset should log out.
other = log_in()
suite.check('other session logged in', other.request('GET', '/').get_status() == 200)

anonymous = WebClient()
page = anonymous.get('/login')
suite.check('login page links to forgot password', 'href="/forgot-password"' in page and 'name="email"' in page)
response, known_time = ask_for_reset(anonymous, stack.ADMIN_EMAIL.upper())
suite.check('known address: says the link is on its way', 'on its way' in response.get_body(), response.get_status())
time.sleep(2)
found = stack.search_mailpit(f'to:{stack.ADMIN_EMAIL} subject:"Reset your password"')
suite.check('reset email arrives', found['messages_count'] == 1, found['messages_count'])
text = stack.get_mailpit_text(found['messages'][0]['ID'])
link = re.search(r'(' + re.escape(stack.WEB_URL) + r'/reset-password/([0-9a-f]{64}))', text)
suite.check('email has a link built from PUBLIC_URL', link is not None and 'from the IP address' in text, text[:300])
sender = found['messages'][0]['From']['Address']
suite.check('sent from SYSTEM_EMAIL_FROM', sender == stack.SYSTEM_EMAIL_FROM, sender)
response, unknown_time = ask_for_reset(anonymous, 'nobody@example.org')
suite.check('unknown address: same response', 'on its way' in response.get_body())
suite.check('response times similar (no enumeration)', abs(known_time - unknown_time) < 0.3, (known_time, unknown_time))
ask_for_reset(anonymous, stack.ADMIN_EMAIL)
time.sleep(2)
count = stack.search_mailpit(f'to:{stack.ADMIN_EMAIL}')['messages_count']
suite.check('a second request within 5 minutes sends nothing', count == 1, count)
since_start = f"id > '{log_start}'"
not_sent = stack.sql(f"SELECT count(*) FROM log WHERE {since_start} AND message LIKE 'Password reset not sent%'")
query = f"SELECT count(*) FROM log WHERE {since_start} AND message LIKE 'Password reset asked for an email%no account'"
no_account = stack.sql(query)
suite.check('both logged', not_sent == '1' and no_account == '1', (not_sent, no_account))
token_hash = f"encode(sha256('{link.group(2)}'::bytea), 'hex')"
stored = stack.sql(f'SELECT count(*) FROM password_reset_token WHERE token_hash = {token_hash}')
suite.check('only the token\'s hash is stored', stored == '1')

path = link.group(1).replace(stack.WEB_URL, '')
response = anonymous.request('GET', path)
is_shown = response.get_status() == 200 and stack.ADMIN_EMAIL in response.get_body()
suite.check('link shows the form for the account', is_shown, response.get_status())
suite.check('reset page sends no referrer', response.get_header('Referrer-Policy') == 'no-referrer')
form = {'new_password': new_password, 'confirm_password': 'different-password!'}
response = anonymous.post_form(path, path, form)
suite.check('mismatch refused', response.get_status() == 400 and 'match' in response.get_body())
response = anonymous.post_form(path, path, {'new_password': 'short', 'confirm_password': 'short'})
suite.check('short password refused', response.get_status() == 400 and 'at least 12' in response.get_body())
response = anonymous.post_form(path, path, {'new_password': new_password, 'confirm_password': new_password})
suite.check('reset redirects to login', response.get_header('Location') == '/login', response)
suite.check('says the password was changed', 'Your password was changed' in anonymous.get('/login'))
response = anonymous.request('GET', path)
is_refused = response.get_status() == 400 and 'valid any more' in response.get_body()
suite.check('link can\'t be reused', is_refused, response.get_status())
response = other.request('GET', '/')
suite.check('other session logged out', response.get_header('Location') == '/login', response)
suite.check('old password refused', try_logging_in(stack.ADMIN_PASSWORD) in (401, 403))
first = log_in(new_password)
suite.check('new password logs in (with the second factor)', first.request('GET', '/').get_status() == 200)

# Changing your own password keeps you logged in, but logs other sessions out.
second = log_in(new_password)
password = stack.ADMIN_PASSWORD
form = {'current_password': new_password, 'new_password': password, 'confirm_password': password}
response = first.post_form('/account', '/account/password', form)
is_kept = response.get_status() == 303 and first.request('GET', '/account').get_status() == 200
suite.check('changing your own password keeps this session', is_kept, response)
suite.check('and logs out the other', second.request('GET', '/').get_status() == 303)

# An expired link.
stack.clear_mailpits()
stack.sql("UPDATE password_reset_token SET created_at = now() - interval '10 minutes'")
ask_for_reset(anonymous, stack.ADMIN_EMAIL)
time.sleep(2)
expired_path = find_reset_path()
stack.sql("UPDATE password_reset_token SET expires_at = now() - interval '1 second' WHERE used_at IS NULL")
suite.check('expired link refused', anonymous.request('GET', expired_path).get_status() == 400)

# The command line reset.
result = stack.run_script('reset-password.php', 'Admin@Example.org')
match = re.search(r'is now: (\S+)', result.stdout)
suite.check('reset-password.php prints a new password', match is not None and len(match.group(1)) == 20, result.stdout)
client = log_in(match.group(1))
suite.check('which logs in', client.request('GET', '/').get_status() == 200)
result = stack.run_script('reset-password.php', 'nobody@example.org')
suite.check('reset-password.php fails for an unknown account', result.returncode == 1, result.returncode)

# Put the admin's password back, for the other suites.
email = stack.ADMIN_EMAIL
password = stack.ADMIN_PASSWORD
stack.run_php(f"AdminUserRepository::changePassword(AdminUserRepository::loadByEmail('{email}'), '{password}');")

suite.finish()
