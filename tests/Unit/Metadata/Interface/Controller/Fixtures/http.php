<?php

declare(strict_types=1);

namespace App\Metadata\Infrastructure\Api\MusicBrainz {
    function file_get_contents(string $filename, bool $useIncludePath = false, mixed $context = null): string|false
    {
        return \App\Tests\Unit\Metadata\Interface\Controller\MetadataResponseTest::$response;
    }
}

namespace App\Metadata\Infrastructure\Api\Discogs {
    function file_get_contents(string $filename, bool $useIncludePath = false, mixed $context = null): string|false
    {
        return \App\Tests\Unit\Metadata\Interface\Controller\MetadataResponseTest::$response;
    }
}
