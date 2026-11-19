"""
Setting up what the tests need through the web interface (email tokens and management API keys), and sending emails
through the SMTP server with an email token.
"""

import re
import smtplib
import ssl

from lib import stack
from lib.web import WebClient


# How long an API key's or email token's ID is: its prefix and 16 hex characters, at the start of the token.
TOKEN_ID_LENGTH = 19


class EmailToken:
    """
    An email token: one string, whose first 19 characters are its ID. It's the bearer token for the send endpoint,
    and the SMTP password, with the ID as the username.
    """

    def __init__(self, token: str) -> None:
        self.m_token = token

    def get_id(self) -> str:
        return self.m_token[:TOKEN_ID_LENGTH]

    # Accessors
    def get_token(self) -> str: return self.m_token


class SmtpReply:
    """The SMTP server's reply to an email: its code and text, e.g. 250 "OK: sent as <id>"."""

    def __init__(self, code: int, text: str) -> None:
        self.m_code = code
        self.m_text = text

    def is_sent(self) -> bool:
        """Whether the email was accepted and sent before the reply."""
        return self.m_code == 250 and 'sent as' in self.m_text

    def is_queued(self) -> bool:
        """Whether the email was accepted, and queued to be sent later."""
        return self.m_code == 250 and 'queued as' in self.m_text

    def get_message_id(self) -> str:
        """Get the service's ID for the email, the last word of an accepted email's reply."""
        return self.m_text.split()[-1]

    def __repr__(self) -> str:
        return f'{self.m_code} {self.m_text}'

    # Accessors
    def get_code(self) -> int: return self.m_code
    def get_text(self) -> str: return self.m_text


def create_api_key(client: WebClient, name: str, allowed_ips: str = '0.0.0.0/0\n::/0') -> str:
    """Create an API key (for the management API) in the web interface, and get it."""
    rows = {f'allowed_ips_{index}': value for index, value in enumerate(allowed_ips.split('\n'))}
    page = client.post_form('/api-keys', '/api-keys', {'name': name} | rows).get_body()
    return re.search(r'id="api-key" value="(ak_[0-9a-f]{80})"', page).group(1)


def create_email_token(client: WebClient, name: str, fields: dict | None = None) -> EmailToken:
    """
    Create an email token in the web interface, allowed to send from any IP address (given as 0.0.0.0/0 and ::/0),
    with no conditions on its senders, reply-tos or recipients, so it can send as anyone, to anyone.

    :param client: a logged in browser.

    :param name: the token's name.

    :param fields: any form fields to change or add, e.g. a rate limit:
    {'rate_limit_max_0': '2', 'rate_limit_unit_0': 'emails', 'rate_limit_period_0': '60'}.

    :return: the token.
    """
    form = {
        'name': name,
        'allowed_ips_0': '0.0.0.0/0',
        'allowed_ips_1': '::/0',
    }

    page = client.post_form('/email-tokens/new', '/email-tokens', form | (fields or {})).get_body()
    return EmailToken(re.search(r'id="email-token" value="(et_[0-9a-f]{80})"', page).group(1))


def create_tls_context() -> ssl.SSLContext:
    """A TLS context that accepts the test stack's self-signed certificate."""
    context = ssl.create_default_context()
    context.check_hostname = False
    context.verify_mode = ssl.CERT_NONE
    return context


def connect(key: EmailToken, timeout: float = 60) -> smtplib.SMTP_SSL:
    """Connect to the SMTP server on its implicit TLS port, and log in with an email token: its ID and the token."""
    connection = smtplib.SMTP_SSL('localhost', stack.SMTPS_PORT, context=create_tls_context(), timeout=timeout)
    connection.login(key.get_id(), key.get_token())
    return connection


def send_data(connection: smtplib.SMTP, data: str | bytes) -> SmtpReply:
    """Send an email's content (after MAIL FROM and RCPT TO), and get the reply, even if it's a refusal."""
    try:
        code, text = connection.data(data)
        reply = SmtpReply(code, text.decode())
    except smtplib.SMTPDataError as error:
        reply = SmtpReply(error.smtp_code, error.smtp_error.decode())

    return reply


def send_email(key: EmailToken, subject: str, recipient: str = 'a@b.test', timeout: float = 60) -> SmtpReply:
    """Send a short email from svc@example.org over SMTP, and get the server's reply."""
    connection = connect(key, timeout)
    connection.mail('svc@example.org')
    connection.rcpt(recipient)
    reply = send_data(connection, f'From: svc@example.org\r\nSubject: {subject}\r\n\r\nbody\r\n')
    connection.quit()
    return reply
