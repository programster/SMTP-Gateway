"""
Delivery attempts: each one is recorded, and counts towards the rate limits of the provider it went to, even when it
failed and the email moved on to another provider.
"""

from lib import stack
from lib.harness import Suite
from lib.service import create_email_token, create_api_key, send_email
from lib.web import WebClient, bearer, call_api

suite = Suite('Delivery attempts')
web = WebClient.create_logged_in()
key = create_email_token(web, 'attempts')
auth = bearer(create_api_key(web, 'attempts'))


def get_room(provider: str) -> int:
    """How many more emails a limit of 5 every 5 minutes would let through a provider now."""
    limits = 'new RateLimitSet(new RateLimit(RateLimitUnit::EMAILS, 5, 300))'
    scope = f"RateLimitScope::forProvider('{provider}')"
    return int(stack.run_php(f'echo RateLimiter::getRoom({limits}, {scope})->getEmails();'))


def list_attempts(message_id: str) -> list[str]:
    """Get an email's attempts, oldest first, as "provider:outcome"."""
    query = (
        "SELECT provider || ':' || outcome FROM email_attempt"
        f" WHERE email_message_id = '{message_id}' ORDER BY dispatched_at"
    )

    return stack.sql(query).split('\n')


# Earlier suites' attempts are moved out of the way, so each provider's room starts full.
stack.sql("UPDATE email_attempt SET dispatched_at = dispatched_at - interval '1 hour'")
rooms = (get_room(stack.PRIMARY_MAILPIT), get_room(stack.SECONDARY_MAILPIT))
suite.check('rooms start full', rooms == (5, 5), rooms)
message_id = send_email(key, 'attempt-normal').get_message_id()
attempts = list_attempts(message_id)
suite.check('a normal send records one attempt', attempts == ['mailpit:sent'], attempts)

stack.stop_services('mailpit')

try:
    message_id = send_email(key, 'attempt-failover', timeout=90).get_message_id()
    attempts = list_attempts(message_id)
    expected = ['mailpit:unavailable', 'mailpit-secondary:sent']
    suite.check('failing over records both attempts', attempts == expected, attempts)
    outcome = stack.sql(f"SELECT provider || ':' || status FROM email_message WHERE id = '{message_id}'")
    suite.check('the email was sent by the secondary', outcome == 'mailpit-secondary:sent', outcome)
    room = get_room(stack.PRIMARY_MAILPIT)
    suite.check('the failed attempt still counts against the primary', room == 3, room)
    room = get_room(stack.SECONDARY_MAILPIT)
    suite.check('the secondary counts its one', room == 4, room)
    query = f"SELECT error FROM email_attempt WHERE email_message_id = '{message_id}' AND outcome = 'unavailable'"
    error = stack.sql(query)
    suite.check('the failed attempt has its error', error != '', error)
    response = web.request('GET', f'/messages/{message_id}')
    page = response.get_body()
    is_listed = '<h2>Attempts</h2>' in page and 'unreachable' in page and 'accepted' in page
    suite.check('the email\'s page lists the attempts', response.get_status() == 200 and is_listed)
    listed = call_api('GET', f'/messages/{message_id}', auth).get_json()['deliveryAttempts']
    summary = [(attempt['provider'], attempt['outcome'], attempt['error'] is None) for attempt in listed]
    expected = [('mailpit', 'unavailable', False), ('mailpit-secondary', 'sent', True)]
    suite.check('the API lists the attempts', summary == expected, listed)
    suite.check('the providers page counts attempts', 'Attempts (minute / hour' in web.get('/providers'))
finally:
    stack.start_services('mailpit')
    stack.sql('DELETE FROM provider_status')

suite.finish()
