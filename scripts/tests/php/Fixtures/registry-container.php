<?php

declare(strict_types=1);

require dirname(__DIR__, 3) . '/bootstrap.php';
exit(new Baander\Tooling\Registry\ContainerQualification($_SERVER['argv'][1])->run($_SERVER['argv'][2] === 'backup'));
