"""
The load test: 30 SMTP connections at once (the default SMTP_MAX_CONNECTIONS), each sending emails, alongside 16 API
clients, while sampling the containers' memory each second. It reports how long it took and the memory used, and
fails if any request did.

LOAD_TEST_EMAILS_PER_CONNECTION sets how many emails each connection sends (10 by default, so 300 in all).
"""

import os
import subprocess
import threading
import time

from lib import stack
from lib.harness import Suite
from lib.service import connect, create_email_token, create_api_key, send_data
from lib.web import WebClient, bearer, call_api

EMAILS_PER_CONNECTION = int(os.environ.get('LOAD_TEST_EMAILS_PER_CONNECTION', '10'))
CONNECTIONS = 30
API_CLIENTS = 16
API_ROUNDS = 20

suite = Suite('Load')
web = WebClient.create_logged_in()
key = create_email_token(web, 'load')
auth = bearer(create_api_key(web, 'load'))
memory_samples = {}
replies = []
errors = []
is_sampling = True
lock = threading.Lock()


def read_memory() -> dict:
    """Get the app and database containers' memory use, in MiB, by container name."""
    containers = [stack.get_container('app'), stack.get_container('db')]
    command = ['docker', 'stats', '--no-stream', '--format', '{{.Name}} {{.MemUsage}}'] + containers
    output = subprocess.run(command, capture_output=True, text=True).stdout
    usage = {}

    for line in output.strip().splitlines():
        name, used = line.split(' ', 1)
        amount = used.split('/')[0].strip()
        mebibytes = float(amount[:-3]) if amount.endswith('iB') else 0.0
        usage[name] = mebibytes * 1024 if amount.endswith('GiB') else mebibytes

    return usage


def sample_memory() -> None:
    while is_sampling:
        for name, mebibytes in read_memory().items():
            memory_samples.setdefault(name, []).append(mebibytes)


def send_emails(connection_number: int) -> None:
    try:
        smtp = connect(key, timeout=120)

        for number in range(EMAILS_PER_CONNECTION):
            smtp.mail('svc@example.org')
            smtp.rcpt(f'load{connection_number}-{number}@x.test')
            subject = f'load {connection_number}-{number}'
            body = 'lorem ipsum ' * 400
            reply = send_data(smtp, f'From: svc@example.org\r\nSubject: {subject}\r\n\r\n{body}\r\n')

            with lock:
                replies.append(reply)

        smtp.quit()
    except Exception as error:
        with lock:
            errors.append(repr(error))


def call_the_api() -> None:
    for _ in range(API_ROUNDS):
        for path in ['/stats', '/email-tokens', '/messages?limit=50']:
            status = call_api('GET', path, auth).get_status()

            if status != 200:
                with lock:
                    errors.append(f'{path}: HTTP {status}')


print('idle:', read_memory())
sampler = threading.Thread(target=sample_memory)
sampler.start()
threads = [threading.Thread(target=send_emails, args=(number,)) for number in range(CONNECTIONS)]
threads += [threading.Thread(target=call_the_api) for _ in range(API_CLIENTS)]
started = time.time()

for thread in threads:
    thread.start()

for thread in threads:
    thread.join()

took = time.time() - started
is_sampling = False
sampler.join()
email_count = CONNECTIONS * EMAILS_PER_CONNECTION
request_count = API_CLIENTS * API_ROUNDS * 3
sent = len([reply for reply in replies if reply.is_sent()])
queued = len([reply for reply in replies if reply.is_queued()])
print(f'{email_count} emails over {CONNECTIONS} connections, and {request_count} API requests, in {took:.1f}s')
print(f'{sent} told sent, {queued} told queued')

for name, samples in memory_samples.items():
    median = sorted(samples)[len(samples) // 2]
    print(f'{name}: peak {max(samples):.0f} MiB, median {median:.0f} MiB, over {len(samples)} samples')

suite.check('no request failed', errors == [], errors[:3])
suite.check('every email accepted', sent + queued == email_count, (sent, queued))

suite.finish()
