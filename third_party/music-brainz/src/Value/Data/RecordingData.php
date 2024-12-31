<?php

namespace MusicBrainz\Value\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Data;

class RecordingData extends Data extends Data extends Data extends Data extends Data
{
    public
    string $mbid;
    public int $length;
    public string $title;
    public string $firstReleaseDate;
    public array $aliases = [];
    public array $ipis = [];
    public string $country;
    public string $labelCode;
    public string $labelType;
    public string $area;
    public array $lifeSpan;
    public string $sortName;
    public string $disambiguation;
    public int $rating;
    public array $releases = [];
    public array $tags = [];
    public array $artistCredits = [];
    public bool $videoFlag;
    public int $score;
    public array $relations = [];

    // Optionally, you can add custom validation rules here if needed.
            public function __construct(array $searchResult = [])
    {
    }
