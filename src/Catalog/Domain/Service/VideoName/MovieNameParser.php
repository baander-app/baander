<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Service\VideoName;

use App\Catalog\Domain\ValueObject\VideoName\MovieName;

final class MovieNameParser
{
    public function parse(string $relativePath): MovieName
    {
        $parts = explode('/', $relativePath);
        $stem = NameTokens::stem(array_pop($parts));
        $folders = [];
        foreach ($parts as $part) {
            if (NameTokens::extraFolder($part) !== null) {
                break;
            }
            $folders[] = $part;
        }
        $name = $folders === [] ? $stem : $folders[array_key_last($folders)];

        return new MovieName(
            folderPath: implode('/', $folders),
            title: NameTokens::title($name),
            year: NameTokens::year($name) ?? NameTokens::year($stem),
            providerTags: NameTokens::providerTags($name . ' ' . $stem),
            extraKind: NameTokens::extraKind($parts, $stem),
            sample: NameTokens::sample($stem),
        );
    }
}
