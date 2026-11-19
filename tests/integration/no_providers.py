"""
A fresh install, which has no settings.yaml (run-tests.sh points SETTINGS_FILE at a file that doesn't exist): the
dashboard and providers page say there are no providers, an email sent before one is added is queued rather than
refused, and once the first provider is added through the API, the queued email is sent.
"""

from lib import stack
from lib.harness import Suite
from lib.service import create_email_token, create_api_key, send_email
from lib.web import WebClient, bearer, call_api

suite = Suite('No providers')
web = WebClient.create_logged_in()
auth = bearer(create_api_key(web, 'no-providers'))
key = create_email_token(web, 'no-providers')

dashboard = web.get('/')
suite.check('the dashboard says there are no providers yet', 'No providers have been set up yet' in dashboard)
page = web.get('/providers')
is_first = 'Add your first one below' in page and 'api_key: &quot;${SENDGRID_API_KEY}&quot;' in page
suite.check('the providers page asks for the first one, with an example', is_first)
suite.check('the settings page has the defaults', '<option value="UTC" selected>' in web.get('/admin/settings/general'))

# An email sent before there are any providers is queued, rather than refused.
reply = send_email(key, 'sent before any provider', 'x@y.test')
suite.check('an email sent with no providers is queued', reply.is_queued(), reply)
status = stack.sql("SELECT status FROM email_message WHERE subject = 'sent before any provider'")
suite.check('and stays queued', status == 'queued', status)

# The API gives no configuration, and the version to add the first one with.
response = call_api('GET', '/providers', auth)
body = response.get_json()
etag = response.get_header('ETag')
is_empty = response.get_status() == 200 and body['config'] is None and etag == f'"{body["version"]}"'
suite.check('GET /providers gives no configuration, and the version to add one with', is_empty, body)
response = call_api('GET', '/providers/export', auth)
suite.check('and there\'s nothing to export', response.get_status() == 404 and response.get_error_code() == 31)

mailpit = {
    'name': 'mailpit',
    'type': 'custom',
    'enabled': True,
    'host': 'mailpit',
    'port': 1025,
    'security': 'none',
    'username': '',
    'password': '',
    'rate_limits': [],
}

config = {'mode': 'overflow', 'providers': [mailpit]}
response = call_api('PUT', '/providers', auth, config, {'If-Match': etag})
suite.check('the first provider can be added', response.get_status() == 200, response.get_body())
suite.check('which creates settings.yaml', 'name: "mailpit"' in stack.read_app_file(stack.SETTINGS_FILE))


def is_queued_email_sent() -> bool:
    return stack.sql("SELECT status FROM email_message WHERE subject = 'sent before any provider'") == 'sent'


# The queued email is then sent.
suite.check('the email queued before it is then sent', stack.wait_until(is_queued_email_sent, 30))
found = stack.search_mailpit('subject:"sent before any provider"')
suite.check('through the new provider', len(found.get('messages', [])) == 1, found)
dashboard = web.get('/')
suite.check('and the dashboard no longer says there are none', 'No providers have been set up yet' not in dashboard)
suite.finish()
