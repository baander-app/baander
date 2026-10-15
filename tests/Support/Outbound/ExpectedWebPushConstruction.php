<?php

declare(strict_types=1);

namespace App\Tests\Support\Outbound;

use App\Notification\Infrastructure\Push\WebPushFactory;
use Minishlink\WebPush\WebPush;
use PHPUnit\Framework\Assert;

/** Assert the installed library's optional-accelerator notice without masking other issues. */
final class ExpectedWebPushConstruction
{
    private const string OPTIONAL_ACCELERATOR_NOTICE = 'It is highly recommended to install the GMP or BCMath extension to speed up calculations. The fastest available calculator implementation will be automatically selected at runtime.';

    /** @param array<string, mixed> $auth */
    public static function create(WebPushFactory $factory, array $auth): WebPush
    {
        $count = 0;
        $source = dirname(__DIR__, 3) . '/vendor/minishlink/web-push/src/Utils.php';
        $previous = null;
        $previous = set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$count, $source, &$previous): bool {
            if ($severity === E_USER_NOTICE && $message === self::OPTIONAL_ACCELERATOR_NOTICE && $file === $source) {
                ++$count;

                return true;
            }

            return $previous !== null ? $previous($severity, $message, $file, $line) : false;
        }, E_ALL);
        try {
            $webPush = $factory->create($auth);
        } finally {
            restore_error_handler();
        }
        Assert::assertSame(extension_loaded('gmp') || extension_loaded('bcmath') ? 0 : 1, $count, 'The installed WebPush constructor emits only its expected optional-accelerator notice.');

        return $webPush;
    }
}
