"""
Multiple providers: the providers API (version checks, validation and secrets), the three modes, skipping a provider
that can't be reached, the wait for an email to be sent while the client is connected, email tokens restricted to
particular providers, and providers restricted to particular senders. Uses the test stack's two Mailpit containers.
"""

import copy
import threading
import time

from lib import stack
from lib.harness import Suite
from lib.service import EmailToken, SmtpReply, connect, create_email_token, create_api_key, send_data, send_email
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

is_full = 'plaintext-secret-123' in exported
suite.check('the API\'s export is a backup, with the secret in full', is_full, exported[-600:])
downloaded = web.request('GET', '/providers/export').get_body()

is_backup = (
        'plaintext-secret-123' in downloaded
    and 'test-secret' in downloaded
    and 'timezone: "Europe/London"' in downloaded
    and downloaded.startswith('# A copy of settings.yaml, with every secret in it written out in full')
)

suite.check('the downloaded settings.yaml is a backup, with every secret, under a warning', is_backup,
            downloaded[:1500])
page = web.get('/providers')
is_hidden = 'mailpit-secondary' in page and 'plaintext-secret-123' not in page and '&lt;unchanged&gt;' in page
suite.check('the providers page doesn\'t show it', is_hidden)
save_config(original)

# Overflow: the primary is limited to 2 emails a minute, so the next 2 go to the secondary.
stack.clear_mailpits()
# Earlier sends are moved out of the way, so they don't count towards the limit.
stack.sql("UPDATE email_message SET dispatched_at = dispatched_at - 3600 WHERE dispatched_at IS NOT NULL")
stack.sql("UPDATE email_attempt SET dispatched_at = dispatched_at - 3600")
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
    query = "SELECT count(*) FROM provider_status WHERE provider = 'mailpit' AND unavailable_until > unix_time()"
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
stack.sql("UPDATE email_message SET next_attempt_at = unix_time() WHERE subject = 'both-down'")


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
query = "SELECT dispatched_at FROM email_message WHERE subject LIKE 'parallel-%'"
times = [float(value) for value in stack.sql(query).split()]
most = max(len([other for other in times if start <= other < start + 1.0]) for start in times)
suite.check('all 16 sent, never more than 2 in any second', len(times) == 16 and most <= 2, (len(times), most))
save_config(original)

# Email tokens restricted to particular providers, which are linked to them by their ids.
primary_id = original['providers'][0]['id']
secondary_id = original['providers'][1]['id']
is_fixed = primary_id.endswith('4e01') and secondary_id.endswith('4e02')
suite.check('each provider has its id, from settings.yaml', is_fixed, (primary_id, secondary_id))


def create_restricted(name: str, provider_ids: list[str], priority: int = 1) -> EmailToken:
    """Create an email token restricted to some of the providers, on the web form."""
    fields = {'restrict_providers': '1', 'priority': str(priority)}

    for provider_id in provider_ids:
        fields[f'allowed_provider_{provider_id}'] = '1'

    return create_email_token(web, name, fields)


def send_with(token: EmailToken, subject: str) -> SmtpReply:
    return send_email(token, subject, 'x@y.test', timeout=120)


def find_provider_condition(email_token: dict) -> list | None:
    """Get the provider ids an email token (as the API shows it) is restricted to, or None if it isn't."""
    allowed = [condition['allowed'] for condition in email_token['conditions'] if condition['field'] == 'provider']
    return allowed[0] if allowed else None


def load_providers_of(token: EmailToken) -> list | None:
    """Get the provider ids an email token is restricted to, through the API, or None if it isn't."""
    return find_provider_condition(call_api('GET', f'/email-tokens/{token.get_id()}', auth).get_json()['emailToken'])


only_secondary = create_restricted('only secondary', [secondary_id])
allowed = load_providers_of(only_secondary)
suite.check('the API shows a token\'s provider condition, by id', allowed == [secondary_id], allowed)

for mode in ['overflow', 'round_robin', 'fallback']:
    config = copy.deepcopy(original)
    config['mode'] = mode
    save_config(config)
    outcomes = []

    for number in range(3):
        send_with(only_secondary, f'only-secondary-{mode}-{number}')
        outcomes.append(find_outcome(f'only-secondary-{mode}-{number}'))

    is_secondary = outcomes == ['mailpit-secondary/sent'] * 3
    suite.check(f'{mode}: a token restricted to one provider always uses it', is_secondary, outcomes)

save_config(original)

# A rename keeps the link, as it's by id. A configuration given without ids keeps them too, by name.
config = copy.deepcopy(original)
config['providers'][1]['name'] = 'mailpit-renamed'
save_config(config)
send_with(only_secondary, 'only-secondary-renamed')
outcome = find_outcome('only-secondary-renamed')
suite.check('renaming its provider keeps the token linked to it', outcome == 'mailpit-renamed/sent', outcome)
save_config(original)
without_ids = copy.deepcopy(original)

for provider in without_ids['providers']:
    del provider['id']

response = save_config(without_ids)
ids = [provider['id'] for provider in response.get_json()['config']['providers']]
suite.check('providers saved without ids keep theirs', ids == [primary_id, secondary_id], ids)
allowed = load_providers_of(only_secondary)
suite.check('so the token stays linked', allowed == [secondary_id], allowed)

# With its only provider disabled, its emails are accepted and queued, then sent once it's enabled again.
config = copy.deepcopy(original)
config['providers'][1]['enabled'] = False
save_config(config)
reply = send_with(only_secondary, 'only-secondary-disabled')
suite.check('its only provider disabled: the email is queued', reply.is_queued(), reply)
page = web.get(f'/email-tokens/{only_secondary.get_id()}')
suite.check('and the token\'s page warns that its emails wait', 'None of its providers is enabled' in page)
reply = send('unrestricted-while-disabled')
suite.check('an unrestricted token still sends, through the other', reply.is_sent(), reply)
save_config(original)


def is_disabled_one_sent() -> bool:
    return find_outcome('only-secondary-disabled') == 'mailpit-secondary/sent'


is_sent = stack.wait_until(is_disabled_one_sent, 20)
outcome = find_outcome('only-secondary-disabled')
suite.check('once it\'s enabled again, the queued email is sent through it', is_sent, outcome)

# A higher priority token waiting for room under its own provider doesn't hold up a lower priority one that can use
# another provider.
stack.sql("UPDATE email_message SET dispatched_at = dispatched_at - 3600 WHERE dispatched_at IS NOT NULL")
stack.sql("UPDATE email_attempt SET dispatched_at = dispatched_at - 3600")
config = copy.deepcopy(original)
config['providers'][0]['rate_limits'] = [{'unit': 'emails', 'max': 1, 'period_seconds': 60}]
save_config(config)
high = create_restricted('high, primary only', [primary_id], 50)
send_with(high, 'high-first')
reply = send_with(high, 'high-waiting')
suite.check('a high priority token at its only provider\'s limit is queued', reply.is_queued(), reply)
reply = send_with(only_secondary, 'low-other-provider')
outcome = find_outcome('low-other-provider')
is_through = reply.is_sent() and outcome == 'mailpit-secondary/sent'
suite.check('a lower priority token restricted to another provider isn\'t held up', is_through, (reply, outcome))
reply = send('low-unrestricted')
outcome = find_outcome('low-unrestricted')
is_through = reply.is_sent() and outcome == 'mailpit-secondary/sent'
suite.check('nor is an unrestricted one, which can use the other provider', is_through, (reply, outcome))
outcome = find_outcome('high-waiting')
suite.check('and the high priority email still waits for its own', outcome == '/queued', outcome)
save_config(original)
stack.sql("UPDATE email_attempt SET dispatched_at = dispatched_at - 3600")

# Removing a provider says which tokens were linked to it, and drops their links. One left with none stays
# restricted, to none, rather than being allowed every provider.
page = web.get(f'/providers/{original["providers"][1]["name"]}')
suite.check('a provider\'s page lists the tokens restricted to it', 'only secondary' in page)
config = copy.deepcopy(original)
del config['providers'][1]
response = save_config(config)
unlinked = response.get_json()['unlinkedEmailTokenIds']
suite.check('removing it says which tokens lost their link', only_secondary.get_id() in unlinked, unlinked)
allowed = load_providers_of(only_secondary)
suite.check('a token left with no provider stays restricted, to none', allowed == [], allowed)
reply = send_with(only_secondary, 'only-secondary-removed')
suite.check('so its emails are queued', reply.is_queued(), reply)
page = web.get(f'/email-tokens/{only_secondary.get_id()}')
suite.check('and its page says its providers have all been removed', 'has been removed' in page)
save_config(original)
allowed = load_providers_of(only_secondary)
suite.check('adding the provider back doesn\'t link it again', allowed == [], allowed)
response = call_api('PATCH', f'/email-tokens/{only_secondary.get_id()}', auth,
                    {'conditions': [{'field': 'provider', 'allowed': ['5c2f0e7a-1d3b-4a6c-9e8f-0a1b2c3d4eff']}]})
is_refused = response.get_status() == 400 and 'no provider with the id' in response.get_body()
suite.check('an id that isn\'t a provider\'s is refused', is_refused, response.get_body())
response = call_api('PATCH', f'/email-tokens/{only_secondary.get_id()}', auth,
                    {'conditions': [{'field': 'provider', 'allowed': None}]})
allowed = find_provider_condition(response.get_json()['emailToken'])
suite.check('removing the condition lets it use any provider', allowed is None, allowed)


def is_removed_one_sent() -> bool:
    return find_outcome('only-secondary-removed').endswith('/sent')


stack.sql("UPDATE email_message SET next_attempt_at = unix_time() WHERE subject = 'only-secondary-removed'")
is_sent = stack.wait_until(is_removed_one_sent, 20)
suite.check('and its queued email is then sent', is_sent, find_outcome('only-secondary-removed'))

# Providers restricted to particular senders: anyone at the domains, or with the addresses. The primary only takes
# example.org (which the other sends here are from), and the secondary programster.org, or stuart@stuart.page.
SECONDARY_SENDERS = {'domains': ['programster.org'], 'addresses': ['stuart@stuart.page']}


def send_as(envelope_sender: str, from_header: str, subject: str) -> tuple[int, SmtpReply]:
    """
    Send an email with an envelope sender and a From header, and get the reply to MAIL FROM, and the reply to the
    email (which is the MAIL FROM's, when it was refused there).
    """
    connection = connect(key, 120)
    mail_code, mail_text = connection.mail(envelope_sender)
    reply = SmtpReply(mail_code, mail_text.decode())

    if mail_code == 250:
        connection.rcpt('x@y.test')
        reply = send_data(connection, f'From: {from_header}\r\nSubject: {subject}\r\n\r\nbody\r\n')

    connection.quit()
    return mail_code, reply


def restrict_senders(secondary_enabled: bool = True, secondary_senders: dict | None = None) -> Response:
    config = copy.deepcopy(original)
    config['providers'][0]['allowed_senders'] = {'domains': ['example.org']}
    config['providers'][1]['allowed_senders'] = secondary_senders or SECONDARY_SENDERS
    config['providers'][1]['enabled'] = secondary_enabled
    return save_config(config)


response = restrict_senders()
saved = response.get_json()['config']['providers'][1].get('allowed_senders')
suite.check('a provider\'s allowed senders are saved, as given', saved == SECONDARY_SENDERS, response.get_body()[:300])
page = web.get('/providers/mailpit-secondary')
suite.check('and shown on its page', 'stuart@stuart.page' in page and 'programster.org' in page)
code, reply = send_as('a@programster.org', 'a@programster.org', 'senders-domain')
outcome = find_outcome('senders-domain')
suite.check('anyone at a domain it takes goes through it', outcome == 'mailpit-secondary/sent', (reply, outcome))
code, reply = send_as('stuart@stuart.page', 'stuart@stuart.page', 'senders-address')
outcome = find_outcome('senders-address')
suite.check('and so does an address it takes', outcome == 'mailpit-secondary/sent', (reply, outcome))
send('senders-other')
outcome = find_outcome('senders-other')
suite.check('a sender it doesn\'t take goes through the provider that does', outcome == 'mailpit/sent', outcome)
code, reply = send_as('someone@stuart.page', 'someone@stuart.page', 'senders-none')
is_refused = code == 550 and 'No provider' in reply.get_text()
suite.check('a sender no provider takes is refused at MAIL FROM', is_refused, reply)
code, reply = send_as('a@programster.org', 'someone@stuart.page', 'senders-from-header')
is_refused = code == 250 and reply.get_code() == 550 and 'someone@stuart.page' in reply.get_text()
suite.check('as is a From header no provider takes, after DATA', is_refused, (code, reply))
api_email = {'from': {'email': 'someone@stuart.page'}, 'to': [{'email': 'x@y.test'}], 'subject': 's', 'text': 'x'}
response = call_api('POST', '/send', bearer(key.get_token()), api_email)
is_refused = response.get_status() == 403 and response.get_error_code() == 24
suite.check('and through the API, with error code 24', is_refused, response.get_body())
rejected = stack.sql("SELECT count(*) FROM rejected_request WHERE reason = 'no_provider_for_sender'")
suite.check('each refusal is recorded', int(rejected) >= 3, rejected)

# A sender only a disabled provider takes is queued for it, as it may be enabled again. If the providers change so
# that none takes it any more, it's failed, rather than waiting forever.
restrict_senders(secondary_enabled=False)
code, reply = send_as('a@programster.org', 'a@programster.org', 'senders-disabled-changed')
suite.check('a sender only a disabled provider takes is queued', reply.is_queued(), (code, reply))
restrict_senders(secondary_enabled=False, secondary_senders={'domains': ['example.net']})


def is_changed_one_failed() -> bool:
    return find_outcome('senders-disabled-changed') == '/failed'


is_failed = stack.wait_until(is_changed_one_failed, 20)
error = stack.sql("SELECT last_error FROM email_message WHERE subject = 'senders-disabled-changed'")
is_explained = is_failed and 'any more' in error
suite.check('once no provider takes it any more, it\'s failed, saying why', is_explained, error)
restrict_senders(secondary_enabled=False)
code, reply = send_as('a@programster.org', 'a@programster.org', 'senders-disabled')
suite.check('another is queued for the disabled provider', reply.is_queued(), (code, reply))
restrict_senders()


def is_disabled_sender_sent() -> bool:
    return find_outcome('senders-disabled') == 'mailpit-secondary/sent'


is_sent = stack.wait_until(is_disabled_sender_sent, 20)
suite.check('and is sent through it once it\'s enabled', is_sent, find_outcome('senders-disabled'))
save_config(original)

suite.finish()
