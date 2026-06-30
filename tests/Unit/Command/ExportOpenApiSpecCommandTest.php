<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ExportOpenApiSpecCommand;
use Nelmio\ApiDocBundle\Render\RenderOpenApi;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ExportOpenApiSpecCommandTest extends TestCase
{
    private RenderOpenApi&MockObject $renderOpenApi;

    protected function setUp(): void
    {
        $this->renderOpenApi = $this->createMock(RenderOpenApi::class);
    }

    public function testConfigureSetsNameAndDescription(): void
    {
        $command = new ExportOpenApiSpecCommand($this->renderOpenApi);

        $this->assertSame('app:export-openapi-spec', $command->getName());
        $this->assertSame(
            'Export the OpenAPI specification to a JSON file.',
            $command->getDescription(),
        );
    }

    public function testConfigureAddsOutputAndFormatOptionsWithDefaults(): void
    {
        $command = new ExportOpenApiSpecCommand($this->renderOpenApi);
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasOption('output'));
        $this->assertTrue($definition->hasOption('format'));
        $this->assertSame('openapi.json', $definition->getOption('output')->getDefault());
        $this->assertSame('json', $definition->getOption('format')->getDefault());
    }

    public function testExecuteWritesJsonSpecToConfiguredPath(): void
    {
        $outputFile = $this->tempPath('.json');
        $this->renderOpenApi
            ->expects($this->once())
            ->method('render')
            ->with('json', 'default')
            ->willReturn('{"openapi":"3.0.0"}');

        $tester = new CommandTester(new ExportOpenApiSpecCommand($this->renderOpenApi));
        $exitCode = $tester->execute(['--output' => $outputFile, '--format' => 'json']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFileExists($outputFile);
        $this->assertStringContainsString('"openapi":"3.0.0"', (string) file_get_contents($outputFile));
        $this->assertStringContainsString('json', $tester->getDisplay());
    }

    public function testExecuteWritesYamlSpecWhenFormatRequested(): void
    {
        $outputFile = $this->tempPath('.yaml');
        $this->renderOpenApi
            ->expects($this->once())
            ->method('render')
            ->with('yaml', 'default')
            ->willReturn("openapi: 3.0.0\n");

        $tester = new CommandTester(new ExportOpenApiSpecCommand($this->renderOpenApi));
        $exitCode = $tester->execute(['--output' => $outputFile, '--format' => 'yaml']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertFileExists($outputFile);
        $this->assertSame("openapi: 3.0.0\n", file_get_contents($outputFile));
    }

    public function testExecuteFailsOnUnsupportedFormat(): void
    {
        $tester = new CommandTester(new ExportOpenApiSpecCommand($this->renderOpenApi));
        $exitCode = $tester->execute(['--format' => 'xml']);

        $this->assertSame(Command::FAILURE, $exitCode);
        // The renderer must not be invoked for an invalid format.
        $this->assertStringContainsString('Format must be', $tester->getDisplay());
    }

    public function testExecuteFailsWhenRendererThrows(): void
    {
        $this->renderOpenApi
            ->expects($this->once())
            ->method('render')
            ->willThrowException(new RuntimeException('renderer exploded'));

        $tester = new CommandTester(new ExportOpenApiSpecCommand($this->renderOpenApi));
        $exitCode = $tester->execute(['--format' => 'json']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Failed to generate OpenAPI specification', $tester->getDisplay());
    }

    private function tempPath(string $suffix): string
    {
        return sys_get_temp_dir() . '/baander_openapi_' . bin2hex(random_bytes(6)) . $suffix;
    }
}
