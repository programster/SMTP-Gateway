"""
The limit on failed logins per IP address (LoginThrottle): after 5 failures in 15 minutes, every login from the
address is refused, for any account, until the window's over. A burst of attempts sent at once can't get round it, a
correct password isn't counted (but doesn't clear the count either), and a completed login clears it. It's per address,
not per account, so failures from one address don't stop another logging in to the same account. In its own stack, as
it blocks the test client's address.
"""

import threading

from lib import stack
from lib.harness import Suite
from lib.web import WebClient, find_csrf_token

suite = Suite('Login throttle')
admin = WebClient.create_logged_in()


def try_password(password: str, email: str = stack.ADMIN_EMAIL) -> int:
    """Try to log in with a password, from a client of its own, and get the response's status."""
    client = WebClient()
    form = {'csrf_token': find_csrf_token(client.get('/login')), 'email': email, 'password': password}
    return client.request('POST', '/login', form).get_status()


def reset() -> None:
    """Forget every address's failures, as if their windows were over."""
    stack.sql('DELETE FROM "login_throttle"')


def get_failures() -> str:
    """Get the test client's address's count of failures, or an empty string if it has none."""
    return stack.sql('SELECT "failures" FROM "login_throttle"')


# Five failures are allowed, and then even the right password is refused, without being checked.
reset()
statuses = [try_password('wrong-password-1') for _ in range(5)]
suite.check('five failed logins are each refused as wrong', statuses == [401] * 5, statuses)
client = WebClient()
right = {'email': stack.ADMIN_EMAIL, 'password': stack.ADMIN_PASSWORD}
form = {'csrf_token': find_csrf_token(client.get('/login'))} | right
response = client.request('POST', '/login', form)
is_refused = (response.get_status() == 429
              and 'Too many failed attempts to log in from your address' in response.get_body())
suite.check('then the right password is refused too, saying why', is_refused, response.get_status())
suite.check('for any account, not just the one tried', try_password('Whatever-1!', 'someone@example.org') == 429)
suite.check('but an account that\'s already logged in carries on', admin.request('GET', '/').get_status() == 200)
logged = stack.sql("SELECT count(*) FROM \"log\" WHERE \"message\" = 'Logins refused after too many failures'")
suite.check('and the refusal is logged, once', logged == '1', logged)

# It's per address: another address's failures don't stop this one logging in, to any account.
reset()
stack.sql("INSERT INTO \"login_throttle\" VALUES ('198.51.100.7', 99, now())")
suite.check('another address\'s failures don\'t lock the account', try_password(stack.ADMIN_PASSWORD) == 303)

# Once the window's over, the address can try again.
reset()
statuses = [try_password('wrong-password-1') for _ in range(5)]
stack.sql('UPDATE "login_throttle" SET "window_started_at" = now() - interval \'16 minutes\'')
suite.check('once the window\'s over, the address can log in again', try_password(stack.ADMIN_PASSWORD) == 303)
suite.check('starting a new count', get_failures() == '0', get_failures())

# A burst of attempts sent at once can't all be checked before the count catches up.
reset()
burst = []


def try_in_burst() -> None:
    burst.append(try_password('wrong-password-1'))


threads = [threading.Thread(target=try_in_burst) for _ in range(15)]

for thread in threads:
    thread.start()

for thread in threads:
    thread.join()

suite.check('of 15 attempts at once, only 5 are checked', burst.count(401) == 5 and burst.count(429) == 10,
            sorted(burst))

# A correct password isn't counted, but doesn't clear the count, so the second factor can't be guessed endlessly.
reset()
statuses = [try_password('wrong-password-1') for _ in range(4)]
client = WebClient()
right = {'email': stack.ADMIN_EMAIL, 'password': stack.ADMIN_PASSWORD}
form = {'csrf_token': find_csrf_token(client.get('/login'))} | right
response = client.request('POST', '/login', form)
suite.check('a correct password isn\'t counted as a failure', response.get_status() == 303 and get_failures() == '4',
            (response.get_status(), get_failures()))
page = client.get('/login/second-factor')
totp_form = {'csrf_token': find_csrf_token(page), 'code': '000000'}
first = client.request('POST', '/login/second-factor/totp', totp_form).get_status()
second = client.request('POST', '/login/second-factor/totp', totp_form | {'code': '111111'}).get_status()
suite.check('but a wrong authenticator code is, after it', first == 401 and second == 429, (first, second))
backup_form = {'csrf_token': find_csrf_token(page), 'backup_code': 'abcde-fghjk'}
response = client.request('POST', '/login/second-factor/backup-code', backup_form)
suite.check('as are backup codes, which are refused too', response.get_status() == 429, response.get_status())

# A completed login clears the count.
reset()
statuses = [try_password('wrong-password-1') for _ in range(3)]
client = WebClient()
client.log_in(stack.ADMIN_EMAIL, stack.ADMIN_PASSWORD)
suite.check('a completed login clears the address\'s failures', client.request('GET', '/').get_status() == 200
            and get_failures() == '', get_failures())

suite.finish()
