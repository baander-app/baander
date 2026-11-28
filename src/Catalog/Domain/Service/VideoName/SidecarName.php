<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Service\VideoName;

use App\Catalog\Domain\ValueObject\VideoName\Sidecar;
use App\Catalog\Domain\ValueObject\VideoName\SidecarKind;

final class SidecarName
{
    public function parse(string $relativePath): ?Sidecar
    {
        $name = pathinfo($relativePath, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if ($extension === 'nfo') {
            return match (strtolower($name)) {
                'tvshow' => new Sidecar(SidecarKind::SeriesNfo),
                'season' => new Sidecar(SidecarKind::SeasonNfo),
                'movie' => new Sidecar(SidecarKind::MovieNfo),
                default => new Sidecar(SidecarKind::VideoNfo, videoStem: $name),
            };
        }
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return null;
        }
        if (preg_match('/^season(?:(\d+)|-specials)-poster$/iD', $name, $match) === 1) {
            return new Sidecar(SidecarKind::SeasonPoster, isset($match[1]) ? (int) $match[1] : 0);
        }
        if (preg_match('/^(.+)-thumb$/iD', $name, $match) === 1) {
            return new Sidecar(SidecarKind::VideoThumbnail, videoStem: $match[1]);
        }

        return match (strtolower($name)) {
            'poster', 'folder' => new Sidecar(SidecarKind::Poster),
            'fanart', 'backdrop' => new Sidecar(SidecarKind::Backdrop),
            default => null,
        };
    }
}
