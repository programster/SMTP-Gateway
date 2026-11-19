"""
Multiple providers: the providers API (version checks, validation and secrets), the three modes, skipping a provider
that can't be reached, and the wait for an email to be sent while the client is connected. Uses the test stack's two
Mailpit containers.
"""

import copy
import threading
import time

from lib import stack
from lib.harness import Suite
from lib.service import SmtpReply, create_email_token, create_api_key, send_email
from lib.web import Response, WebClient, bearer, call_api

suite = Suite('Providers')
web = WebClient.create_logged_in()
auth = bearer(create_api_key(web, 'providers'))
key = create_email_token(web, 'providers')


def load_config() -> tuple[dict, str]:
    """Get the providers configuration from the API, and its ETag."""
    response = call_api('GET', '/providers', auth)
    return response.get_json()['config'], response.get_header('ETag')


def save_config(config: dict) -> Response:
    """Save a providers configuration through the API, based on the current version."""
    etag = load_config()[1]
    return call_api('PUT', '/providers', auth, config, {'If-Match': etag})


def find_outcome(subject: str) -> str:
    """Get the provider that sent an email, and its status, as "provider/status"."""
    return stack.sql(f"SELECT coalesce(provider, '') || '/' || status FROM email_message WHERE subject = '{subject}'")


def send(subject: str) -> SmtpReply:
    return send_email(key, subject, 'x@y.test', timeout=120)


original, etag = load_config()

# Reading, version checks and validation.
response = call_api('GET', '/providers', auth)
version = response.get_json()['version']
is_read = original['mode'] == 'overflow' and etag == f'"{version}"'
suite.check('GET returns the configuration, its version and an ETag', is_read, response.get_body()[:200])
response = call_api('PUT', '/providers', auth, original)
suite.check('PUT without If-Match refused (428)', response.get_status() == 428 and response.get_error_code() == 6)
response = call_api('PUT', '/providers', auth, original, {'If-Match': '"stale"'})
suite.check('PUT with a stale version refused (412)', response.get_status() == 412, response.get_body())
invalid = copy.deepcopy(original)
invalid['providers'][0]['ratelimits'] = []
response = save_config(invalid)
is_refused = response.get_status() == 400 and 'ratelimits' in response.get_body()
suite.check('a misspelt setting is refused, naming it', is_refused, response.get_body())
invalid = copy.deepcopy(original)
invalid['providers'][1]['name'] = 'mailpit'
response = save_config(invalid)
is_refused = response.get_status() == 400 and 'two providers called' in response.get_body()
suite.check('duplicate provider names refused', is_refused, response.get_body())
invalid = copy.deepcopy(original)

for provider in invalid['providers']:
    provider['enabled'] = False

response = save_config(invalid)
is_refused = response.get_status() == 400 and 'At least one provider' in response.get_body()
suite.check('no enabled provider refused', is_refused, response.get_body())

# A secret written out in full: shown as <unchanged>, kept when that's saved, and exported as a reference.
config = copy.deepcopy(original)
config['providers'][1]['username'] = 'someone'
config['providers'][1]['password'] = 'plaintext-secret-123'
suite.check('a secret written out in full can be saved', save_config(config).get_status() == 200)
body = call_api('GET', '/providers', auth).get_body()
suite.check('it\'s shown as <unchanged>', '"password":"<unchanged>"' in body and 'plaintext-secret-123' not in body)
response = save_config(call_api('GET', '/providers', auth).get_json()['config'])
on_disk = stack.read_app_file('/config/settings.yaml')
suite.check('saving <unchanged> keeps it', response.get_status() == 200 and 'plaintext-secret-123' in on_disk)

# Saving the providers leaves settings.yaml's other sections as they were.
is_kept = (
        'client_secret: "test-secret"' in on_disk
    and 'timezone: "Europe/London"' in on_disk
    and 'sync_wait_seconds: 5' in on_disk
    and 'retention:' in on_disk
)

suite.check('saving the providers keeps the other settings', is_kept, on_disk[:1500])
exported = call_api('GET', '/providers/export', auth).get_body()

is_replaced = (
        'plaintext-secret-123' not in exported
    and '${PROVIDER_MAILPIT_SECONDARY_PASSWORD}' in exported
    and 'left out' in exported
)

suite.check('the export replaces it with a reference', is_replaced, exported[-600:])
downloaded = web.request('GET', '/providers/export').get_body()

is_safe = (
        'plaintext-secret-123' not in downloaded
    and 'test-secret' not in downloaded
    and '${GOOGLE_SSO_CLIENT_SECRET}' in downloaded
    and 'timezone: "Europe/London"' in downloaded
)

suite.check('the downloaded settings.yaml has every secret replaced with a reference', is_safe, downloaded[:1500])
page = web.get('/providers')
is_hidden = 'mailpit-secondary' in page and 'plaintext-secret-123' not in page and '&lt;unchanged&gt;' in page
suite.check('the providers page doesn\'t show it', is_hidden)
save_config(original)

# Overflow: the primary is limited to 2 emails a minute, so the next 2 go to the secondary.
stack.clear_mailpits()
# Earlier sends are moved out of the way, so they don't count towards the limit.
stack.sql("UPDATE email_message SET dispatched_at = dispatched_at - interval '1 hour' WHERE dispatched_at IS NOT NULL")
stack.sql("UPDATE email_attempt SET dispatched_at = dispatched_at - interval '1 hour'")
config = copy.deepcopy(original)
config['providers'][0]['rate_limits'] = [{'unit': 'emails', 'max': 2, 'period_seconds': 60}]
save_config(config)
replies = [send(f'overflow-{number}') for number in range(4)]
outcomes = [find_outcome(f'overflow-{number}') for number in range(4)]
suite.check('overflow: all sent while the client waited', all(reply.is_sent() for reply in replies), replies)
expected = ['mailpit/sent', 'mailpit/sent', 'mailpit-secondary/sent', 'mailpit-secondary/sent']
suite.check('overflow: 2 through the primary, then the secondary', outcomes == expected, outcomes)

# Round robin.
config = copy.deepcopy(original)
config['mode'] = 'round_robin'
save_config(config)

for number in range(4):
    send(f'round-robin-{number}')

used = [find_outcome(f'round-robin-{number}') for number in range(4)]
suite.check('round robin alternates', used[0] != used[1] and used[1] != used[2] and used[2] != used[3], used)

# Fallback: the next provider is only used when one refuses, or fails.
config = copy.deepcopy(original)
config['mode'] = 'fallback'
save_config(config)

try:
    stack.set_mailpit_refusing(stack.PRIMARY_MAILPIT, 550)
    reply = send('fallback-refused')
    is_moved_on = reply.is_sent() and find_outcome('fallback-refused') == 'mailpit-secondary/sent'
    suite.check('fallback: the primary refuses, so the secondary sends', is_moved_on, reply)
    stack.set_mailpit_refusing(stack.SECONDARY_MAILPIT, 550)
    reply = send('fallback-all-refused')
    is_told = reply.get_code() == 554 and 'Every provider rejected' in reply.get_text()
    suite.check('fallback: every provider refuses, so the client gets a 554', is_told, reply)
    outcome = find_outcome('fallback-all-refused')
    suite.check('fallback: and the email is failed', outcome.endswith('/failed'), outcome)
    stack.set_mailpit_refusing(stack.PRIMARY_MAILPIT, 451)
    stack.set_mailpit_refusing(stack.SECONDARY_MAILPIT, 0)
    reply = send('fallback-temporary')
    is_moved_on = reply.is_sent() and find_outcome('fallback-temporary') == 'mailpit-secondary/sent'
    suite.check('fallback: the primary fails temporarily (451), so the secondary sends', is_moved_on, reply)
finally:
    stack.set_mailpit_refusing(stack.PRIMARY_MAILPIT, 0)
    stack.set_mailpit_refusing(stack.SECONDARY_MAILPIT, 0)

# A provider that can't be reached is skipped, for a while.
stack.stop_services('mailpit')

try:
    reply = send('primary-down')
    is_moved_on = reply.is_sent() and find_outcome('primary-down') == 'mailpit-secondary/sent'
    suite.check('primary unreachable: the secondary sends, and the client is told', is_moved_on, reply)
    query = "SELECT count(*) FROM provider_status WHERE provider = 'mailpit' AND unavailable_until > now()"
    suite.check('the primary is marked unavailable', stack.sql(query) == '1')
    statuses = call_api('GET', '/providers', auth).get_json()['status']
    is_shown = any(status['name'] == 'mailpit' and status['available'] is False for status in statuses)
    suite.check('the API shows it unavailable', is_shown, statuses)
    started = time.time()
    send('primary-still-down')
    took = time.time() - started
    is_skipped = took < 3 and find_outcome('primary-still-down') == 'mailpit-secondary/sent'
    suite.check('it\'s skipped, without waiting to connect to it', is_skipped, took)
    stack.stop_services('mailpit-secondary')
    reply = send('both-down')
    suite.check('every provider unreachable: the client is told queued', reply.is_queued(), reply)
finally:
    stack.start_services('mailpit', 'mailpit-secondary')

stack.sql('DELETE FROM provider_status')
stack.sql("UPDATE email_message SET next_attempt_at = now() WHERE subject = 'both-down'")


def is_sent_after_all() -> bool:
    return find_outcome('both-down').endswith('/sent')


is_sent = stack.wait_until(is_sent_after_all, 20)
suite.check('the queued email is sent once they\'re back', is_sent, find_outcome('both-down'))

# The wait while the client is connected: 16 clients at once, against a limit of 2 a second.
config = copy.deepcopy(original)
config['providers'][0]['rate_limits'] = [{'unit': 'emails', 'max': 2, 'period_seconds': 1}]
config['providers'][1]['enabled'] = False
save_config(config)
parallel_replies = {}


def send_in_parallel(number: int) -> None:
    parallel_replies[number] = send(f'parallel-{number}')


threads = [threading.Thread(target=send_in_parallel, args=(number,)) for number in range(16)]

for thread in threads:
    thread.start()

for thread in threads:
    thread.join()

sent = len([reply for reply in parallel_replies.values() if reply.is_sent()])
queued = len([reply for reply in parallel_replies.values() if reply.is_queued()])
# How many fit within SYNC_WAIT_SECONDS depends on timing, so this only checks there are some of each.
is_split = sent >= 2 and queued >= 2 and sent + queued == 16
suite.check('the early ones are told sent, and the later ones queued at once', is_split, (sent, queued))


def are_all_parallel_sent() -> bool:
    query = "SELECT count(*) FROM email_message WHERE subject LIKE 'parallel-%' AND status = 'sent'"
    return stack.sql(query) == '16'


stack.wait_until(are_all_parallel_sent, 20)
query = "SELECT extract(epoch FROM dispatched_at) FROM email_message WHERE subject LIKE 'parallel-%'"
times = [float(value) for value in stack.sql(query).split()]
most = max(len([other for other in times if start <= other < start + 1.0]) for start in times)
suite.check('all 16 sent, never more than 2 in any second', len(times) == 16 and most <= 2, (len(times), most))
save_config(original)

suite.finish()
