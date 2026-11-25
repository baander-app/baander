<?php

declare(strict_types=1);

namespace Baander\DevDependencies\Php;

/**
 * Compares each digest pin with what its tag resolves to now. A moved tag is a finding, because
 * the image the pin names no longer receives the tag's updates; a registry that cannot be read is
 * a gap, so an unreachable source never reads as a current pin.
 *
 * @phpstan-import-type Image from BuildInputs
 */
final class ImageDrift
{
    /** @param list<Image> $images */
    public static function assess(array $images, DockerEvidence $registry, Report $report): void
    {
        $locations = [];
        foreach ($images as $image) {
            $locations[$image['reference']][] = $image['path'] . ':' . $image['line'];
        }
        ksort($locations);
        foreach ($locations as $reference => $where) {
            [$tagged, $pinned] = explode('@', $reference, 2);
            if (!DockerEvidence::isTagged($tagged)) {
                $report->add('evidence', $reference, 'image-drift', 'policy', 'Pinned by digest alone; there is no tag to compare.', ['locations' => $where]);
                continue;
            }
            try {
                $current = $registry->digest($tagged);
            } catch (\Throwable $error) {
                $report->add('gap', $reference, 'image-drift', 'registry', $error->getMessage(), ['locations' => $where]);
                continue;
            }
            $report->add(
                $current === $pinned ? 'evidence' : 'finding',
                $reference,
                'image-drift',
                'registry',
                $current === $pinned ? 'The tag still resolves to the pinned digest.' : 'The tag resolves to another digest; review the new image and move the pin.',
                ['tag' => $tagged, 'pinned' => $pinned, 'current' => $current, 'locations' => $where],
            );
        }
    }
}
