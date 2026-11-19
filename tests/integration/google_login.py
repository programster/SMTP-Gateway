"""
Logging in with Google, as far as it can be tested without a real Google account: the redirect to Google, and every
way the callback can be refused. Google's side (a real ID token) is tested in tests/unit/GoogleSsoTest.php, against
programster/google-sso's fake Google.

The made-up code is sent to Google's real token endpoint, which refuses it. Without internet access, it can't be
reached instead, which is refused the same way.
"""

import urllib.parse

from lib import stack
from lib.harness import Suite
from lib.web import WebClient

suite = Suite('Google login')
browser = WebClient()


def start_login() -> str:
    """Start logging in with Google, and get the state it sends Google."""
    location = browser.request('GET', '/login/google').get_header('Location')
    return urllib.parse.parse_qs(urllib.parse.urlparse(location).query).get('state', [''])[0]


suite.check('the login page has a Google button', 'href="/login/google"' in browser.get('/login'))
response = browser.request('GET', '/login/google')
location = response.get_header('Location')
query = urllib.parse.parse_qs(urllib.parse.urlparse(location).query)
is_google = response.get_status() in (302, 303) and location.startswith('https://accounts.google.com/o/oauth2/auth?')
suite.check('it redirects to Google', is_google, location)
callback = stack.WEB_URL + '/login/google/callback'
is_right = query.get('client_id') == [stack.GOOGLE_SSO_CLIENT_ID] and query.get('redirect_uri') == [callback]
suite.check('with the right client and callback', is_right, query)
state = query.get('state', [''])[0]
suite.check('and a random state', len(state) == 32, state)

response = browser.request('GET', f'/login/google/callback?error=access_denied&state={state}')
is_refused = response.get_status() == 401 and 'cancelled' in response.get_body()
suite.check('cancelling at Google is refused', is_refused, response.get_status())
start_login()
response = browser.request('GET', '/login/google/callback?code=abc&state=wrong')
is_refused = response.get_status() == 401 and 'try again' in response.get_body()
suite.check('a wrong state is refused', is_refused, response.get_status())
state = start_login()
response = browser.request('GET', f'/login/google/callback?code=made-up&state={state}')
is_refused = response.get_status() == 401 and 'couldn' in response.get_body()
suite.check('a code Google refuses is refused', is_refused, response.get_status())
suite.check('and doesn\'t log in', browser.request('GET', '/').get_status() == 303)
response = browser.request('GET', f'/login/google/callback?code=made-up&state={state}')
is_refused = response.get_status() == 401 and 'try again' in response.get_body()
suite.check('a state can\'t be used twice', is_refused, response.get_status())

suite.finish()
