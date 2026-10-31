<?php

declare(strict_types=1);

namespace Baander\Tooling\Website;

use League\CommonMark\Normalizer\TextNormalizerInterface;

final class HeadingSlugNormalizer implements TextNormalizerInterface
{
    /** @param array<string,mixed> $context */
    public function normalize(string $text, array $context = []): string
    {
        $slug = mb_strtolower($text);
        $slug = preg_replace('/[^\p{L}\p{M}\p{N}_\-\s]/u', '', $slug) ?? '';
        $slug = str_replace(' ', '-', trim($slug));
        return $slug === '' ? 'section' : $slug;
    }
}
