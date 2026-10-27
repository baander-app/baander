<?php

declare(strict_types=1);

namespace Baander\Tests\Tooling;

use Baander\RegistryTests\EnrollmentIssuerContract;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/relay/tests/bootstrap.php';

#[Group('registry-python-subject')]
final class EnrollmentIssuerTest extends TestCase
{
    public function testUnchangedIssuerThroughItsPublicCliAndIndependentOracle(): void
    {
        self::assertGreaterThan(
            25,
            EnrollmentIssuerContract::run(
                getenv('REGISTRY_SUBJECT_PYTHON') ?: 'python3',
                dirname(__DIR__, 3) . '/relay/tools/issue_enrollment.py',
            ),
        );
    }
}
