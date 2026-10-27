<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';
require dirname(__DIR__, 4) . '/relay/tests/bootstrap.php';

exit(new Baander\Tooling\Registry\QualificationRunner($_SERVER['argv'][1])->run(['fuzz']));
