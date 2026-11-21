"""
Logging in with a second factor: the password alone doesn't log in, a TOTP code can't be used twice, and a fresh one
logs in.
"""

from lib import stack
from lib.harness import Suite
from lib.web import WebClient, create_totp_code, create_unused_totp_code, find_csrf_token, load_totp_secrets

suite = Suite('Second factor')
email = stack.ADMIN_EMAIL.lower()
web = WebClient.create_logged_in()
secret = load_totp_secrets()[email]
used_step = int(stack.sql(f"SELECT totp_last_used_step FROM admin_user WHERE email = '{email}'"))
used_code = create_totp_code(secret, used_step * 30.0)

web.request('POST', '/logout', {'csrf_token': find_csrf_token(web.get('/'))})
form = {'csrf_token': find_csrf_token(web.get('/login')), 'email': stack.ADMIN_EMAIL, 'password': stack.ADMIN_PASSWORD}
response = web.request('POST', '/login', form)
suite.check('password step goes to second factor', response.get_header('Location') == '/login/second-factor')
suite.check('not logged in before second factor', web.request('GET', '/').get_status() == 303)
form = {'csrf_token': find_csrf_token(web.get('/login/second-factor')), 'code': used_code}
suite.check('replayed TOTP code refused', web.request('POST', '/login/second-factor/totp', form).get_status() == 401)
suite.check('still not logged in after the replay', web.request('GET', '/').get_status() == 303)
# The next unused code, which the service accepts straight away, so this doesn't wait for a new 30 second step.
code = create_unused_totp_code(stack.ADMIN_EMAIL, secret)
form = {'csrf_token': find_csrf_token(web.get('/login/second-factor')), 'code': code}
response = web.request('POST', '/login/second-factor/totp', form)
suite.check('fresh TOTP code logs in', response.get_header('Location') == '/', response)
suite.check('logged in after TOTP', web.request('GET', '/').get_status() == 200)

suite.finish()
