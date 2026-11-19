"""
The SMTP server's limit on connections at once, which run-tests.sh starts this stack with set to 3
(SMTP_MAX_CONNECTIONS): connections beyond it wait their turn, rather than being refused, and are handled as soon as
one finishes, and it's logged as a warning when they have to wait.
"""

import threading
import time

from lib import stack
from lib.harness import Suite
from lib.service import connect, create_email_token, send_email
from lib.web import WebClient

suite = Suite('SMTP connections')
web = WebClient.create_logged_in()

# A 4th connection waits until one of the 3 finishes, rather than being refused.
token = create_email_token(web, 'connection limit')
held = [connect(token) for _ in range(3)]
waiting = {}


def send_while_full() -> None:
    """Send an email over a 4th connection, recording its reply, or why it failed."""
    try:
        waiting['reply'] = send_email(token, 'waited its turn', timeout=30)
    except Exception as error:  # noqa: BLE001 - a refusal is what this checks for.
        waiting['error'] = repr(error)


fourth = threading.Thread(target=send_while_full)
fourth.start()
time.sleep(2)
suite.check('a connection beyond SMTP_MAX_CONNECTIONS waits, rather than being refused', fourth.is_alive(), waiting)
held.pop().quit()
fourth.join(30)
reply = waiting.get('reply')
is_sent = reply is not None and (reply.is_sent() or reply.is_queued())
suite.check('and is handled once one finishes', is_sent, waiting)

for connection in held:
    connection.quit()



def has_waiting_warning() -> bool:
    """Whether the warning that connections had to wait has been logged."""
    return stack.sql("SELECT count(*) FROM log WHERE message LIKE 'Every SMTP connection was in use%'") != '0'


has_warning = stack.wait_until(has_waiting_warning, 10)

suite.check('connections having to wait is logged as a warning', has_warning)

suite.finish()
