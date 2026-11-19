"""
Records each check a test suite makes, prints it as it goes, and reports the total at the end.
"""

import sys
from typing import NoReturn


class Suite:
    """
    One test suite's checks. Each is printed as PASS or FAIL as it's made, and finish() exits with a failure status
    if any failed, so run-tests.sh can tell.
    """

    def __init__(self, name: str) -> None:
        self.m_name = name
        self.m_passed = 0
        self.m_failed = 0
        print(f'== {name}', flush=True)

    def check(self, name: str, passed: bool, detail: object = '') -> bool:
        """
        Record a check.

        :param name: what's being checked, e.g. "wrong password refused".

        :param passed: whether it passed.

        :param detail: what to show if it failed, to help work out why.

        :return: whether it passed, so a later check that depends on it can be skipped.
        """
        if passed:
            self.m_passed += 1
            print(f'PASS {name}', flush=True)
        else:
            self.m_failed += 1
            print(f'FAIL {name} -- {detail}', flush=True)

        return passed

    def finish(self) -> NoReturn:
        """Print the total, and exit: with status 1 if any check failed."""
        total = self.m_passed + self.m_failed
        print(f'{self.m_name}: {self.m_passed}/{total} passed\n', flush=True)
        sys.exit(1 if self.m_failed > 0 else 0)
