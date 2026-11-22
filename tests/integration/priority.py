"""
Priorities: keys' priorities, and the service's own emails (e.g. password resets) going first, while every limit still
applies to them.
"""

from lib import stack
from lib.harness import Suite
from lib.service import EmailToken, create_email_token, create_api_key, send_email
from lib.web import WebClient, bearer, call_api

suite = Suite('Priority')
web = WebClient.create_logged_in()
auth = bearer(create_api_key(web, 'priority'))


def create_policy(name: str, extra: dict) -> dict:
    """A key policy that allows anything (it has no conditions), with no rate limits."""
    policy = {
        'name': name,
        'rateLimits': [],
    }

    return policy | extra


response = call_api('POST', '/email-tokens', auth, create_policy('low', {}))
low = response.get_json()
suite.check('default priority is 1', response.get_status() == 201 and low['emailToken']['priority'] == 1, low)
response = call_api('POST', '/email-tokens', auth, create_policy('high', {'priority': 50}))
high = response.get_json()
suite.check('priority 50 accepted', response.get_status() == 201 and high['emailToken']['priority'] == 50, high)

for invalid in [0, 9999, 'x', 1.5]:
    response = call_api('POST', '/email-tokens', auth, create_policy('invalid', {'priority': invalid}))
    is_refused = response.get_status() == 400 and 'priority' in response.get_json()['error']['message']
    suite.check(f'priority {invalid!r} refused', is_refused, response.get_body())

# The web form.
key = create_email_token(web, 'web priority', {'priority': '7'})
suite.check('priority shown on the key page', '<h3>Priority</h3><p>7</p>' in web.get(f'/email-tokens/{key.get_id()}'))
response = web.post_form('/email-tokens/new', '/email-tokens', {
    'name': 'invalid priority',
    'allowed_ips_0': '0.0.0.0/0',
    'priority': '0',
})

is_refused = response.get_status() == 400 and 'Priority:' in response.get_body()
suite.check('web form refuses priority 0', is_refused, response.get_status())

# The order the queue is sent in, under a global limit of 1 email every 8 seconds.
response = call_api('PUT', '/rate-limits', auth, {'rateLimits': [{'unit': 'emails', 'max': 1, 'periodSeconds': 8}]})
suite.check('global limit set', response.get_status() == 200, response.get_body())
low_key = EmailToken(low['token'])
high_key = EmailToken(high['token'])
replies = [send_email(low_key, f'prio-low-{number}') for number in range(4)]
replies.append(send_email(high_key, 'prio-high'))
# Only one reset email is sent per account every 5 minutes, so any earlier one is moved out of the way.
stack.sql("UPDATE password_reset_token SET created_at = unix_time() - 600")
web.post_form('/forgot-password', '/forgot-password', {'email': stack.ADMIN_EMAIL})
suite.check('all but the first told queued', all(reply.is_queued() for reply in replies[1:]), replies)


def is_queue_empty() -> bool:
    return stack.sql("SELECT count(*) FROM email_message WHERE status = 'queued'") == '0'


stack.wait_until(is_queue_empty, 90)
ours = "(subject LIKE 'prio-%' OR source = 'system')"
order = stack.sql(f'SELECT subject FROM email_message WHERE {ours} ORDER BY dispatched_at').split('\n')
expected = ['prio-low-0', 'Reset your password', 'prio-high', 'prio-low-1', 'prio-low-2', 'prio-low-3']
suite.check('order: the service\'s own, then high, then the rest of low', order == expected, order)

gaps = stack.sql(
    'SELECT round(min(gap)) FROM (SELECT (dispatched_at - lag(dispatched_at)'
    f' OVER (ORDER BY dispatched_at)) AS gap FROM email_message WHERE {ours}) AS gaps WHERE gap IS NOT NULL'
)

is_spaced = gaps != '' and int(gaps) >= 7
suite.check('the global limit still applied to the service\'s own (8 seconds apart)', is_spaced, gaps)

row = stack.sql(
    "SELECT source, email_token_id IS NULL, priority, status, raw_message_base64 IS NULL FROM email_message"
    " WHERE source = 'system'"
)

suite.check('recorded with no key, priority 9999, sent, content deleted', row == 'system|t|9999|sent|t', row)
system_id = stack.sql("SELECT id FROM email_message WHERE source = 'system'")
response = web.request('GET', f'/messages/{system_id}')
is_shown = 'Sent by the service itself' in response.get_body() and 'Never kept' in response.get_body()
suite.check('its page says it was sent by the service', response.get_status() == 200 and is_shown)
suite.check('the messages list shows the service', 'The service' in web.get('/messages'))
message = call_api('GET', f'/messages/{system_id}', auth).get_json()['message']
is_right = message['emailTokenId'] is None and message['priority'] == 9999 and message['hasContent'] is False
suite.check('API: emailTokenId null, priority 9999, no content', is_right, message)
suite.check('API download refused', call_api('GET', f'/messages/{system_id}/raw', auth).get_status() == 404)
call_api('PUT', '/rate-limits', auth, {'rateLimits': []})

suite.finish()
