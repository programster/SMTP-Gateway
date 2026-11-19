"""
The test stack: where to reach it, and helpers for looking inside it (its database and containers) and at the two
Mailpit containers that catch the emails it relays.

The settings come from the environment, which run-tests.sh fills from the stack's env file
(tests/.run/<stack>/stack.env), with defaults that match its "main" stack.
"""

import json
import os
import subprocess
import time
import urllib.parse
import urllib.request
from typing import Any, Callable

PROJECT = os.environ.get('COMPOSE_PROJECT_NAME', 'smtpapitest-main')
WEB_URL = 'http://localhost:' + os.environ.get('WEB_HOST_PORT', '18180')
SMTPS_PORT = int(os.environ.get('SMTPS_HOST_PORT', '14565'))
SUBMISSION_PORT = int(os.environ.get('SUBMISSION_HOST_PORT', '14687'))
ADMIN_EMAIL = os.environ.get('ADMIN_EMAIL', 'admin@example.org')
# The default account's password, whose hash is ADMIN_PASSWORD_HASH in tests/test.env.
ADMIN_PASSWORD = 'correct-horse-battery'
SYSTEM_EMAIL_FROM = os.environ.get('SYSTEM_EMAIL_FROM', 'noreply@example.org')
GOOGLE_SSO_CLIENT_ID = os.environ.get('GOOGLE_SSO_CLIENT_ID', 'test-client.apps.googleusercontent.com')
# Where the app keeps its settings, which a stack can move (e.g. to start with none, as a fresh install does).
SETTINGS_FILE = os.environ.get('SETTINGS_FILE', '') or '/config/settings.yaml'
STATE_DIR = os.environ.get('TEST_STATE_DIR', os.path.join(os.path.dirname(__file__), '..', '.run', 'main', 'state'))

# The two Mailpit containers, by their provider names in the test stack's settings.yaml.
PRIMARY_MAILPIT = 'mailpit'
SECONDARY_MAILPIT = 'mailpit-secondary'

MAILPIT_URLS = {
    PRIMARY_MAILPIT: 'http://localhost:' + os.environ.get('MAILPIT_HOST_PORT', '18125'),
    SECONDARY_MAILPIT: 'http://localhost:' + os.environ.get('MAILPIT_SECONDARY_HOST_PORT', '18126'),
}


def get_container(service: str) -> str:
    """Get the name of one of the test stack's containers, e.g. "app" or "mailpit"."""
    return f'{PROJECT}-{service}'


def sql(query: str) -> str:
    """Run a query against the test stack's database, and get its output: one row per line, columns split by "|"."""
    database = os.environ.get('DB_NAME', 'smtpapi')
    user = os.environ.get('DB_USER', 'smtpapi')
    command = ['docker', 'exec', get_container('db'), 'psql', '-U', user, '-d', database, '-tAc', query]
    return subprocess.run(command, capture_output=True, text=True, check=True).stdout.strip()


def run_php(code: str) -> str:
    """Run some PHP in the app container, with the site loaded, and get what it printed."""
    command = ['docker', 'exec', '-u', 'www-data', get_container('app'), 'php', '-r',
               "require '/var/www/site/bootstrap.php'; " + code]

    return subprocess.run(command, capture_output=True, text=True, check=True).stdout.strip()


def run_script(name: str, *arguments: str) -> subprocess.CompletedProcess:
    """Run one of the site's scripts (in site/scripts) in the app container, as the web server's user would."""
    command = ['docker', 'exec', '-u', 'www-data', get_container('app'), 'php', f'/var/www/site/scripts/{name}']
    return subprocess.run(command + list(arguments), capture_output=True, text=True)


def read_app_file(path: str) -> str:
    """Read a file in the app container."""
    command = ['docker', 'exec', get_container('app'), 'cat', path]
    return subprocess.run(command, capture_output=True, text=True, check=True).stdout


def stop_services(*services: str) -> None:
    """Stop some of the test stack's containers, e.g. a Mailpit, to make a provider unreachable."""
    command = ['docker', 'stop'] + [get_container(service) for service in services]
    subprocess.run(command, capture_output=True, check=True)


def start_services(*services: str) -> None:
    """Start containers stopped with stop_services()."""
    command = ['docker', 'start'] + [get_container(service) for service in services]
    subprocess.run(command, capture_output=True, check=True)


def wait_until(condition: Callable[[], bool], seconds: float) -> bool:
    """
    Wait until something is true, checking every half a second.

    :param condition: what to wait for.

    :param seconds: how long to wait at most.

    :return: whether it became true in time.
    """
    deadline = time.time() + seconds
    is_met = condition()

    while not is_met and time.time() < deadline:
        time.sleep(0.5)
        is_met = condition()

    return is_met


def call_mailpit(mailpit: str, method: str, path: str, body: Any = None) -> Any:
    """
    Call one of the Mailpit containers' API.

    :param mailpit: PRIMARY_MAILPIT or SECONDARY_MAILPIT.

    :param method: the HTTP method.

    :param path: the path under /api/v1, e.g. "/messages".

    :param body: what to send as JSON, if anything.

    :return: the decoded JSON response, or None if it wasn't JSON (e.g. a deletion's "ok").
    """
    data = None if body is None else json.dumps(body).encode()
    headers = {} if body is None else {'Content-Type': 'application/json'}
    url = MAILPIT_URLS[mailpit] + '/api/v1' + path
    request = urllib.request.Request(url, data=data, method=method, headers=headers)
    response = urllib.request.urlopen(request)
    content = response.read()
    is_json = response.headers.get('Content-Type', '').startswith('application/json')
    return json.loads(content) if is_json else None


def clear_mailpits() -> None:
    """Delete every email both Mailpit containers have caught."""
    for mailpit in MAILPIT_URLS:
        call_mailpit(mailpit, 'DELETE', '/messages')


def search_mailpit(query: str, mailpit: str = PRIMARY_MAILPIT) -> dict:
    """Search the emails a Mailpit has caught, e.g. 'to:a@b.test subject:"Hello"'."""
    return call_mailpit(mailpit, 'GET', '/search?query=' + urllib.parse.quote(query))


def get_mailpit_text(message_id: str, mailpit: str = PRIMARY_MAILPIT) -> str:
    """Get the plain text body of an email a Mailpit has caught."""
    return call_mailpit(mailpit, 'GET', f'/message/{message_id}')['Text']


def get_mailpit_raw(message_id: str, mailpit: str = PRIMARY_MAILPIT) -> str:
    """Get an email a Mailpit has caught, exactly as it was received."""
    url = MAILPIT_URLS[mailpit] + f'/api/v1/message/{message_id}/raw'
    return urllib.request.urlopen(url).read().decode()


def set_mailpit_refusing(mailpit: str, error_code: int) -> None:
    """Make a Mailpit refuse every email with an SMTP error code (e.g. 451 or 550), or 0 to accept them again."""
    is_refusing = error_code > 0
    chaos = {'ErrorCode': error_code if is_refusing else 451, 'Probability': 100 if is_refusing else 0}
    call_mailpit(mailpit, 'PUT', '/chaos', {'Sender': chaos})
