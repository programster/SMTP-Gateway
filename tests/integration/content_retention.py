"""
Whether an email's content is kept once it's sent (EMAIL_CONTENT_RETENTION_DAYS), and so whether it can be downloaded
from the web interface and the API, and read on the email's page (its HTML, in a sandboxed frame, and plain text).
run-tests.sh runs this with the content not kept (-1, the default), and kept.
"""

import os

from lib.harness import Suite
from lib.service import connect, create_email_token, create_api_key, send_data
from lib.web import WebClient, bearer, call_api

is_kept = os.environ.get('EMAIL_CONTENT_RETENTION_DAYS', '-1') != '-1'
suite = Suite('Content retention (' + ('kept' if is_kept else 'not kept') + ')')
web = WebClient.create_logged_in()
key = create_email_token(web, 'retention')
auth = bearer(create_api_key(web, 'retention'))

smtp = connect(key)
smtp.mail('svc@example.org')
smtp.rcpt('a@b.test')
reply = send_data(smtp, 'From: svc@example.org\r\nSubject: retention\r\nX-Custom: kept-header\r\n\r\nthe body\r\n')
smtp.quit()
suite.check('email sent', reply.is_sent(), reply)
message_id = reply.get_message_id()
message = call_api('GET', f'/messages/{message_id}', auth).get_json()['message']
suite.check('the API says whether it has its content', message['hasContent'] is is_kept, message)
web_download = web.request('GET', f'/messages/{message_id}/raw')
api_download = call_api('GET', f'/messages/{message_id}/raw', auth)

if is_kept:
    is_whole = 'X-Custom: kept-header' in web_download.get_body() and 'the body' in web_download.get_body()
    suite.check('it can be downloaded from the web interface', web_download.get_status() == 200 and is_whole)
    is_whole = 'X-Custom: kept-header' in api_download.get_body() and 'the body' in api_download.get_body()
    suite.check('and the API', api_download.get_status() == 200 and is_whole, api_download.get_status())
else:
    suite.check('it can\'t be downloaded from the web interface', web_download.get_status() == 404)
    suite.check('or the API', api_download.get_status() == 404, api_download.get_body())


# Reading it on its page: a tab each for its HTML and plain text.
smtp = connect(key)
smtp.mail('svc@example.org')
smtp.rcpt('a@b.test')

both = (
    'From: svc@example.org\r\nSubject: both\r\nMIME-Version: 1.0\r\n'
    'Content-Type: multipart/alternative; boundary="b"\r\n\r\n'
    '--b\r\nContent-Type: text/plain\r\n\r\nplain <version>\r\n'
    '--b\r\nContent-Type: text/html\r\n\r\n<html><head></head><body><p>html version</p>'
    '<img src="https://tracker.example/open.png"><script>alert(1)</script></body></html>\r\n--b--\r\n'
)

reply = send_data(smtp, both)
smtp.quit()
both_id = reply.get_message_id()
page = web.get(f'/messages/{both_id}')
html = web.request('GET', f'/messages/{both_id}/html')
csp = html.get_header('Content-Security-Policy')

if is_kept:
    suite.check('its page has tabs for its HTML and plain text', 'role="tablist"' in page and '>Plain text</button>'
                in page)
    suite.check('the plain text is shown, escaped', 'plain &lt;version&gt;' in page)
    has_frame = f'src="/messages/{both_id}/html"' in page and 'sandbox="allow-popups' in page
    suite.check('the HTML is in a sandboxed frame', has_frame)
    suite.check('the .eml can still be downloaded', f'/messages/{both_id}/raw' in page)
    suite.check('the frame shows the HTML', html.get_status() == 200 and '<p>html version</p>' in html.get_body())
    suite.check('opening its links in a new tab', '<head><base target="_blank">' in html.get_body())
    is_sandboxed = 'sandbox' in csp and "default-src 'none'" in csp and 'script-src' not in csp
    suite.check('with no scripts, and nothing loaded from anywhere (not even remote images)', is_sandboxed, csp)
    is_framed = html.get_header('X-Frame-Options') == 'SAMEORIGIN' and "frame-ancestors 'self'" in csp
    suite.check('and only this site can frame it', is_framed, html.get_headers())
    other = web.request('GET', f'/messages/{both_id}')
    suite.check('while other pages still can\'t be framed', other.get_header('X-Frame-Options') == 'DENY')
else:
    suite.check('without its content, its page has no tabs', 'role="tablist"' not in page and 'Not available' in page)
    suite.check('and its HTML can\'t be fetched', html.get_status() == 404, html.get_status())

suite.finish()
