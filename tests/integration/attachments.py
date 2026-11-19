"""
Removing attachments from kept emails (EMAIL_ATTACHMENT_RETENTION_DAYS), which run-tests.sh runs with the content kept
for 30 days, and attachments either not kept (-1: removed as soon as the email is sent, or cancelled), or kept for 7
days (removed by scripts/remove-attachments.php, then deleted with the content by scripts/cleanup.php).
"""

import base64
import os
import re
import time
from email.message import EmailMessage

from lib import stack
from lib.harness import Suite
from lib.service import EmailToken, connect, create_email_token, create_api_key, send_data
from lib.web import WebClient, bearer, call_api

attachment_days = int(os.environ.get('EMAIL_ATTACHMENT_RETENTION_DAYS', '-1'))
suite = Suite('Attachments (' + ('not kept' if attachment_days == -1 else f'kept {attachment_days} days') + ')')
web = WebClient.create_logged_in()
auth = bearer(create_api_key(web, 'attachments'))
key = create_email_token(web, 'attachments')


def create_email(subject: str, is_big: bool) -> bytes:
    """An email with a text and HTML body, a PDF attachment, and optionally a 14 MB one as well."""
    email = EmailMessage()
    email['From'] = 'svc@example.org'
    email['To'] = 'a@b.test'
    email['Subject'] = subject
    email.set_content('plain body')
    email.add_alternative('<p>html body</p>', subtype='html')
    pdf = b'%PDF-1.4 ' + os.urandom(200000)
    email.add_attachment(pdf, maintype='application', subtype='pdf', filename='invoice.pdf')

    if is_big:
        big = os.urandom(14 * 1024 * 1024)
        email.add_attachment(big, maintype='application', subtype='octet-stream', filename='big.bin')

    return email.as_bytes()


def send(sending_key: EmailToken, data: bytes) -> str:
    """Send an email, and get the service's reply."""
    smtp = connect(sending_key)
    smtp.mail('svc@example.org')
    smtp.rcpt('a@b.test')
    reply = send_data(smtp, data)
    smtp.quit()
    return reply.get_text()


def load_message(message_id: str) -> dict:
    return call_api('GET', f'/messages/{message_id}', auth).get_json()['message']


def download(message_id: str) -> str:
    return call_api('GET', f'/messages/{message_id}/raw', auth).get_body()


def read_note(raw: str) -> str:
    """Get the text of the note that replaces the removed attachments."""
    encoded = re.search(r'attachments-removed.txt.*?\r?\n\r?\n([A-Za-z0-9+/=\r\n]+)', raw, re.S).group(1)
    return base64.b64decode(encoded).decode()


def backdate_sending(message_id: str, days: int) -> None:
    """Make out an email was sent some days ago."""
    stack.sql(f"UPDATE email_message SET sent_at = now() - interval '{days} days' WHERE id = '{message_id}'")


reply = send(key, create_email('with attachments', is_big=True))
suite.check('an email with a 14 MB attachment is sent', 'sent as' in reply, reply)
message_id = reply.split()[-1]
time.sleep(1)
message = load_message(message_id)
raw = download(message_id)

if attachment_days == -1:
    suite.check('both attachments are removed once it\'s sent', message['removedAttachments'] == 2, message)
    has_body = 'html body' in raw and 'plain body' in raw
    suite.check('the download keeps the body, without the attachments', has_body and len(raw) < 20000, len(raw))
    note = read_note(raw)
    is_listed = 'invoice.pdf' in note and 'big.bin (application/octet-stream, 14 MB)' in note
    suite.check('a note lists what was removed', is_listed, note)
    page = web.get(f'/messages/{message_id}')
    suite.check('the email\'s page says they were removed', 'Without its 2 attachments' in page)

    # A key limited to 1 email a day, so its next 2 are queued, then cancelled by revoking it.
    limited = create_email_token(web, 'limited', {
        'rate_limit_max_0': '1',
        'rate_limit_unit_0': 'emails',
        'rate_limit_period_0': '86400',
    })

    message_ids = [send(limited, create_email(f'queued {number}', is_big=False)).split()[-1] for number in range(3)]
    statuses = [load_message(queued_id)['status'] for queued_id in message_ids]
    is_held_back = statuses == ['sent', 'queued', 'queued']
    suite.check('a limited key\'s first email is sent, and the others queued', is_held_back, statuses)
    response = call_api('DELETE', f'/email-tokens/{limited.get_id()}', auth)
    cancelled = response.get_json().get('cancelledQueuedEmails')
    suite.check('revoking the key cancels them', cancelled == 2, response.get_body())
    counts = [load_message(queued_id)['removedAttachments'] for queued_id in message_ids[1:]]
    suite.check('cancelled emails keep their attachments until the script runs', counts == [0, 0], counts)
    result = stack.run_script('remove-attachments.php')
    counts = [load_message(queued_id)['removedAttachments'] for queued_id in message_ids[1:]]
    suite.check('remove-attachments.php removes them', counts == [1, 1], result.stdout + result.stderr)
    result = stack.run_script('remove-attachments.php')
    is_idle = result.returncode == 0 and result.stdout.strip() == ''
    suite.check('running it again has nothing to do', is_idle, result.stdout)

    # An email that's nothing but an attachment can't keep a body, so its content is deleted.
    whole = (
        b'From: svc@example.org\r\nTo: a@b.test\r\nSubject: whole\r\nMIME-Version: 1.0\r\nContent-Type: application/pdf'
        b'\r\nContent-Transfer-Encoding: base64\r\n\r\n' + base64.encodebytes(os.urandom(3000))
    )

    whole_id = send(key, whole).split()[-1]
    time.sleep(1)
    is_deleted = load_message(whole_id)['hasContent'] is False
    suite.check('an email that\'s just an attachment has its content deleted', is_deleted)
    warning = stack.sql("SELECT message FROM log WHERE message LIKE 'Couldn%' ORDER BY id DESC LIMIT 1")
    suite.check('with a warning logged', 'deleted its content' in warning, warning)
else:
    has_all = message['removedAttachments'] == 0 and 'invoice.pdf' in raw and len(raw) > 14 * 1024 * 1024
    suite.check('attachments are kept at first', has_all, (message, len(raw)))
    backdate_sending(message_id, attachment_days - 1)
    stack.run_script('remove-attachments.php')
    suite.check(f'and still after {attachment_days - 1} days', load_message(message_id)['removedAttachments'] == 0)
    backdate_sending(message_id, attachment_days + 1)
    result = stack.run_script('remove-attachments.php')
    message = load_message(message_id)
    raw = download(message_id)
    is_removed = message['removedAttachments'] == 2 and 'html body' in raw and len(raw) < 20000
    suite.check(f'removed after {attachment_days + 1} days, keeping the body', is_removed, (message, result.stdout))
    backdate_sending(message_id, 31)
    result = stack.run_script('cleanup.php')
    is_deleted = load_message(message_id)['hasContent'] is False
    suite.check('cleanup.php deletes the content after 30 days', is_deleted, result.stdout)
    is_described = f'attachments are removed {attachment_days} days after' in result.stdout
    suite.check('and its output describes the attachment retention', is_described, result.stdout)

suite.finish()
