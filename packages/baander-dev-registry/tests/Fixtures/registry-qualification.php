<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/scripts/bootstrap.php';

exit(new Baander\DevRegistry\QualificationRunner($_SERVER['argv'][1])->run(['fuzz']));
