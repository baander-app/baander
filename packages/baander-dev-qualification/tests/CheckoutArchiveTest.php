<?php

declare(strict_types=1);

namespace Baander\DevQualification\Tests;

use Baander\Configuration\ToolingConfiguration;
use Baander\DevQualification\Fixtures\CheckoutArchive;
use Baander\DevQualification\Fixtures\Runtime;
use PHPUnit\Framework\TestCase;

final class CheckoutArchiveTest extends TestCase
{
    public function testArchivesUseExampleDefaultsWithAndWithoutPrivateLocalEnvironment(): void
    {
        $directory = sys_get_temp_dir() . '/baander-archive-' . bin2hex(random_bytes(8));
        mkdir($directory . '/source/config/secrets', 0700, true);
        mkdir($directory . '/export', 0700);
        file_put_contents($directory . '/source/.env.example', "FIXTURE=default-marker\n");
        file_put_contents($directory . '/source/.env.test', "FIXTURE_TEST=test-marker\n");
        file_put_contents($directory . '/source/config/application.php', '<?php return true;');
        chmod($directory . '/source/config/application.php', 0755);
        file_put_contents($directory . '/source/config/secrets/private.key', 'excluded-marker');
        file_put_contents($directory . '/source/.env', "FIXTURE=private-marker\n");
        $configuration = ToolingConfiguration::forRepository(dirname(__DIR__, 3), []);
        $runtime = new Runtime($directory . '/source', environment: [], configuration: $configuration);
        try {
            foreach ([true, false] as $hasLocalEnvironment) {
                if (!$hasLocalEnvironment) {
                    unlink($directory . '/source/.env');
                }
                CheckoutArchive::create($runtime, $directory . '/checkout.tar', ['config', '.env.example', '.env.test'], ['config/secrets']);
                $runtime->run(['tar', '-xf', $directory . '/checkout.tar', '-C', $directory . '/export']);
                self::assertSame("FIXTURE=default-marker\n", file_get_contents($directory . '/export/.env'));
                self::assertSame("FIXTURE=default-marker\n", file_get_contents($directory . '/export/.env.example'));
                self::assertSame("FIXTURE_TEST=test-marker\n", file_get_contents($directory . '/export/.env.test'));
                self::assertFileExists($directory . '/export/config/application.php');
                self::assertSame(0755, fileperms($directory . '/export/config/application.php') & 0777);
                self::assertFileDoesNotExist($directory . '/export/config/secrets/private.key');
                self::assertSame($hasLocalEnvironment, is_file($directory . '/source/.env'));
                if ($hasLocalEnvironment) {
                    self::assertSame("FIXTURE=private-marker\n", file_get_contents($directory . '/source/.env'));
                }
                unlink($directory . '/export/.env');
            }
        } finally {
            $runtime->cleaning = true;
            $runtime->run(['rm', '-rf', '--', $directory]);
        }
    }
}
