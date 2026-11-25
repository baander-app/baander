<?php

declare(strict_types=1);
use Baander\DevRegistry\QualificationRunner;

require dirname(__DIR__, 4) . '/scripts/bootstrap.php';

exit(new QualificationRunner($_SERVER['argv'][1])->run(['fuzz']));
