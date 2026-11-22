"""
The Settings page: each section saved on its own (and kept in settings.yaml), the mistakes each refuses, conflicts
within a section, changes applying straight away (e.g. logging in with Google being switched off and on), and the
client secret never being shown.
"""

import re

from lib import stack
from lib.harness import Suite
from lib.service import create_api_key
from lib.web import Response, WebClient, bearer, call_api

suite = Suite('Settings')
web = WebClient.create_logged_in()


def get_version(page: str, path: str) -> str:
    """Get the version a section's form, on its page, is based on."""
    match = re.search(r'action="/admin/settings/' + path + r'".*?name="version" value="([0-9a-f]+)"', page, re.DOTALL)
    return match.group(1) if match else ''


# The sections shown on another section's page, rather than their own.
SHOWN_ON = {'sending': 'general'}


def save(path: str, form: dict, version: str = '') -> Response:
    """Save a section's form, on its page, based on its current version (or the one given)."""
    page_path = f"/admin/settings/{SHOWN_ON.get(path, path)}"
    page = web.get(page_path)
    fields = {'version': version or get_version(page, path)} | form
    return web.post_form(page_path, f'/admin/settings/{path}', fields)


def read_settings_file() -> str:
    return stack.read_app_file('/config/settings.yaml')


# Each section has a page of its own, in the Settings section of the side panel.
response = web.request('GET', '/admin/settings')
is_redirected = response.get_status() in (302, 303) and response.get_header('Location') == '/admin/settings/general'
suite.check('the settings go to their first page', is_redirected, response.get_status())
headings = {
    'general': 'General', 'security': 'Security', 'proxies': 'Proxies',
    'retention': 'Email retention', 'google-sso': 'SSO',
}

for path, heading in headings.items():
    page = web.get(f'/admin/settings/{path}')
    other_forms = [other for other in headings if other != path and f'action="/admin/settings/{other}"' in page]
    is_alone = f'>{heading}</h2>' in page and f'action="/admin/settings/{path}"' in page and other_forms == []
    suite.check(f'the {path} page has its section, and only its section', is_alone, other_forms)

page = web.get('/admin/settings/general')
has_sending = '>Sending</h2>' in page and 'action="/admin/settings/sending"' in page
suite.check('the sending settings are on the General page, in a form of their own', has_sending)
response = web.request('GET', '/admin/settings/sending')
is_redirected = response.get_header('Location') == '/admin/settings/general#sending'
suite.check('and their old page goes there', is_redirected, response.get_header('Location'))

page = web.get('/admin/settings/general')
is_marked = 'href="/admin/settings/general" aria-current="page"' in page
suite.check('the side panel marks the page', is_marked)
open_section = r'<details class="nav-section" open>\s*<summary>(?:(?!</summary>).)*<span>Settings</span>'
is_open = page.count('<details class="nav-section" open>') == 1 and re.search(open_section, page, re.S) is not None
suite.check('with only the Settings section open', is_open)
suite.check('which links to Server resources and Providers', all(
    f'href="{path}"' in page for path in ['/admin/settings/server-resources', '/providers']))
suite.check('the client secret isn\'t shown', 'test-secret' not in web.get('/admin/settings/google-sso'))
suite.check('an unknown settings page is a 404', web.request('GET', '/admin/settings/nonsense').get_status() == 404)
is_missing = web.request('GET', '/admin/settings/providers').get_status() == 404
suite.check('as are the providers, which have a page of their own', is_missing)

# General.
uk_format = {'date_order': 'dmy', 'date_separator': '-', 'time_position': 'before'}
response = save('general', {'timezone': 'Asia/Tokyo'} | uk_format)
suite.check('the time zone is saved', response.get_status() == 303 and 'timezone: "Asia/Tokyo"' in read_settings_file())
suite.check('and shown as chosen', '<option value="Asia/Tokyo" selected>' in web.get('/admin/settings/general'))
response = save('general', {'timezone': 'Mars/Olympus'} | uk_format)
is_refused = response.get_status() == 400 and 'Mars/Olympus' in response.get_body()
suite.check('a time zone that doesn\'t exist is refused', is_refused)
us_format = {'date_order': 'mdy', 'date_separator': '/', 'time_position': 'after'}
response = save('general', {'timezone': 'Europe/London'} | us_format)
saved = read_settings_file()
is_saved = 'date_order: "mdy"' in saved and 'date_separator: "/"' in saved and 'time_position: "after"' in saved
suite.check('how dates and times are written is saved', response.get_status() == 303 and is_saved, saved)
page = web.get('/admin/settings/general')
suite.check('and used', re.search(r'<strong>\d\d/\d\d/\d{4} \d\d:\d\d:\d\d</strong>', page) is not None)
response = save('general', {'timezone': 'Europe/London', 'date_order': 'nonsense', 'date_separator': '/'})
suite.check('a made up format is refused', response.get_status() == 400 and 'from the lists' in response.get_body())
save('general', {'timezone': 'Asia/Tokyo'} | uk_format)

# A section saved from an old version conflicts, but saving another section in between doesn't.
old_version = get_version(web.get('/admin/settings/general'), 'general')
save('sending', {'system_email_from': 'noreply@example.org', 'sync_wait_seconds': '5'})
response = save('general', {'timezone': 'Europe/London'} | uk_format, old_version)
suite.check('saving another section in between doesn\'t conflict', response.get_status() == 303)
response = save('general', {'timezone': 'Europe/Paris'} | uk_format, old_version)
is_conflict = response.get_status() == 400 and 'changed since you loaded' in response.get_body()
suite.check('saving a section from an old version is refused', is_conflict, response.get_status())

# Security, and proxies, which have a page (and section of settings.yaml) of their own.
response = save('security', {'require_mfa': '', 'password_min_length': '12'})
saved = read_settings_file()
suite.check('whether a second factor is required is saved', response.get_status() == 303
            and 'require_mfa: false' in saved and 'trusted_proxies' not in saved.split('proxies:')[0], saved)
save('security', {'require_mfa': '1', 'password_min_length': '12'})

# The password rules, checked whenever a password is set.
rules = {'require_mfa': '1', 'password_min_length': '14', 'password_requires_capital': '1',
         'password_requires_number': '1', 'password_requires_symbol': '1'}
response = save('security', rules)
saved = read_settings_file()
is_saved = 'password_min_length: 14' in saved and 'password_requires_symbol: true' in saved
suite.check('the password rules are saved', response.get_status() == 303 and is_saved, saved)
page = web.get('/admin/users')
suite.check('and described on the forms that set a password', 'At least 14 characters, with a capital letter, a number'
            ' and a symbol.' in page and 'minlength="14"' in page)
weak = {'email': 'weak@example.org', 'password_source': 'set', 'password': 'justlowercaseletters',
        'confirm_password': 'justlowercaseletters'}
response = web.post_form('/admin/users', '/admin/users', weak)
is_refused = response.get_status() == 400 and 'needs a capital letter, a number and a symbol' in response.get_body()
suite.check('a password missing any of them is refused, saying what it\'s missing', is_refused, response.get_status())
response = save('security', {'require_mfa': '1', 'password_min_length': '7'})
is_refused = response.get_status() == 400 and 'from 8 to 64' in response.get_body()
suite.check('a minimum length below 8 is refused', is_refused)
save('security', {'require_mfa': '1', 'password_min_length': '12'})
page = web.get('/admin/settings/security')
suite.check('the security page has no proxies on it', 'trusted_proxies' not in page and 'behind_cloudflare' not in page)
response = save('proxies', {'trusted_proxies_0': '0.0.0.0/0'})
is_refused = response.get_status() == 400 and 'any client could fake' in response.get_body()
suite.check('trusting every address is refused', is_refused, response.get_status())
suite.check('and the form keeps what was entered', 'name="trusted_proxies_0" value="0.0.0.0/0"' in response.get_body())
response = save('proxies', {'trusted_proxies_0': '10.0.0.0/8', 'trusted_proxies_1': '10.0.0.300'})
is_refused = response.get_status() == 400 and 'Trusted proxies, row 2' in response.get_body()
suite.check('a trusted proxy that isn\'t an address is refused, naming its row', is_refused, response.get_status())
proxies = {'trusted_proxies_0': '10.0.0.0/8', 'trusted_proxies_1': '172.16.0.0/12'}
response = save('proxies', proxies)
saved = read_settings_file()
is_saved = response.get_status() == 303 and 'trusted_proxies: ["10.0.0.0/8", "172.16.0.0/12"]' in saved
suite.check('trusted proxies are saved, in the proxies section', is_saved and '\nproxies:\n' in saved, saved)
page = web.get('/admin/settings/proxies')
has_rows = 'name="trusted_proxies_1" value="172.16.0.0/12"' in page and 'data-role="add-row"' in page
suite.check('and shown as rows, with an Add button', has_rows)
suite.check('with the rows\' script', 'src="/js/SettingsPage.js"' in page)
# Behind Cloudflare: its ranges are stored in the database, not settings.yaml, and CF-Connecting-IP is only believed
# from them. Ranges covering every address stand in for Cloudflare's here, so saving doesn't try to fetch them (the
# test stack may not reach cloudflare.com), and the test client counts as Cloudflare.
stack.sql(
    "INSERT INTO cloudflare_network (network, fetched_at) VALUES ('0.0.0.0/1', unix_time()),"
    " ('128.0.0.0/1', unix_time()),"
    " ('::/1', unix_time()), ('8000::/1', unix_time())"
)

restricted = bearer(create_api_key(web, 'behind cloudflare', '10.99.99.0/24'))
from_cloudflare = {'CF-Connecting-IP': '10.99.99.7'}
response = call_api('GET', '/email-tokens', restricted, headers=from_cloudflare)
suite.check('not behind Cloudflare, CF-Connecting-IP is ignored', response.get_status() == 403, response.get_status())
response = save('proxies', {'behind_cloudflare': '1', 'trusted_proxies_0': ''})
suite.check('being behind Cloudflare is saved', response.get_status() == 303
            and 'behind_cloudflare: true' in read_settings_file() and 'cloudflare_network' not in read_settings_file())
response = call_api('GET', '/email-tokens', restricted, headers=from_cloudflare)
suite.check('then CF-Connecting-IP from Cloudflare is believed', response.get_status() == 200, response.get_status())
response = call_api('GET', '/email-tokens', restricted)
suite.check('and without it, the request is Cloudflare\'s own address', response.get_status() == 403)
page = web.get('/admin/settings/proxies')
suite.check('the page says how many of Cloudflare\'s ranges are stored', "Cloudflare's 4 address ranges" in page
            and 'Fetch them now' in page)
suite.check('and says not to add them to the trusted proxies', "don't add them to" in page)
save('proxies', {'trusted_proxies_0': ''})
suite.check('it\'s turned off again', 'behind_cloudflare: false' in read_settings_file())
response = call_api('GET', '/email-tokens', restricted, headers=from_cloudflare)
suite.check('and CF-Connecting-IP is ignored again', response.get_status() == 403, response.get_status())
stack.sql('DELETE FROM cloudflare_network')

page = web.get('/admin/settings/google-sso')
has_help = 'data-dialog="google-mfa-help"' in page and '<dialog id="google-mfa-help"' in page
has_short_choices = '<strong>Never</strong>' in page
suite.check('Google\'s choices are short, with a help dialog explaining them', has_help and has_short_choices)
save('proxies', {'trusted_proxies_0': ''})

# Sending.
response = save('sending', {'system_email_from': 'noreply@example.org', 'sync_wait_seconds': '61'})
suite.check('a wait of more than 60 seconds is refused', response.get_status() == 400)
response = save('sending', {'system_email_from': 'not-an-address', 'sync_wait_seconds': '5'})
suite.check('an invalid address is refused', response.get_status() == 400)
response = save('sending', {'system_email_from': 'noreply@example.org', 'sync_wait_seconds': '7'})
suite.check('the wait is saved', response.get_status() == 303 and 'sync_wait_seconds: 7' in read_settings_file())
save('sending', {'system_email_from': 'noreply@example.org', 'sync_wait_seconds': '5'})

# Retention: each part has to fit within the one before it.
retention = {
    'email_record_days': '90',
    'content_mode': 'kept_for_days',
    'content_days': '91',
    'attachment_mode': 'not_kept',
    'attachment_days': '30',
    'log_days': '90',
}

response = save('retention', retention)
is_refused = response.get_status() == 400 and 'be kept for longer' in response.get_body()
suite.check('content kept longer than the record is refused', is_refused, response.get_body()[-2000:])
response = save('retention', retention | {'content_days': '30', 'log_days': '30'})
saved = read_settings_file()
is_saved = response.get_status() == 303 and 'email_content_days: 30' in saved and 'log_days: 30' in saved
suite.check('the retention settings are saved', is_saved, saved)
save('retention', retention | {'content_mode': 'not_kept', 'log_days': '90'})

# Logging in with Google: switched off and on, applying straight away, with the secret kept when it's left empty.
google = {'enabled': '1', 'client_id': stack.GOOGLE_SSO_CLIENT_ID, 'client_secret': '', 'bypass_mfa': 'never'}
response = save('google-sso', google | {'client_id': ''})
suite.check('switching Google on without a client ID is refused', response.get_status() == 400)
response = save('google-sso', google | {'bypass_mfa': 'sometimes'})
suite.check('an unknown second factor choice is refused', response.get_status() == 400)
del google['enabled']
save('google-sso', google)
is_hidden = 'href="/login/google"' not in WebClient().get('/login')
suite.check('switching Google off hides its button straight away', is_hidden)
response = save('google-sso', google | {'enabled': '1'})
suite.check('switching it back on shows it again', 'href="/login/google"' in WebClient().get('/login'))
suite.check('and leaving the secret empty keeps it', 'client_secret: "test-secret"' in read_settings_file())

# Each save is logged, with who made it: by their account's ID, never their email address.
admin_id = stack.sql(f"SELECT id FROM admin_user WHERE email = '{stack.ADMIN_EMAIL}'")
logged = stack.sql(
    f"SELECT count(*) FROM log WHERE message = 'Settings changed' AND context->>'by_user_id' = '{admin_id}'"
)

suite.check('each save is logged with who made it', int(logged) >= 8, logged)
suite.finish()
