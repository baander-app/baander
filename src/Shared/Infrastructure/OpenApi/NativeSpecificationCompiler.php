<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\OpenApi;

use LogicException;
use OpenApi\Assembler;
use OpenApi\Augmenter;
use OpenApi\Compiler\OpenApi30Compiler;
use OpenApi\Loggers\CollectingLogger;
use OpenApi\Merge;
use OpenApi\Resolver;
use OpenApi\Utils\Pipeline;
use OpenApi\Utils\TypedList;

final class NativeSpecificationCompiler
{
    /** @return array<string, mixed> */
    public function compile(Assembler $assembler): array
    {
        $logger = new CollectingLogger();
        $factory = $assembler->getAttributeFactory();
        $factory->setLogger($logger);
        $specification = $assembler->getSpecification();
        $resolver = new Resolver();
        $resolver->setLogger($logger);
        $resolver->resolve($assembler);
        $mergers = new TypedList([new Merge\Tags(), new Merge\LastWins()]);
        $pipeline = new Pipeline(
            [
                new Augmenter\TypeAliases(),
                new Augmenter\Inheritance(),
                new Augmenter\OperationTags(),
                new Augmenter\ImpliedSchemas(),
                new Augmenter\Names(),
                new Augmenter\Enums(),
                new Augmenter\Shortcuts(),
                new Augmenter\DefaultMediaType(),
                new Augmenter\SecurityRequirements(),
                new Augmenter\PathItems(),
                new Augmenter\Types(),
                new Augmenter\Properties(),
                new Augmenter\Refs(),
                new Augmenter\Parameters(),
                new Augmenter\Merge($mergers),
                new Augmenter\PathFilter(),
                new Augmenter\Cleanup(),
                new Augmenter\MediaTypes(),
                new Augmenter\Docblocks(),
                new Augmenter\OperationIdTemplates(),
                new Augmenter\OperationIds(),
                new Augmenter\Tags(),
                new Augmenter\EnumDescriptions(),
                new Augmenter\Merge($mergers, Augmenter\Group::Augment),
            ],
            groups: [Augmenter\Group::Resolve, Augmenter\Group::Reduce, Augmenter\Group::Augment],
            defaultGroup: Augmenter\Group::Augment,
            logger: $logger,
        );
        $pipeline->get(Augmenter\Inheritance::class)?->setAttributeFactory($factory);
        $pipeline->process($specification);
        $compiler = new OpenApi30Compiler($logger);
        $diagnostics = $compiler->validate($specification);
        $document = $compiler->compile($specification);
        $failures = array_filter(
            [...$logger->entries(), ...$diagnostics],
            static fn(array $entry): bool => in_array($entry['level'], ['warning', 'error', 'critical', 'alert', 'emergency'], true),
        );
        if ($failures !== []) {
            throw new LogicException(implode("\n", array_unique(array_column($failures, 'message'))));
        }

        return $document;
    }
}
