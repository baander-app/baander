<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command\DocGenerator;

use App\Command\DocGenerator\CodebaseScanner;
use PHPUnit\Framework\TestCase;

final class CodebaseScannerTest extends TestCase
{
    public function testReflectedInterfacesAreSerializedAsAList(): void
    {
        $root = sys_get_temp_dir() . '/baander-scanner-' . bin2hex(random_bytes(8));
        $directory = $root . '/Auth/Domain/Model/OAuth';
        mkdir($directory, 0700, true);
        $file = $directory . '/TokenId.php';
        copy(dirname(__DIR__, 4) . '/src/Auth/Domain/Model/OAuth/TokenId.php', $file);
        try {
            $contexts = (new CodebaseScanner())->scan($root);
            $this->assertCount(1, $contexts);
            $this->assertCount(1, $contexts[0]->classes);
            $class = $contexts[0]->classes[0];
            $this->assertSame('TokenId', $class->shortName);
            $this->assertSame(['Stringable', 'JsonSerializable'], $class->interfaces);
            $this->assertSame('["Stringable","JsonSerializable"]', json_encode($class->interfaces));
            $this->assertNotEmpty($class->description);
        } finally {
            unlink($file);
            rmdir($directory);
            rmdir(dirname($directory));
            rmdir(dirname($directory, 2));
            rmdir(dirname($directory, 3));
            rmdir($root);
        }
    }
}
