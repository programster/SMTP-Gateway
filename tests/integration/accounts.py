"""
Web interface accounts: adding them (identified by email address, in any case), resetting an account's second factor
from the command line, an account's own page (with its last login, and changing its email address), emailing a new
account a link to choose its own password, making one given a password change it when it first logs in, backup codes
(given with the first second factor, logging in with one once, and generating new ones), and deleting them.
"""

import html
import re
import time

from lib import stack
from lib.harness import Suite
from lib.web import (Response, WebClient, create_unused_totp_code, find_csrf_token, forget_used_totp_code,
                     load_totp_secrets)

suite = Suite('Accounts')
admin = WebClient.create_logged_in()
password = 'Bobs-long-password-1'
account = {'password_source': 'set', 'password': password, 'confirm_password': password}


def has_totp(email: str) -> bool:
    """Whether an account has an authenticator app set up."""
    return stack.sql(f"SELECT totp_secret IS NOT NULL FROM admin_user WHERE email = '{email}'") == 't'


response = admin.post_form('/admin/users', '/admin/users', account | {'email': 'Bob@Example.org'})
suite.check('account added', response.get_status() == 303, response.get_status())
stored = stack.sql("SELECT email FROM admin_user WHERE email LIKE 'bob@%'")
suite.check('email address stored in lower case', stored == 'bob@example.org', stored)
response = admin.post_form('/admin/users', '/admin/users', account | {'email': 'bob@example.ORG'})
is_refused = response.get_status() == 400 and 'already an account' in response.get_body()
suite.check('same address in another case refused', is_refused, response.get_status())
response = admin.post_form('/admin/users', '/admin/users', account | {'email': 'not-an-email'})
is_refused = response.get_status() == 400 and 'valid email' in response.get_body()
suite.check('invalid email address refused', is_refused, response.get_status())

bob = WebClient()
bob.log_in('BOB@example.org', password)
suite.check('new account logs in, in any case', bob.request('GET', '/').get_status() == 200)
suite.check('it had to set up a second factor', has_totp('bob@example.org'))

result = stack.run_script('reset-mfa.php', 'Bob@Example.org')
suite.check('reset-mfa.php removes it', result.returncode == 0 and not has_totp('bob@example.org'), result.stdout)
result = stack.run_script('reset-mfa.php', 'nobody@example.org')
suite.check('reset-mfa.php fails for an unknown account', result.returncode != 0, result.stdout)

bob_id = stack.sql("SELECT id FROM admin_user WHERE email = 'bob@example.org'")

# An account whose owner chooses their own password, with an emailed link.
page = admin.get('/admin/users')
suite.check('the form offers emailing a link, chosen to begin with', 'value="link" data-role="password-source" checked'
            in page and 'name="must_change_password" value="1" checked' in page)
response = admin.post_form('/admin/users', '/admin/users', {'email': 'carol@example.org', 'password_source': 'link'})
suite.check('an account is created, with a link emailed to it', response.get_status() == 303, response.get_status())
found = {'messages': []}
deadline = time.time() + 15

while len(found['messages']) == 0 and time.time() < deadline:
    found = stack.search_mailpit('to:carol@example.org subject:"Choose your password"')
    time.sleep(0.5)

text = stack.get_mailpit_text(found['messages'][0]['ID']) if found['messages'] else ''
link = re.search(r'/reset-password/[0-9a-f]{64}', text)
suite.check('which says it works for 72 hours', link is not None and '72 hours' in text, text[:300])
carol = WebClient()
carol_password = 'Carols-own-password-2'
form = {'new_password': carol_password, 'confirm_password': carol_password}
response = carol.post_form(link.group(0), link.group(0), form) if link else None
suite.check('the link sets the password they chose', response is not None and response.get_status() == 303)
carol.log_in('carol@example.org', carol_password)
suite.check('and they can log in with it', carol.request('GET', '/').get_status() == 200)

# An account given a password, which has to be changed when its owner first logs in.
form = account | {'email': 'dave@example.org', 'must_change_password': '1'}
response = admin.post_form('/admin/users', '/admin/users', form)
must_change = stack.sql("SELECT must_change_password FROM admin_user WHERE email = 'dave@example.org'")
suite.check('an account is created that must change its password', response.get_status() == 303 and must_change == 't')
dave = WebClient()
dave.request('POST', '/login', {'csrf_token': find_csrf_token(dave.get('/login')), 'email': 'dave@example.org',
                                'password': password})
suite.check('when it logs in, it\'s sent to My account', dave.request('GET', '/email-tokens').get_header('Location')
            == '/account')
suite.check('which says to choose a password of its own', 'set by an administrator' in dave.get('/account'))
page = dave.get('/account')
dave_password = 'Daves-own-password-3'
form = {'current_password': password, 'new_password': dave_password, 'confirm_password': dave_password}
response = dave.post_form('/account', '/account/password', form)
must_change = stack.sql("SELECT must_change_password FROM admin_user WHERE email = 'dave@example.org'")
suite.check('changing it lets it carry on', response.get_status() == 303 and must_change == 'f',
            (response.get_status(), must_change, response.get_header('Location'), response.get_body()[:400]))
suite.check('to setting up its second factor', 'set by an administrator' not in dave.get('/account'))

# An account's own page, where its email address can be changed.
page = admin.get(f'/admin/users/{bob_id}')
has_login = 'Last login' in page and re.search(r'from\s+<span', page) is not None
suite.check('an account has a page', 'bob@example.org' in page and has_login)
suite.check('the users page links to it', f'href="/admin/users/{bob_id}"' in admin.get('/admin/users'))
response = admin.post_form(f'/admin/users/{bob_id}', f'/admin/users/{bob_id}/email', {'email': 'Robert@Example.org'})
changed = stack.sql(f"SELECT email FROM admin_user WHERE id = '{bob_id}'")
suite.check('its email address is changed', response.get_status() == 303 and changed == 'robert@example.org', changed)
response = admin.post_form(f'/admin/users/{bob_id}', f'/admin/users/{bob_id}/email', {'email': stack.ADMIN_EMAIL})
is_refused = response.get_status() == 400 and 'already an account' in response.get_body()
suite.check('but not to another account\'s', is_refused, response.get_status())
admin_id = stack.sql(f"SELECT id FROM admin_user WHERE email = '{stack.ADMIN_EMAIL}'")
page = admin.get(f'/admin/users/{admin_id}')
suite.check('an account can\'t delete itself from its page', '/delete"' not in page and 'My account' in page)
suite.check('an unknown account is a 404', admin.request('GET', '/admin/users/nonsense').get_status() == 404)

# Blocking: the account can't log in, and is logged out, until it's unblocked. It stays in the list.
page = admin.get('/admin/users')
suite.check('a live account\'s row has block and archive buttons', f'/admin/users/{bob_id}/block"' in page
            and f'data-action="/admin/users/{bob_id}/archive"' in page)
is_missing = f'/admin/users/{bob_id}/delete' not in page
suite.check('but no delete button, as only archived accounts can be deleted', is_missing)
response = admin.post_form('/admin/users', f'/admin/users/{bob_id}/block', {})
status = stack.sql(f"SELECT status FROM admin_user WHERE id = '{bob_id}'")
suite.check('an account is blocked', response.get_status() == 303 and status == 'blocked', status)
suite.check('which logs it out', bob.request('GET', '/').get_status() == 303)
page = admin.get('/admin/users')
suite.check('it stays in the list, marked blocked, with an unblock button', 'badge-blocked' in page
            and f'/admin/users/{bob_id}/unblock"' in page)
blocked = WebClient()
form = {'csrf_token': find_csrf_token(blocked.get('/login')), 'email': 'robert@example.org', 'password': password}
response = blocked.request('POST', '/login', form)
suite.check('it can\'t log in, and is told why', response.get_status() == 403 and 'has been blocked'
            in response.get_body(), response.get_status())
response = admin.post_form('/admin/users', f'/admin/users/{bob_id}/unblock', {})
status = stack.sql(f"SELECT status FROM admin_user WHERE id = '{bob_id}'")
suite.check('it\'s unblocked', response.get_status() == 303 and status == 'active', status)
bob = WebClient()
bob.log_in('robert@example.org', password)
suite.check('and can log in again', bob.request('GET', '/').get_status() == 200)
response = admin.post_form('/admin/users', f'/admin/users/{admin_id}/block', {})
suite.check('nobody can block themselves', response.get_status() == 400 and 'your own account' in response.get_body())

# Archiving: needs your password. The account is blocked, and its email address and sign-in details removed.
response = admin.post_form('/admin/users', f'/admin/users/{bob_id}/archive', {'current_password': 'wrong'})
suite.check('archiving with the wrong password is refused', response.get_status() == 400 and 'password is wrong'
            in response.get_body(), response.get_status())
right_password = {'current_password': stack.ADMIN_PASSWORD}
response = admin.post_form('/admin/users', f'/admin/users/{bob_id}/archive', right_password)
row = stack.sql(f"SELECT status, email IS NULL, totp_secret IS NULL FROM admin_user WHERE id = '{bob_id}'")
suite.check('an account is archived, without its email address or sign-ins', response.get_status() == 303
            and row == 'archived|t|t', row)
suite.check('which logs it out', bob.request('GET', '/').get_status() == 303)
suite.check('it\'s gone from the list', bob_id not in admin.get('/admin/users'))
page = admin.get('/admin/users?archived=1')
suite.check('until archived accounts are shown', f'/admin/users/{bob_id}/restore' in page
            and f'data-action="/admin/users/{bob_id}/delete"' in page)
page = admin.get(f'/admin/users/{bob_id}')
suite.check('its page says it\'s archived', 'Archived account' in page and 'Restore user' in page)
entry_id = stack.sql(f"SELECT id FROM log WHERE context->>'user_id' = '{bob_id}' ORDER BY created_at LIMIT 1")
page = admin.get(f'/admin/logs/{entry_id}')
suite.check('its log entries still link to it', f'href="/admin/users/{bob_id}">Archived account</a>' in page)
emails = stack.sql("SELECT count(*) FROM email_message WHERE recipients::text ILIKE '%robert@example.org%'")
suite.check('the service\'s emails to it no longer hold its address', emails == '0', emails)

# Restoring it, with its email address again: it keeps its ID.
restore_path = f'/admin/users/{bob_id}/restore'
form = account | {'email': 'bob@example.org'}
response = admin.post_form(restore_path, restore_path, form)
row = stack.sql(f"SELECT status, email FROM admin_user WHERE id = '{bob_id}'")
suite.check('an archived account is restored, keeping its ID', response.get_status() == 303
            and row == 'active|bob@example.org', row)
bob = WebClient()
bob.log_in('bob@example.org', password)
suite.check('and can log in with its new password', bob.request('GET', '/').get_status() == 200)

# Deleting: only an archived account, with your password.
response = admin.post_form('/admin/users', f'/admin/users/{bob_id}/delete', right_password)
is_refused = response.get_status() == 409 and 'Archive it first' in response.get_body()
suite.check('a live account can\'t be deleted', is_refused, response.get_status())
admin.post_form('/admin/users', f'/admin/users/{bob_id}/archive', right_password)
response = admin.post_form('/admin/users?archived=1', f'/admin/users/{bob_id}/delete', {'current_password': 'wrong'})
suite.check('deleting with the wrong password is refused', response.get_status() == 400)
response = admin.post_form('/admin/users?archived=1', f'/admin/users/{bob_id}/delete', right_password)
is_deleted = stack.sql(f"SELECT count(*) FROM admin_user WHERE id = '{bob_id}'") == '0'
suite.check('an archived account is deleted', response.get_status() == 303 and is_deleted, response.get_status())
page = admin.get(f'/admin/logs/{entry_id}')
suite.check('its log entries then say it was deleted', 'Deleted account' in page)

# Removing a security key: its row has a dustbin button, which opens a dialog that asks for the password. WebAuthn
# can't be used here, so the key is added straight to the database, as removing it doesn't need the key itself.
admin_id = stack.sql(f"SELECT id FROM admin_user WHERE email = '{stack.ADMIN_EMAIL}'")

stack.sql(
    "INSERT INTO admin_webauthn_credential (id, admin_user_id, name, credential_id, public_key_pem)"
    f" VALUES (gen_random_uuid(), '{admin_id}', 'Blue YubiKey', 'test-credential', 'test-key')"
)

key_id = stack.sql("SELECT id FROM admin_webauthn_credential WHERE credential_id = 'test-credential'")
page = admin.get('/account')
remove_path = f'/account/security-keys/{key_id}/remove'
has_button = f'data-action="{remove_path}"' in page and 'aria-label="Remove Blue YubiKey"' in page
suite.check('a security key has a dustbin button to remove it', has_button and 'class="icon"' in page)
suite.check('which opens a dialog that asks for the password', 'id="remove-security-key-dialog"' in page)
keys_table = page.split('<table>', 1)[-1].split('</table>', 1)[0]
suite.check('and the key\'s row has no password field of its own', 'current_password' not in keys_table)


def has_key() -> bool:
    return stack.sql(f"SELECT count(*) FROM admin_webauthn_credential WHERE id = '{key_id}'") == '1'


response = admin.post_form('/account', remove_path, {'current_password': 'not-the-password'})
is_refused = response.get_status() == 400 and has_key()
suite.check('removing a security key with the wrong password is refused', is_refused, response.get_status())
response = admin.post_form('/account', remove_path, {'current_password': stack.ADMIN_PASSWORD})
is_removed = response.get_status() == 303 and not has_key()
suite.check('and with the right password, it\'s removed', is_removed, response.get_status())
has_dialog = 'remove-security-key-dialog' in admin.get('/account')
suite.check('which leaves no dialog, as there\'s nothing to remove', has_dialog is False)

# Backup codes: an account is given six when it sets up its first second factor, shown once. Each logs in once instead
# of the second factor, and generating new ones (with the password) makes the old ones stop working.
erin_email = 'erin@example.org'
admin.post_form('/admin/users', '/admin/users', account | {'email': erin_email})
erin_id = stack.sql(f"SELECT id FROM admin_user WHERE email = '{erin_email}'")
erin = WebClient()
erin.log_in(erin_email, password)
code_pattern = r'<li><code>([a-z2-9]{5}-[a-z2-9]{5})</code></li>'
response = erin.request('GET', '/account/backup-codes')
codes = re.findall(code_pattern, response.get_body())
suite.check('setting up a second factor gives six backup codes', response.get_status() == 200 and len(codes) == 6,
            (response.get_status(), codes))
suite.check('which can be printed, downloaded or copied', all(f'data-role="{role}"' in response.get_body()
                                                              for role in ['print', 'download', 'copy']))
hashes = stack.sql(f"SELECT code_hash FROM admin_backup_code WHERE admin_user_id = '{erin_id}'").splitlines()
is_hashed = len(hashes) == 6 and all(h.startswith('$2y$') for h in hashes) and not any(
    code.replace('-', '') in '\n'.join(hashes) for code in codes)
suite.check('only their hashes are stored', is_hashed, hashes)
response = erin.request('GET', '/account/backup-codes')
suite.check('they\'re only shown once', response.get_header('Location') == '/account', response.get_status())
suite.check('the account page says how many are left', '6 of 6 unused' in erin.get('/account'))


def start_login(client: WebClient) -> Response:
    """Log out, and give the password, getting to the second factor step."""
    client.request('POST', '/logout', {'csrf_token': find_csrf_token(client.get('/'))})
    form = {'csrf_token': find_csrf_token(client.get('/login')), 'email': erin_email, 'password': password}
    return client.request('POST', '/login', form)


def give_backup_code(client: WebClient, code: str) -> Response:
    """Give a backup code at the second factor step."""
    form = {'csrf_token': find_csrf_token(client.get('/login/second-factor')), 'backup_code': code}
    return client.request('POST', '/login/second-factor/backup-code', form)


start_login(erin)
suite.check('the second factor step offers a backup code', 'Use a backup code' in erin.get('/login/second-factor'))
response = give_backup_code(erin, codes[0].upper().replace('-', ' '))
suite.check('a backup code logs in, however it\'s typed', response.get_header('Location') == '/', response.get_status())
page = erin.get('/')
suite.check('which says how many are left', 'You have 5 backup codes left' in page)
suite.check('and the account page counts it as used', '5 of 6 unused' in erin.get('/account'))
logged = stack.sql("SELECT count(*) FROM log WHERE message = 'Backup code used to log in'")
suite.check('using one is logged', logged not in ('', '0'), logged)

start_login(erin)
response = give_backup_code(erin, codes[0])
is_refused = response.get_status() == 401 and 'class="backup-code-login" open' in response.get_body()
suite.check('a used backup code is refused, with the form left open', is_refused, response.get_status())
suite.check('as is one that was never given', give_backup_code(erin, 'abcde-fghjk').get_status() == 401)
response = give_backup_code(erin, codes[1])
suite.check('but another of them still works', response.get_header('Location') == '/', response.get_status())

form = {'current_password': 'not-the-password'}
response = erin.post_form('/account', '/account/backup-codes', form)
suite.check('new codes need the password', response.get_status() == 400, response.get_status())
response = erin.post_form('/account', '/account/backup-codes', {'current_password': password})
suite.check('with it, new ones are generated', response.get_header('Location') == '/account/backup-codes')
new_codes = re.findall(code_pattern, erin.get('/account/backup-codes'))
suite.check('six new ones', len(new_codes) == 6 and not set(new_codes) & set(codes), new_codes)
start_login(erin)
suite.check('which replace the old ones', give_backup_code(erin, codes[2]).get_status() == 401)
response = give_backup_code(erin, new_codes[0])
suite.check('and the new ones work', response.get_header('Location') == '/', response.get_status())

# Logging in with the last backup code gives a new set straight away, shown once.
for code in new_codes[1:5]:
    start_login(erin)
    give_backup_code(erin, code)

start_login(erin)
response = give_backup_code(erin, new_codes[5])
suite.check('the last backup code logs in, to new codes', response.get_header('Location') == '/account/backup-codes',
            response.get_header('Location'))
page = erin.get('/account/backup-codes')
latest_codes = re.findall(code_pattern, page)
suite.check('six of them, saying why', len(latest_codes) == 6 and 'your last backup code' in page, latest_codes)

# So does logging in any other way with none left, e.g. an account that had none before backup codes existed.
stack.sql(f"UPDATE admin_backup_code SET used_at = unix_time() WHERE admin_user_id = '{erin_id}'")
start_login(erin)
forget_used_totp_code(erin_email)
totp_form = {'csrf_token': find_csrf_token(erin.get('/login/second-factor')),
             'code': create_unused_totp_code(erin_email, load_totp_secrets()[erin_email])}
response = erin.request('POST', '/login/second-factor/totp', totp_form)
suite.check('logging in with none left is sent to new ones', response.get_header('Location') == '/account/backup-codes',
            response.get_header('Location'))
suite.check('which are shown', len(re.findall(code_pattern, erin.get('/account/backup-codes'))) == 6)
start_login(erin)
forget_used_totp_code(erin_email)
totp_form = {'csrf_token': find_csrf_token(erin.get('/login/second-factor')),
             'code': create_unused_totp_code(erin_email, load_totp_secrets()[erin_email])}
response = erin.request('POST', '/login/second-factor/totp', totp_form)
suite.check('but not when some are left', response.get_header('Location') == '/', response.get_header('Location'))

result = stack.run_script('reset-mfa.php', erin_email)
remaining = stack.sql(f"SELECT count(*) FROM admin_backup_code WHERE admin_user_id = '{erin_id}'")
suite.check('reset-mfa.php removes the backup codes too', result.returncode == 0 and remaining == '0', remaining)


# A grace period, for an account that has lost its second factor.
def give_second_factor() -> None:
    """Give the account a second factor and a backup code again, directly."""
    stack.sql(f"UPDATE admin_user SET totp_secret = 'grace-test' WHERE id = '{erin_id}'")
    stack.sql(f"DELETE FROM admin_backup_code WHERE admin_user_id = '{erin_id}'")
    stack.sql("INSERT INTO admin_backup_code (id, admin_user_id, code_hash) "
              f"VALUES (gen_random_uuid(), '{erin_id}', 'grace-test')")


def grace_until() -> str:
    """Get when the account's grace period ends, or an empty string if it has none."""
    return stack.sql(f"SELECT mfa_grace_until FROM admin_user WHERE id = '{erin_id}'")


def has_second_factor() -> bool:
    """Check whether the account still has its authenticator app."""
    return stack.sql(f"SELECT totp_secret IS NOT NULL FROM admin_user WHERE id = '{erin_id}'") == 't'


def give_password(given: str = password) -> Response:
    """Give a password on the login page, in a new browser session."""
    client = WebClient()
    form = {'csrf_token': find_csrf_token(client.get('/login')), 'email': erin_email, 'password': given}
    return client.request('POST', '/login', form)


erin_path = f'/admin/users/{erin_id}'
grace_path = f'{erin_path}/mfa-grace'
right_password = {'current_password': stack.ADMIN_PASSWORD}
give_second_factor()
page = admin.get(erin_path)
suite.check('an account with a second factor offers a grace period', f'data-action="{grace_path}"' in page)
suite.check('and the command to remove them', 'reset-mfa.php' in page)
suite.check('and a button to email a reset link, instead of a command', f'action="{erin_path}/password-reset"' in page
            and 'reset-password.php' not in page)
suite.check('without a password login skipping it first', give_password().get_header('Location')
            == '/login/second-factor')
response = admin.post_form(erin_path, grace_path, {'current_password': 'wrong'})
suite.check('a grace period needs your password', response.get_status() == 400 and grace_until() == '')
admin_id = stack.sql(f"SELECT id FROM admin_user WHERE email = '{stack.ADMIN_EMAIL.lower()}'")
own_path = f'/admin/users/{admin_id}'
response = admin.post_form(own_path, f'{own_path}/mfa-grace', right_password)
suite.check('nobody can give themselves one', response.get_status() == 400, response.get_status())

response = admin.post_form(erin_path, grace_path, right_password)
suite.check('with it, the account has one', response.get_status() == 303 and grace_until() != '', grace_until())
suite.check('which its page says', 'A grace period is active' in admin.get(erin_path))
admin.post_form(erin_path, f'{grace_path}/cancel', {})
suite.check('and it can be cancelled', grace_until() == '', grace_until())
suite.check('after which the second factor is needed again', give_password().get_header('Location')
            == '/login/second-factor')

admin.post_form(erin_path, grace_path, right_password)
stack.sql(f"UPDATE admin_user SET mfa_grace_until = unix_time() - 60 WHERE id = '{erin_id}'")
suite.check('an expired one does nothing', give_password().get_header('Location') == '/login/second-factor'
            and has_second_factor())

admin.post_form(erin_path, grace_path, right_password)
response = give_password('not-the-password')
suite.check('a wrong password doesn\'t use it', response.get_status() == 401 and grace_until() != ''
            and has_second_factor(), response.get_status())

admin.post_form(erin_path, f'{erin_path}/block', {})
suite.check('blocking cancels it', grace_until() == '', grace_until())
admin.post_form(erin_path, f'{erin_path}/unblock', {})

admin.post_form(erin_path, grace_path, right_password)
response = give_password()
codes_left = stack.sql(f"SELECT count(*) FROM admin_backup_code WHERE admin_user_id = '{erin_id}'")
suite.check('the password alone logs in, to set up a new one', response.get_header('Location') == '/account',
            response.get_header('Location'))
suite.check('having removed the old second factors', not has_second_factor() and codes_left == '0', codes_left)
suite.check('and used it up', grace_until() == '', grace_until())
logged = stack.sql("SELECT count(*) FROM log WHERE message = 'A grace period replaced the second factors'")
suite.check('which is logged', logged == '1', logged)
give_second_factor()
suite.check('so it only works once', give_password().get_header('Location') == '/login/second-factor')

admin.post_form(erin_path, grace_path, right_password)
result = stack.run_script('reset-password.php', erin_email)
suite.check('changing the password cancels it', result.returncode == 0 and grace_until() == '', grace_until())

# An administrator emailing an account a link to choose a new password.
reset_path = f'{erin_path}/password-reset'
stack.sql("UPDATE password_reset_token SET created_at = unix_time() - 600 "
          f"WHERE admin_user_id = '{erin_id}'")
admin.post_form(erin_path, f'{erin_path}/block', {})
response = admin.post_form(erin_path, reset_path, {})
suite.check('a blocked account can\'t be sent a reset link', response.get_status() == 400, response.get_status())
admin.post_form(erin_path, f'{erin_path}/unblock', {})
response = admin.post_form(erin_path, reset_path, {})
suite.check('an active one can', response.get_status() == 303, response.get_status())
found = {'messages': []}
deadline = time.time() + 15

while len(found['messages']) == 0 and time.time() < deadline:
    found = stack.search_mailpit(f'to:{erin_email} subject:"Reset your password"')
    time.sleep(0.5)

text = stack.get_mailpit_text(found['messages'][0]['ID']) if found['messages'] else ''
suite.check('which says an administrator sent it', 'An administrator' in text
            and re.search(r'/reset-password/[0-9a-f]{64}', text) is not None, text[:200])
response = admin.post_form(erin_path, reset_path, {})
is_refused = response.get_status() == 400 and 'last few minutes' in response.get_body()
suite.check('but not twice in a few minutes', is_refused, response.get_status())
response = admin.post_form(own_path, f'{own_path}/password-reset', {})
suite.check('nor to your own account from here', response.get_status() == 400, response.get_status())

# An administrator setting a random password, which the account has to change when it next logs in.
stack.sql(f"UPDATE admin_user SET totp_secret = NULL WHERE id = '{erin_id}'")
stack.sql(f"DELETE FROM admin_backup_code WHERE admin_user_id = '{erin_id}'")
temporary_path = f'{erin_path}/temporary-password'
old_hash = stack.sql(f"SELECT password_hash FROM admin_user WHERE id = '{erin_id}'")
response = admin.post_form(erin_path, temporary_path, {})
shown = re.search(r'id="temporary-password" value="([^"]+)"', response.get_body())
suite.check('an admin can set a random password, shown once', response.get_status() == 200 and shown is not None,
            response.get_status())
temporary = html.unescape(shown.group(1)) if shown else ''
row = stack.sql(f"SELECT must_change_password, password_hash <> '{old_hash}' FROM admin_user WHERE id = '{erin_id}'")
suite.check('which replaces the password, and must be changed', row == 't|t', row)
suite.check('and isn\'t logged', stack.sql(f"SELECT count(*) FROM log WHERE message LIKE '%{temporary[:8]}%' "
                                            f"OR context::text LIKE '%{temporary[:8]}%'") == '0')
suite.check('it logs in', give_password(temporary).get_header('Location') == '/')
temporary_client = WebClient()
form = {'csrf_token': find_csrf_token(temporary_client.get('/login')), 'email': erin_email, 'password': temporary}
temporary_client.request('POST', '/login', form)
landing = temporary_client.request('GET', '/admin/users')
suite.check('but has to choose a new one first', landing.get_status() == 303
            and landing.get_header('Location') != '/admin/users', landing.get_header('Location'))
response = admin.post_form(own_path, f'{own_path}/temporary-password', {})
suite.check('nobody can set their own this way', response.get_status() == 400, response.get_status())
admin.post_form(erin_path, f'{erin_path}/block', {})
response = admin.post_form(erin_path, temporary_path, {})
suite.check('nor a blocked account\'s', response.get_status() == 400, response.get_status())

suite.finish()
