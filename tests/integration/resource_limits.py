"""
The limits on the memory and CPU the service uses, which run-tests.sh starts this stack with set lower than their
defaults: that each is applied where it should be (nginx, PHP-FPM, the queue workers and PostgreSQL), and that the
read-only Server resources page (under Settings) shows them, with suggestions for this server, and warns about the
ones that don't fit together.
"""

import base64
import subprocess

from lib import stack
from lib.harness import Suite
from lib.web import WebClient, bearer, call_api
from lib.service import create_api_key

suite = Suite('Resource limits')
web = WebClient.create_logged_in()
auth = bearer(create_api_key(web, 'resource-limits'))

# Applied where they should be.
nginx_limits = stack.read_app_file('/run/nginx-limits.conf')
suite.check('nginx is given HTTP_MAX_BODY_SIZE', 'client_max_body_size 2M;' in nginx_limits, nginx_limits)
limits = stack.read_app_file('/run/runtime-limits.sh')
suite.check('PHP-FPM is given PHP_MEMORY_LIMIT', "export PHP_MEMORY_LIMIT='96M'" in limits, limits)
suite.check('and the defaults fill in what .env doesn\'t set', "export PHP_FPM_MAX_CHILDREN='8'" in limits, limits)

worker_commands = stack.run_php(
    "foreach (glob('/proc/[0-9]*/cmdline') as $f) { $c = str_replace(\"\\0\", ' ', (string)@file_get_contents($f));"
    " if (str_contains($c, 'queue-worker.php')) { echo $c, PHP_EOL; } }"
)

suite.check('the queue workers run with PHP_MEMORY_LIMIT', 'memory_limit=96M' in worker_commands, worker_commands)
cli_limit = stack.run_php("echo ini_get('memory_limit');")
suite.check('PHP on the command line (e.g. the cron jobs) has its own limit', cli_limit == '256M', cli_limit)
suite.check('PostgreSQL is given DB_MAX_CONNECTIONS', stack.sql('SHOW max_connections') == '50')
inspect = ['docker', 'inspect', '--format', '{{.HostConfig.Memory}}', stack.get_container('db')]
db_memory = subprocess.run(inspect, capture_output=True, text=True, check=True).stdout.strip()
suite.check('and its container is limited to DB_MEMORY_LIMIT', db_memory == str(320 * 1024 * 1024), db_memory)

# A request larger than HTTP_MAX_BODY_SIZE is refused by nginx, before PHP sees it.
big = {'raw': base64.b64encode(b'x' * 3 * 1024 * 1024).decode(), 'from': 'a@b.test', 'to': ['c@d.test']}
response = call_api('POST', '/send', auth, big)
suite.check('a request over HTTP_MAX_BODY_SIZE is refused', response.get_status() == 413, response.get_status())

# The Settings page's section.
page = web.get('/admin/settings/server-resources')
suite.check('the server resources page is shown', '<h1>Server resources</h1>' in page)
suite.check('it shows the server\'s memory and vCPUs', 'This server:' in page and 'vCPUs' in page)
suite.check('and where PostgreSQL is', 'PostgreSQL is the bundled' in page)
suite.check('it shows the current values', '<td>2 MiB</td>' in page and '<td>96 MiB</td>' in page)
suite.check('it suggests values to put in .env', 'id="suggested-env"' in page and 'QUEUE_WORKERS=' in page)
suite.check('it has no form to save', 'action="/admin/settings/server-resources"' not in page)
suite.check('the body size is warned about', 'HTTP_MAX_BODY_SIZE is too small' in page)
suite.check('as are the database connections', 'PostgreSQL accepts 50 connections' in page)
suite.check('as is PHP\'s memory', 'PHP_MEMORY_LIMIT is too small' in page)

# The same warnings are in the container's log, from start up.
logs = subprocess.run(['docker', 'logs', stack.get_container('app')], capture_output=True, text=True).stderr
suite.check('the warnings are in the container\'s log', 'WARNING: HTTP_MAX_BODY_SIZE is too small' in logs)

suite.finish()
