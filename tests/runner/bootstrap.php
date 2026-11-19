<?php

/*
 * Loads the test runner's classes, and the packages installed in its image (see tests/runner/Dockerfile), for run.php
 * and worker.php.
 */

declare(strict_types = 1);

require_once '/opt/runner/vendor/autoload.php';

define('ROOT_DIR', dirname(__DIR__, 2));
new \Programster\Autoloader\Autoloader([__DIR__ . '/src']);
