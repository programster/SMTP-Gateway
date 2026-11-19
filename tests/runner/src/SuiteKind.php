<?php

/*
 * How a suite is run: the PHP unit tests, inside the stack's app container, or a Python suite, from the tests folder.
 */

declare(strict_types = 1);


enum SuiteKind : string
{
    # PHP scripts in tests/unit, run one after another inside the stack's app container.
    case UNIT = 'unit';

    # A Python module, e.g. "integration.e2e", run from the tests folder against the stack.
    case PYTHON = 'python';
}
