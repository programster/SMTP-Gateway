"""
Rate limits: an email limit spacing a burst out over time, recipient limits (splitting an email at RCPT TO, refusing
one through the API, and failing a queued email the limits can no longer allow), and the global limits page.
"""

from lib import stack
from lib.harness import Suite
from lib.service import connect, create_email_token, send_data
from lib.web import WebClient, bearer, call_api

suite = Suite('Rate limits')
web = WebClient.create_logged_in()


def find_most_in_any_second(subject_pattern: str) -> tuple[int, int]:
    """Get how many emails with matching subjects were sent, and the most that were sent within any one second."""
    query = f"SELECT extract(epoch FROM dispatched_at) FROM email_message WHERE subject LIKE '{subject_pattern}'"
    times = [float(value) for value in stack.sql(query).split()]
    most = 0

    for start in times:
        most = max(most, len([other for other in times if start <= other < start + 1.0]))

    return len(times), most


def count_sent(subject_pattern: str) -> int:
    """Count the sent emails with matching subjects."""
    query = f"SELECT count(*) FROM email_message WHERE subject LIKE '{subject_pattern}' AND status = 'sent'"
    return int(stack.sql(query))


# An email limit of 2 a second: 8 emails sent one after another over one connection are spaced out.
key = create_email_token(web, 'emails limit', {
    'rate_limit_max_0': '2',
    'rate_limit_unit_0': 'emails',
    'rate_limit_period_0': '1',
})

smtp = connect(key)
replies = []

for number in range(8):
    smtp.mail('svc@example.org')
    smtp.rcpt(f'user{number}@anywhere.test')
    replies.append(send_data(smtp, f'From: svc@example.org\r\nSubject: burst-{number}\r\n\r\nbody\r\n'))

smtp.quit()
# Within SYNC_WAIT_SECONDS, the client waits for each to be sent, so they're spaced out as they're told "sent".
suite.check('every email accepted', all(reply.get_code() == 250 for reply in replies), replies)


def are_all_sent() -> bool:
    return count_sent('burst-%') == 8


suite.check('all 8 sent', stack.wait_until(are_all_sent, 30), count_sent('burst-%'))
count, most = find_most_in_any_second('burst-%')
suite.check('never more than 2 in any second', count == 8 and most <= 2, (count, most))

# A recipient limit of 3 a minute.
key = create_email_token(web, 'recipients limit', {
    'allowed_senders_0': '*@example.org',
    'rate_limit_max_0': '3',
    'rate_limit_unit_0': 'recipients',
    'rate_limit_period_0': '60',
})

suite.check('key page shows the recipient limit', '3 recipients per minute' in web.get(f'/email-tokens/{key.get_id()}'))
smtp = connect(key)
smtp.mail('svc@example.org')
codes = [smtp.rcpt(f'r{number}@x.test')[0] for number in range(5)]
suite.check('4th recipient refused with 452, to split the email', codes == [250, 250, 250, 452, 452], codes)
reply = send_data(smtp, 'From: svc@example.org\r\nSubject: three\r\n\r\nhi\r\n')
suite.check('email to the first 3 sent', reply.is_sent(), reply)
smtp.mail('svc@example.org')

for number in range(3):
    smtp.rcpt(f'q{number}@x.test')

reply = send_data(smtp, 'From: svc@example.org\r\nSubject: three more\r\n\r\nhi\r\n')
suite.check('next 3 recipients queued, as the limit is used up', reply.is_queued(), reply)
queued_id = reply.get_message_id()
smtp.quit()

recipients = [{'email': f't{number}@x.test'} for number in range(4)]
email = {'from': {'email': 'svc@example.org'}, 'to': recipients, 'subject': 'big', 'text': 'hi'}
response = call_api('POST', '/send', bearer(key.get_token()), email)
error = response.get_json()['error'] if response.get_status() == 400 else {}
is_refused = error.get('code') == 23 and 'several emails' in error.get('message', '')
suite.check('API email over the recipient limit refused', is_refused, response.get_body())

# Lowering the global limit below the queued email's 3 recipients means it can never be sent, so it's failed.
response = web.post_form('/rate-limits', '/rate-limits', {
    'rate_limit_max_0': '2',
    'rate_limit_unit_0': 'recipients',
    'rate_limit_period_0': '86400',
})

suite.check('global limits page shows the new limit', '2 recipients per day' in web.get('/rate-limits'))


def is_failed() -> bool:
    return stack.sql(f"SELECT status FROM email_message WHERE id = '{queued_id}'") == 'failed'


suite.check('a queued email the limits can no longer allow is failed', stack.wait_until(is_failed, 10))
page = web.get(f'/messages/{queued_id}')
suite.check('its page says why', 'more than the rate limits now allow' in page)
web.post_form('/rate-limits', '/rate-limits', {})
suite.check('global limits can be cleared', 'No limit' in web.get('/rate-limits'))

# The rows of a form, added one at a time.
page = web.get('/rate-limits')
has_one_row = page.count('name="rate_limit_max_') == 2 and 'data-role="add-row"' in page
suite.check('the global limits start with one row (and its template), and an Add button', has_one_row)
periods = [1, 60, 3600, 86400, 604800, 2592000]
rows = {}

for index, period in enumerate(periods):
    rows |= {f'rate_limit_max_{index}': str(1000 + index), f'rate_limit_unit_{index}': 'emails',
             f'rate_limit_period_{index}': str(period)}

rows |= {'rate_limit_max_6': '5000', 'rate_limit_unit_6': 'recipients', 'rate_limit_period_6': '3600'}
response = web.post_form('/rate-limits', '/rate-limits', rows)
page = web.get('/rate-limits')
is_saved = response.get_status() == 303 and page.count('name="rate_limit_max_') == 8
suite.check('more than six limits can be saved', is_saved, response.get_status())
web.post_form('/rate-limits', '/rate-limits', {})

page = web.get('/email-tokens/new')
has_rows = all(f'name="{prefix}_0"' in page for prefix in ['allowed_ips', 'allowed_senders', 'allowed_reply_tos',
                                                            'allowed_recipients'])
suite.check('the email token form\'s lists are rows', has_rows and 'data-check="ip-network"' in page)
has_command = 'id="api-command" data-site-url="http' in page and '/api/v1/email-tokens</pre>' in page
suite.check('and the curl command that would create it through the API is below it', has_command)
form = {'name': 'bad network', 'allowed_ips_0': '10.0.0.0/8', 'allowed_ips_1': '10.0.0.300'}
response = web.post_form('/email-tokens/new', '/email-tokens', form)
is_refused = response.get_status() == 400 and 'Allowed IP addresses, row 2' in response.get_body()
suite.check('an IP address row that isn\'t one is refused, saying which', is_refused, response.get_status())
suite.check('keeping the rows entered', 'value="10.0.0.300"' in response.get_body())

suite.finish()
