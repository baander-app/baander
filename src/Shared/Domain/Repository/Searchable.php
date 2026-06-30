<?php

declare(strict_types=1);

namespace App\Shared\Domain\Repository;

use App\Shared\Domain\Model\SearchOptions;
use App\Shared\Domain\Model\SearchResult;

interface Searchable
{
    public function search(SearchOptions $options): SearchResult;
}
