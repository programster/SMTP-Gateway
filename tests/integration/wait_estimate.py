"""
The estimate of whether an email will be sent soon enough to wait for: a key held back by its own rate limit, with a
big backlog, mustn't make another key's emails reply "queued".
"""

import time

from lib import stack
from lib.harness import Suite
from lib.service import create_email_token, send_email
from lib.web import WebClient

suite = Suite('Wait estimate')
web = WebClient.create_logged_in()

limited = create_email_token(web, 'backlogged', {
    'rate_limit_max_0': '1',
    'rate_limit_unit_0': 'emails',
    'rate_limit_period_0': '86400',
})

free = create_email_token(web, 'free')

# A few sends from the free key first, so the workers have a recent sending speed to go on.
for number in range(5):
    send_email(free, f'warm-up-{number}')

reply = send_email(limited, 'backlogged-0')
suite.check('the limited key\'s first email is sent', reply.is_sent(), reply)
reply = send_email(limited, 'backlogged-1')
suite.check('its second is queued', reply.is_queued(), reply)

# Copy its queued email 5,000 times, for a big backlog.
columns = stack.sql(
    "SELECT string_agg(quote_ident(column_name), ',') FROM information_schema.columns"
    " WHERE table_name = 'email_message' AND column_name <> 'id'"
)

stack.sql(
    f'INSERT INTO email_message (id, {columns}) SELECT uuidv7(), {columns} FROM email_message,'
    f" generate_series(1, 5000) WHERE email_token_id = '{limited.get_id()}' AND status = 'queued'"
)

backlogged = f"email_token_id = '{limited.get_id()}'"
backlog = stack.sql(f"SELECT count(*) FROM email_message WHERE status = 'queued' AND {backlogged}")
suite.check('it has a backlog', int(backlog) > 5000, backlog)
started = time.time()
reply = send_email(free, 'after the backlog')
took = time.time() - started
suite.check('the free key\'s email is still sent while it waits', reply.is_sent() and took < 5, (reply, took))
stack.sql(f"UPDATE email_message SET status = 'cancelled' WHERE status = 'queued' AND {backlogged}")

suite.finish()
