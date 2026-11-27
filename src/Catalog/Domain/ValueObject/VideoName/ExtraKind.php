<?php

declare(strict_types=1);

namespace App\Catalog\Domain\ValueObject\VideoName;

enum ExtraKind: string
{
    case BehindTheScenes = 'behind_the_scenes';
    case DeletedScene = 'deleted_scene';
    case Featurette = 'featurette';
    case Interview = 'interview';
    case Scene = 'scene';
    case Short = 'short';
    case Trailer = 'trailer';
    case Clip = 'clip';
    case Sample = 'sample';
    case ThemeMusic = 'theme_music';
    case Backdrop = 'backdrop';
    case Other = 'other';
}
