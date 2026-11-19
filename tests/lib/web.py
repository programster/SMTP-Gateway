"""
HTTP requests to the test stack: a browser-like client for the web interface (which keeps its cookies, and logs in,
giving the second factor), and plain requests for the API.
"""

import base64
import hashlib
import hmac
import http.cookiejar
import json
import os
import re
import struct
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

from lib import stack


class Response:
    """An HTTP response: its status, body and headers."""

    def __init__(self, status: int, body: str, headers: dict) -> None:
        self.m_status = status
        self.m_body = body
        self.m_headers = headers

    def get_json(self) -> Any:
        """Decode the body as JSON."""
        return json.loads(self.m_body)

    def get_header(self, name: str) -> str:
        """Get a header, or an empty string if there isn't one."""
        return self.m_headers.get(name, '')

    def get_error_code(self) -> int:
        """Get the application's error code from a JSON error response, or 0 if it isn't one."""
        decoded = json.loads(self.m_body) if self.m_body.startswith('{') else {}
        return decoded.get('error', {}).get('code', 0)

    # Accessors
    def get_status(self) -> int: return self.m_status
    def get_body(self) -> str: return self.m_body
    def get_headers(self) -> dict: return dict(self.m_headers)


class NoRedirects(urllib.request.HTTPRedirectHandler):
    """Stops urllib following redirects, so a test can check where a response sends the browser."""

    def redirect_request(self, request, fp, code, msg, headers, new_url) -> None:
        return None


def send_request(opener: urllib.request.OpenerDirector, method: str, url: str, body: bytes | None,
                 headers: dict) -> Response:
    """Send a request, and get the response, whatever its status."""
    request = urllib.request.Request(url, data=body, method=method, headers=headers)

    try:
        response = opener.open(request)
        result = Response(response.status, response.read().decode(), dict(response.headers))
    except urllib.error.HTTPError as error:
        result = Response(error.code, error.read().decode(), dict(error.headers))

    return result


def call_api(method: str, path: str, auth_header: str, body: Any = None, headers: dict | None = None) -> Response:
    """
    Call the API.

    :param method: the HTTP method.

    :param path: the path under /api/v1, e.g. "/email-tokens".

    :param auth_header: the Authorization header, e.g. from bearer().

    :param body: what to send as JSON, if anything.

    :param headers: any other headers.

    :return: the response.
    """
    all_headers = dict(headers or {})
    all_headers['Authorization'] = auth_header
    data = None

    if body is not None:
        data = json.dumps(body).encode()
        all_headers['Content-Type'] = 'application/json'

    opener = urllib.request.build_opener(NoRedirects())
    return send_request(opener, method, stack.WEB_URL + '/api/v1' + path, data, all_headers)


def bearer(token: str) -> str:
    """The Authorization header for an API key (the management API) or an email token (the send endpoint)."""
    return 'Bearer ' + token


def basic(username: str, password: str) -> str:
    """An HTTP Basic Authorization header, which no endpoint takes: only for checking it's refused."""
    return 'Basic ' + base64.b64encode(f'{username}:{password}'.encode()).decode()


def find_csrf_token(html: str) -> str:
    """Get the CSRF token from a page's form."""
    return re.search(r'name="csrf_token" value="([0-9a-f]+)"', html).group(1)


def create_totp_code(secret: str, at: float = 0.0) -> str:
    """Work out an authenticator app's code (RFC 6238) for a TOTP secret, now or at a given time."""
    key = base64.b32decode(secret + '=' * (-len(secret) % 8))
    counter = int((at if at > 0 else time.time()) // 30)
    digest = hmac.new(key, struct.pack('>Q', counter), hashlib.sha1).digest()
    offset = digest[-1] & 15
    code = (struct.unpack('>I', digest[offset:offset + 4])[0] & 0x7fffffff) % 1000000
    return '%06d' % code


def wait_for_next_totp_step() -> None:
    """Wait for the next 30 second step, as each code can only be used once."""
    time.sleep(31 - (time.time() % 30))


def create_unused_totp_code(email: str, secret: str) -> str:
    """
    Get a code for an account that it hasn't used yet. Each code can only be used once, so if this step's has been
    used (by an earlier login, as far as these tests know), this waits for the next step.
    """
    path = os.path.join(stack.STATE_DIR, 'totp-used-steps.json')
    used_steps = {}

    if os.path.exists(path):
        with open(path) as file:
            used_steps = json.load(file)

    if used_steps.get(email.lower(), -1) >= int(time.time() // 30):
        wait_for_next_totp_step()

    used_steps[email.lower()] = int(time.time() // 30)
    os.makedirs(stack.STATE_DIR, exist_ok=True)

    with open(path, 'w') as file:
        json.dump(used_steps, file)

    return create_totp_code(secret)


def forget_used_totp_code(email: str) -> None:
    """
    Let an account use its current TOTP code again, by clearing the service's record of the last code it used, and
    these tests' own (see create_unused_totp_code()), so the next login doesn't wait up to 30 seconds for a new code.
    Only for a suite that logs in several times, and isn't testing that a code can't be used twice (e2e tests that).
    """
    stack.sql(f"UPDATE admin_user SET totp_last_used_step = NULL WHERE email = '{email.lower()}'")
    path = os.path.join(stack.STATE_DIR, 'totp-used-steps.json')

    if os.path.exists(path):
        with open(path) as file:
            used_steps = json.load(file)

        used_steps.pop(email.lower(), None)

        with open(path, 'w') as file:
            json.dump(used_steps, file)


def save_totp_secret(email: str, secret: str) -> None:
    """Keep an account's TOTP secret, so later suites can log in as it."""
    secrets = load_totp_secrets()
    secrets[email.lower()] = secret
    os.makedirs(stack.STATE_DIR, exist_ok=True)

    with open(os.path.join(stack.STATE_DIR, 'totp-secrets.json'), 'w') as file:
        json.dump(secrets, file)


def load_totp_secrets() -> dict:
    """Get every TOTP secret kept by save_totp_secret(), by email address."""
    path = os.path.join(stack.STATE_DIR, 'totp-secrets.json')
    secrets = {}

    if os.path.exists(path):
        with open(path) as file:
            secrets = json.load(file)

    return secrets


class WebClient:
    """A browser: keeps its cookies, and doesn't follow redirects, so tests can check them."""

    def __init__(self) -> None:
        cookies = urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())
        self.m_opener = urllib.request.build_opener(cookies, NoRedirects())

    @staticmethod
    def create_logged_in(email: str = stack.ADMIN_EMAIL, password: str = stack.ADMIN_PASSWORD) -> 'WebClient':
        """Create a browser that's logged in, setting up a second factor first if the account needs one."""
        client = WebClient()
        client.log_in(email, password)
        return client

    def request(self, method: str, path: str, form: dict | None = None, body: Any = None,
                headers: dict | None = None) -> Response:
        """
        Send a request to the web interface.

        :param method: the HTTP method.

        :param path: the path, e.g. "/email-tokens".

        :param form: form fields to post, if any.

        :param body: what to send as JSON instead, if anything.

        :param headers: any other headers.

        :return: the response.
        """
        all_headers = dict(headers or {})
        data = None

        if form is not None:
            data = urllib.parse.urlencode(form).encode()
            all_headers['Content-Type'] = 'application/x-www-form-urlencoded'
        elif body is not None:
            data = json.dumps(body).encode()
            all_headers['Content-Type'] = 'application/json'

        return send_request(self.m_opener, method, stack.WEB_URL + path, data, all_headers)

    def get(self, path: str) -> str:
        """Get a page's HTML."""
        return self.request('GET', path).get_body()

    def post(self, path: str, form: dict) -> str:
        """Post a form, and get the page it responds with."""
        return self.request('POST', path, form).get_body()

    def post_form(self, form_page: str, path: str, form: dict) -> Response:
        """Post a form, with the CSRF token from the page it's on."""
        return self.request('POST', path, {'csrf_token': find_csrf_token(self.get(form_page))} | form)

    def log_in(self, email: str, password: str) -> None:
        """
        Log in, giving the second factor if the account has one (from the secrets kept by save_totp_secret()), or
        setting one up if the account must have one and doesn't yet.
        """
        login_page = self.get('/login')
        form = {'csrf_token': find_csrf_token(login_page), 'email': email, 'password': password}
        response = self.request('POST', '/login', form)

        if response.get_header('Location') == '/login/second-factor':
            secret = load_totp_secrets()[email.lower()]
            page = self.get('/login/second-factor')
            totp_form = {'csrf_token': find_csrf_token(page), 'code': create_unused_totp_code(email, secret)}
            self.request('POST', '/login/second-factor/totp', totp_form)

        if self.request('GET', '/').get_header('Location') == '/account':
            self.set_up_totp(email)

    def set_up_totp(self, email: str) -> str:
        """Set up an authenticator app for the logged in account, keeping its secret, and get the secret."""
        page = self.get('/account/totp')
        secret = re.search(r'by hand:\s+<code>([A-Z2-7 ]+)</code>', page).group(1).replace(' ', '')
        code = create_unused_totp_code(email, secret)
        self.request('POST', '/account/totp', {'csrf_token': find_csrf_token(page), 'code': code})
        save_totp_secret(email, secret)
        return secret
