"""
The provider form on the Providers page: adding a provider of each type (disabled, so nothing is sent through them),
the mistakes it refuses (keeping what was entered), secrets entered or read from an environment variable and never
shown again, the enabled and disabled providers' tables, a provider's own page, editing (a secret left blank is kept),
renaming, putting the enabled providers in order, the mode's radio group, removing (but not the last enabled
provider), a change based on an old version, names the web interface uses, and the YAML editor still being there.
"""

import re

from lib import stack
from lib.harness import Suite
from lib.web import Response, WebClient, find_csrf_token

suite = Suite('Provider form')
web = WebClient.create_logged_in()

# The required fields of each type, as the form names them (<type>_<setting>), with each secret entered.
REQUIRED = {
    'sendgrid': {'sendgrid_api_key': 'SG.sendgrid-secret'},
    'mailgun': {'mailgun_region': 'eu', 'mailgun_smtp_username': 'postmaster@mg.example.org',
                'mailgun_smtp_password': 'mailgun-secret'},
    'postmark': {'postmark_server_token': 'postmark-secret', 'postmark_message_stream': 'outbound'},
    'brevo': {'brevo_smtp_login': 'login@example.org', 'brevo_smtp_key': 'brevo-secret'},
    'mailjet': {'mailjet_api_key': 'public-key', 'mailjet_secret_key': 'mailjet-secret'},
    'mailersend': {'mailersend_smtp_username': 'user@example.org', 'mailersend_smtp_password': 'mailersend-secret'},
    'mailtrap': {'mailtrap_smtp_token': 'mailtrap-secret'},
    'custom': {'custom_host': 'smtp.example.org', 'custom_port': '587', 'custom_security': 'tls'},
}

SECRETS = ['SG.sendgrid-secret', 'mailgun-secret', 'postmark-secret', 'brevo-secret', 'mailjet-secret',
           'mailersend-secret', 'mailtrap-secret']


def get_version(page: str) -> str:
    """Get the version of the providers a page's forms are based on."""
    match = re.search(r'name="version" value="([0-9a-f]+)"', page)
    return match.group(1) if match else ''


# Each type's secrets, every one of which a browser sends the source of (its radio is always checked).
SECRET_FIELDS = {
    'sendgrid': ['api_key'],
    'mailgun': ['smtp_password', 'api_key', 'webhook_signing_key'],
    'postmark': ['server_token', 'account_token', 'webhook_secret'],
    'brevo': ['smtp_key', 'api_key', 'webhook_secret'],
    'mailjet': ['secret_key', 'webhook_secret'],
    'mailersend': ['smtp_password', 'api_token', 'webhook_secret'],
    'mailtrap': ['smtp_token', 'api_token', 'webhook_secret'],
    'custom': ['password'],
}


def with_sources(type_name: str, fields: dict) -> dict:
    """Add "entered" as the source of each of a type's secrets, as a browser would, unless the fields give one."""
    sources = {f'{type_name}_{key}_source': 'entered' for key in SECRET_FIELDS[type_name]}
    return sources | fields


def add(type_name: str, name: str, fields: dict, version: str = '') -> Response:
    """Post the add form, disabled, based on the current version (or the one given)."""
    page = web.get('/providers/new')
    form = {'version': version or get_version(page), 'type': type_name, 'name': name, 'enabled': ''}
    return web.post_form('/providers/new', '/providers/new', form | with_sources(type_name, fields))


def read_settings() -> str:
    return stack.read_app_file('/config/settings.yaml')


def provider_names() -> list:
    """Get the providers' names, in their order in settings.yaml."""
    return re.findall(r'- name: "([^"]+)"', read_settings())


# The add form offers every type.
page = web.get('/providers/new')
has_types = all(f'<option value="{type_name}"' in page for type_name in REQUIRED)
suite.check('the add form offers every type of provider', has_types)
suite.check('with a fieldset of each type\'s fields', all(f'data-provider-type="{t}"' in page for t in REQUIRED))

# Adding a provider of each type, with its required fields.
for type_name, fields in REQUIRED.items():
    response = add(type_name, f'test-{type_name}', fields)
    suite.check(f'a {type_name} provider is added', response.get_status() == 303, (response.get_status(),
                                                                                      response.get_body()[:300]))

suite.check('every one is in settings.yaml', all(f'test-{t}' in provider_names() for t in REQUIRED))
page = web.get('/providers')
suite.check('and listed on the page', all(f'<strong>test-{t}</strong>' in page for t in REQUIRED))
enabled_table, _, disabled_table = page.partition('<h2>Disabled</h2>')
is_split = 'href="/providers/mailpit"' in enabled_table and 'test-sendgrid' not in enabled_table
suite.check('the enabled providers are in one table, and the disabled ones below', is_split
            and 'href="/providers/test-sendgrid"' in disabled_table)
suite.check('only the enabled ones can be dragged into order', enabled_table.count('data-role="drag-handle"') == 2
            and 'data-role="drag-handle"' not in disabled_table)
pencil = 'href="/providers/test-sendgrid/edit" aria-label="Edit test-sendgrid"'
suite.check('each has a pencil to edit it', pencil in page)

# A provider's own page.
page = web.get('/providers/test-mailgun')
suite.check('a provider has a page of its own', 'SMTP username' in page and 'postmaster@mg.example.org' in page)
suite.check('which never shows a secret', 'mailgun-secret' not in page and 'written out in full' in page)
suite.check('and has buttons to edit and remove it', 'href="/providers/test-mailgun/edit"' in page
            and 'data-action="/providers/test-mailgun/remove"' in page and 'id="remove-provider-dialog"' in page)
suite.check('and says it\'s disabled, so not tried', 'Not tried, as it&apos;s disabled.' in page)
page = web.get('/providers/mailpit')
suite.check('an enabled one says where it is in the order', ' of 2 enabled providers.' in page)
response = add('sendgrid', 'export', REQUIRED['sendgrid'])
is_refused = response.get_status() == 400 and 'web interface uses /providers/export' in response.get_body()
suite.check('a name the web interface\'s pages use is refused', is_refused, response.get_status())
suite.check('the secrets entered are saved', 'SG.sendgrid-secret' in read_settings())
pages = page + web.get('/providers/test-sendgrid/edit') + web.get('/providers/new')
suite.check('but never shown in a page', not any(secret in pages for secret in SECRETS))

# A secret read from an environment variable.
fields = {'sendgrid_api_key_source': 'environment', 'sendgrid_api_key_env': 'OTHER_SENDGRID_KEY'}
response = add('sendgrid', 'test-reference', fields)
suite.check('a secret from an environment variable is saved as a reference', response.get_status() == 303
            and 'api_key: "${OTHER_SENDGRID_KEY}"' in read_settings())
page = web.get('/providers/test-reference/edit')
suite.check('and shown as that variable when editing', 'value="OTHER_SENDGRID_KEY"' in page)

# The mistakes it refuses, keeping what was entered.
response = add('mailjet', 'test-incomplete', {'mailjet_api_key': 'public-key'})
is_refused = response.get_status() == 400 and 'value="test-incomplete"' in response.get_body()
suite.check('a missing required field is refused, keeping the name', is_refused, response.get_status())
response = add('sendgrid', 'test-sendgrid', REQUIRED['sendgrid'])
is_refused = response.get_status() == 400 and 'two providers called' in response.get_body()
suite.check('a name that\'s taken is refused', is_refused, response.get_status())
fields = {'sendgrid_api_key_source': 'environment', 'sendgrid_api_key_env': 'not-a-name'}
response = add('sendgrid', 'test-bad-reference', fields)
is_refused = response.get_status() == 400 and 'capital letters' in response.get_body()
suite.check('an environment variable name that isn\'t one is refused', is_refused, response.get_status())
response = add('sendgrid', 'test-stale', REQUIRED['sendgrid'], version='0' * 64)
is_refused = response.get_status() == 400 and 'changed since' in response.get_body()
suite.check('a change based on an old version is refused', is_refused, response.get_status())


def add_with_script(type_name: str, name: str, fields: dict) -> Response:
    """Post the add form as its script does (ProviderForm.js), asking for JSON back."""
    page = web.get('/providers/new')
    form = {'csrf_token': find_csrf_token(page), 'version': get_version(page), 'type': type_name, 'name': name,
            'enabled': ''}
    headers = {'Accept': 'application/json'}
    return web.request('POST', '/providers/new', form | with_sources(type_name, fields), headers=headers)


# Saving with the form's script, which keeps the form (and the secrets entered in it) when it's refused.
page = web.get('/providers/new')
suite.check('the form gives its script the names the web interface uses', 'data-reserved-names="[&quot;new&quot;'
            in page and 'id="provider-name-error"' in page and 'data-role="form-error"' in page)
response = add_with_script('sendgrid', 'Sendgrid', REQUIRED['sendgrid'])
is_json_error = (response.get_status() == 400 and response.get_error_code() == 2
                 and 'isn\'t a valid provider name' in response.get_json()['error']['message'])
suite.check('a refused save from the script gets a JSON error, not the form again', is_json_error,
            (response.get_status(), response.get_body()[:300]))
suite.check('which doesn\'t save it', 'Sendgrid' not in provider_names())
response = add_with_script('sendgrid', 'test-sendgrid', REQUIRED['sendgrid'])
suite.check('a taken name gets a JSON error too', response.get_status() == 400
            and 'two providers called' in response.get_json()['error']['message'], response.get_status())
response = add_with_script('sendgrid', 'test-script', REQUIRED['sendgrid'])
suite.check('a save from the script gets where to go next', response.get_status() == 200
            and response.get_json() == {'redirect': '/providers'}, (response.get_status(), response.get_body()[:300]))
suite.check('and is saved', 'test-script' in provider_names())

# Editing, with a secret left blank kept as it was.
page = web.get('/providers/test-postmark/edit')
suite.check('the edit form has the type fixed', 'name="type" value="postmark"' in page and '<select id="provider-type"'
            not in page)
form = {'version': get_version(page), 'name': 'test-postmark', 'enabled': '', 'postmark_server_token': '',
        'postmark_server_token_source': 'entered', 'postmark_message_stream': 'broadcast',
        'postmark_account_token_source': 'entered', 'postmark_webhook_secret_source': 'entered'}
response = web.post_form('/providers/test-postmark/edit', '/providers/test-postmark/edit', form)
saved = read_settings()
suite.check('a provider is edited', response.get_status() == 303 and 'message_stream: "broadcast"' in saved,
            response.get_status())
suite.check('and its page is shown', response.get_header('Location') == '/providers/test-postmark')
suite.check('and its secret, left blank, is kept', 'server_token: "postmark-secret"' in saved)

# Renaming, which keeps its secrets.
page = web.get('/providers/test-brevo/edit')
form = {'version': get_version(page), 'name': 'test-brevo-renamed', 'enabled': '',
        'brevo_smtp_login': 'login@example.org', 'brevo_smtp_key': '', 'brevo_smtp_key_source': 'entered',
        'brevo_api_key_source': 'entered',
        'brevo_webhook_secret_source': 'entered'}
response = web.post_form('/providers/test-brevo/edit', '/providers/test-brevo/edit', form)
names = provider_names()
is_renamed = response.get_status() == 303 and 'test-brevo-renamed' in names and 'test-brevo' not in names
suite.check('a provider is renamed', is_renamed, names)
suite.check('keeping its secret', 'smtp_key: "brevo-secret"' in read_settings())


def set_enabled(name: str, action: str) -> Response:
    """Press a provider's Enable or Disable button, on its page."""
    path = f'/providers/{name}'
    return web.post_form(path, f'{path}/{action}', {'version': get_version(web.get(path))})


# Disabling a provider, which keeps its settings, so it can be enabled again without setting it up again.
page = web.get('/providers/test-sendgrid')
suite.check('a disabled provider\'s page has an Enable button', 'action="/providers/test-sendgrid/enable"' in page)
suite.check('and its Remove button says it\'s disabled', 'It&apos;s disabled, so no emails are sent through it now.'
            in page and 'data-refused="false"' in page)
response = set_enabled('test-sendgrid', 'enable')
page = web.get('/providers/test-sendgrid')
suite.check('enabling it goes back to its page', response.get_header('Location') == '/providers/test-sendgrid')
suite.check('and it\'s enabled, with a Disable button', 'Not tried' not in page
            and 'action="/providers/test-sendgrid/disable"' in page, response.get_status())
suite.check('keeping its secret', 'SG.sendgrid-secret' in read_settings())
response = set_enabled('test-sendgrid', 'disable')
page = web.get('/providers/test-sendgrid')
suite.check('and it can be disabled again', 'Not tried, as it&apos;s disabled.' in page, response.get_status())
page = web.get('/providers/mailpit')
suite.check('an enabled provider\'s Remove button names the others emails will go through instead',
            'the other enabled provider instead: &quot;mailpit-secondary&quot;' in page)

# The only enabled provider can be neither disabled nor removed, and its page says so before it's tried.
set_enabled('mailpit-secondary', 'disable')
page = web.get('/providers/mailpit')
is_refused = 'data-refused="true"' in page and 'not even password reset links' in page
suite.check('the only enabled provider\'s Remove button says it can\'t be removed, and why', is_refused)
suite.check('and its Disable button is disabled', re.search(r'action="/providers/mailpit/disable"[\s\S]*?<button '
            r'type="submit" class="secondary" disabled title="It&apos;s the only enabled provider', page) is not None)
set_enabled('mailpit', 'disable')
page = web.get('/providers/mailpit')
suite.check('and disabling it anyway is refused', 'Not tried' not in page and 'must be enabled' in page)
set_enabled('mailpit-secondary', 'enable')
suite.check('the other is enabled again', 'Not tried' not in web.get('/providers/mailpit-secondary'))


def post_from_list(path: str, fields: dict) -> Response:
    """Post one of the Providers page's buttons, with the version it's based on."""
    page = web.get('/providers')
    return web.post_form('/providers', path, {'version': get_version(page)} | fields)


# Putting the enabled providers in order (as dragging them does), with the disabled ones after them.
response = post_from_list('/providers/order', {'order_0': 'mailpit-secondary', 'order_1': 'mailpit'})
names = provider_names()
is_ordered = response.get_status() == 303 and names[:2] == ['mailpit-secondary', 'mailpit']
suite.check('the enabled providers are put in a new order', is_ordered, names)
suite.check('with the disabled ones after them', all(name.startswith('test-') for name in names[2:]), names)
response = post_from_list('/providers/order', {'order_0': 'mailpit'})
suite.check('an order without every enabled provider is refused', provider_names()[:2] == names[:2]
            and 'every enabled provider' in web.get('/providers'))
post_from_list('/providers/order', {'order_0': 'mailpit', 'order_1': 'mailpit-secondary'})
suite.check('and they\'re put back', provider_names()[:2] == ['mailpit', 'mailpit-secondary'])

# The mode, as a radio group, with each mode explained.
page = web.get('/providers')
has_radios = all(f'name="mode" value="{mode}"' in page for mode in ['round_robin', 'overflow', 'fallback'])
suite.check('the mode is a radio group', has_radios and 'Useful for a backup' in page)
response = post_from_list('/providers/mode', {'mode': 'fallback'})
suite.check('choosing a mode saves it', response.get_status() == 303 and 'mode: "fallback"' in read_settings())
post_from_list('/providers/mode', {'mode': 'overflow'})

# Removing, which asks first.
suite.check('removing asks first', 'id="remove-provider-dialog"' in web.get('/providers'))
response = post_from_list('/providers/test-mailtrap/remove', {})
suite.check('a provider is removed', response.get_status() == 303 and 'test-mailtrap' not in provider_names())
post_from_list('/providers/mailpit-secondary/remove', {})
response = post_from_list('/providers/mailpit/remove', {})
is_kept = 'mailpit' in provider_names() and 'must be enabled' in web.get('/providers')
suite.check('the last enabled provider can\'t be removed', is_kept)

# The YAML editor is still there, as an advanced option.
page = web.get('/providers')
suite.check('the YAML editor is still there', 'Advanced: edit the providers as YAML' in page and 'name="yaml"' in page)
is_missing = web.request('GET', '/providers/nonsense/edit').get_status() == 404
suite.check('an unknown provider\'s edit page is a 404', is_missing)

suite.finish()
