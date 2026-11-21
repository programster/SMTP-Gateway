"""
SMTP2GO's webhook, through the real endpoint and database: only requests with the secret are accepted, an event is
matched to its email by SMTP2GO's ID (and only for an email sent through that provider), a retry isn't recorded twice,
and the provider's page prompts for the webhook until it's set up.
"""

import json
import re

from lib import stack
from lib.harness import Suite
from lib.service import create_email_token, send_email
from lib.web import Response, WebClient, bearer

SECRET = '0123456789abcdef0123456789abcdef'
PROVIDER = 'test-smtp2go-hook'
PATH = f'/webhooks/{PROVIDER}'

suite = Suite('SMTP2GO webhook')
web = WebClient.create_logged_in()


def add_provider(name: str, secret: str) -> None:
    """Add a disabled SMTP2GO provider with only an API key, so nothing is sent through it."""
    version = re.search(r'name="version" value="([0-9a-f]+)"', web.get('/providers/new')).group(1)

    form = {'version': version, 'type': 'smtp2go', 'name': name, 'enabled': '', 'smtp2go_smtp_username': '',
            'smtp2go_smtp_password': '', 'smtp2go_api_key': 'api-test', 'smtp2go_webhook_secret': secret}

    response = web.post_form('/providers/new', '/providers/new', form)
    suite.check(f'{name} is added', response.get_status() == 303, response.get_body()[:300])


def post_event(event: dict, authorization: str = bearer(SECRET)) -> Response:
    """Post an event to the webhook."""
    headers = {'Authorization': authorization, 'Content-Type': 'application/json'}
    return WebClient().request('POST', PATH, body=event, headers=headers)


def count_events(message_id: str) -> int:
    """Count the events recorded against an email."""
    return int(stack.sql(f"SELECT count(*) FROM email_event WHERE email_message_id = '{message_id}'"))


add_provider(PROVIDER, '')
page = web.get(f'/providers/{PROVIDER}')
suite.check('its page prompts for the webhook', 'aren&apos;t being recorded' in page or "aren't being recorded" in page)
suite.check('with a button, as it has an API key', f'action="/providers/{PROVIDER}/webhook"' in page)
suite.check('and the steps to do it by hand', 'Set it up by hand' in page)
response = post_event({'event': 'delivered', 'email_id': 'none'})
suite.check('without a secret, its webhook doesn\'t exist', response.get_status() == 404, response.get_status())

path = f'/providers/{PROVIDER}/edit'
version = re.search(r'name="version" value="([0-9a-f]+)"', web.get(path)).group(1)

form = {'version': version, 'name': PROVIDER, 'enabled': '', 'smtp2go_smtp_username': '', 'smtp2go_smtp_password': '',
        'smtp2go_api_key': '', 'smtp2go_webhook_secret': SECRET}

response = web.post_form(path, path, form)
suite.check('a secret is saved', response.get_status() == 303, response.get_body()[:300])
page = web.get(f'/providers/{PROVIDER}')
suite.check('and the prompt goes', 'aren&apos;t being recorded' not in page and "aren't being recorded" not in page)

token = create_email_token(web, 'smtp2go-webhook')
message_id = send_email(token, 'smtp2go-webhook').get_message_id()
stack.sql(f"UPDATE email_message SET provider = '{PROVIDER}', provider_message_id = 'smtp2go-1' WHERE id = '{message_id}'")
other_id = send_email(token, 'smtp2go-webhook-other').get_message_id()
stack.sql(f"UPDATE email_message SET provider_message_id = 'smtp2go-other' WHERE id = '{other_id}'")

delivered = {'event': 'delivered', 'time': '2026-11-21T16:33:58Z', 'email_id': 'smtp2go-1', 'rcpt': 'a@b.test'}
response = post_event(delivered, 'Bearer wrong-secret')
suite.check('a wrong secret is refused', response.get_status() == 401, response.get_status())
suite.check('and records nothing', count_events(message_id) == 0)

response = post_event(delivered)
suite.check('an event with the secret is accepted', response.get_status() == 200, response.get_body())
suite.check('and recorded against its email', response.get_json().get('recorded') == 1, response.get_body())
row = stack.sql(f"SELECT event_type, recipient FROM email_event WHERE email_message_id = '{message_id}'")
suite.check('as delivered, to its recipient', row == 'delivered|a@b.test', row)

response = post_event(delivered)
suite.check('a retry isn\'t recorded twice', response.get_json().get('recorded') == 0 and count_events(message_id) == 1)

response = post_event({'event': 'bounce', 'bounce': 'hard', 'email_id': 'smtp2go-1', 'rcpt': 'a@b.test',
                       'time': '2026-11-21T16:34:00Z', 'message': '550 no such user'})

bounce = stack.sql(f"SELECT detail FROM email_event WHERE email_message_id = '{message_id}' AND event_type = 'bounced'")
suite.check('a bounce is recorded, with its reason', bounce == '550 no such user', bounce)

response = post_event({'event': 'delivered', 'email_id': 'someone-elses-email', 'rcpt': 'x@b.test'})
suite.check('an event for an email this service didn\'t send is ignored', response.get_json().get('recorded') == 0)

response = post_event({'event': 'delivered', 'email_id': 'smtp2go-other', 'rcpt': 'a@b.test'})
is_ignored = response.get_json().get('recorded') == 0 and count_events(other_id) == 0
suite.check('as is one matching an email sent through another provider', is_ignored, response.get_body())

form_body = 'event=open&email_id=smtp2go-1&rcpt=a%40b.test&time=2026-11-21T16%3A35%3A00Z'
headers = {'Authorization': bearer(SECRET), 'Content-Type': 'application/x-www-form-urlencoded'}
response = WebClient().request('POST', PATH, headers=headers, body=None, form=dict(
    item.split('=') for item in form_body.replace('%40', '@').replace('%3A', ':').split('&')
))
suite.check('a form encoded event is recorded too', response.get_status() == 200
            and response.get_json().get('recorded') == 1, response.get_body())

response = web.post_form(f'/providers/{PROVIDER}', f'/providers/{PROVIDER}/webhook',
                         {'version': re.search(r'name="version" value="([0-9a-f]+)"',
                                               web.get(f'/providers/{PROVIDER}')).group(1)})

page = web.get(f'/providers/{PROVIDER}')
suite.check('the set up button refuses a site that isn\'t https, saying so', response.get_status() == 303
            and 'must be public and https' in page, json.dumps(response.get_status()))

suite.finish()
