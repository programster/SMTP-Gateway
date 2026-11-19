"""
The application's logger (ServiceFactory::getLogger(), programster/log's FailoverLogger): it logs to the database (the
"log" table, shown under Logs), and while the database can't be reached, to /logs/app.log instead, along with an error
saying why the database couldn't be used.
"""

import json

from lib import stack
from lib.harness import Suite

suite = Suite('Logging')


def log(message: str) -> None:
    """Log an error through the application's logger, in a process of its own, as a request or script would."""
    logger = 'ServiceFactory::getLogger(LogCategory::SYSTEM)'
    stack.run_php(f"{logger}->error({json.dumps(message)}, ['source' => 'logging test']);")


def read_file_logs() -> list:
    """Get the logs written to /logs/app.log, as JsonFileLogger writes them."""
    text = stack.read_app_file('/logs/app.log').strip()
    return json.loads(text) if text else []


log('logged while the database is up')
count = stack.sql("SELECT count(*) FROM log WHERE message = 'logged while the database is up'")
suite.check('a log goes to the database', count == '1', count)
category = stack.sql("SELECT context->>'category' FROM log WHERE message = 'logged while the database is up'")
suite.check('with its category in its context', category == 'system', category)

stack.stop_services('db')

try:
    log('logged while the database is down')
    logs = read_file_logs()
    messages = [entry.get('message', '') for entry in logs]
    is_in_file = 'logged while the database is down' in messages
    suite.check('while the database is down, a log goes to the file instead', is_in_file, messages)
    failures = [entry for entry in logs if entry.get('context', {}).get('failed_during') == 'fetchLogger']
    suite.check('along with an error saying why the database couldn\'t be used', len(failures) >= 1, logs[-3:])
finally:
    stack.start_services('db')


def is_database_back() -> bool:
    try:
        return stack.sql('SELECT 1') == '1'
    except Exception:
        return False


suite.check('the database comes back', stack.wait_until(is_database_back, 60))
log('logged once the database is back')
count = stack.sql("SELECT count(*) FROM log WHERE message = 'logged once the database is back'")
suite.check('once it\'s back, logs go to the database again', count == '1', count)
suite.finish()
