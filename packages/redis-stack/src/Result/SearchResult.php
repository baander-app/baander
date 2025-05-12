<?php

namespace Baander\RedisStack\Result;

class SearchResult
{
    protected $count;
    protected $documents;

    public function __construct(int $count, $documents)
    {
        $this->count = $count;
        $this->documents = $documents;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function getDocuments(): array
    {
        return $this->documents;
    }


    public static function makeSearchResult(
        $raw,
        bool $documentsAsArray,
        bool $withScores = false,
        bool $withPayloads = false,
        bool $noContent = false
    ) {
        $documentWidth = $noContent ? 1 : 2;

        if (!$raw) {
            return false;
        }

        if (is_countable($raw) && count($raw) === 1) {
            return new SearchResult(0, []);
        }

        if ($withScores) {
            $documentWidth++;
        }

        if ($withPayloads) {
            $documentWidth++;
        }

        $count = array_shift($raw);
        $documents = [];
        for ($i = 0; $i < count($raw); $i += $documentWidth) {
            $document = $documentsAsArray ? [] : new \stdClass();
            $documentsAsArray ?
                $document['id'] = $raw[$i] :
                $document->id = $raw[$i];
            if ($withScores) {
                $documentsAsArray ?
                    $document['score'] = $raw[$i+1] :
                    $document->score = $raw[$i+1];
            }
            if ($withPayloads) {
                $j = $withScores ? 2 : 1;
                $documentsAsArray ?
                    $document['payload'] = $raw[$i+$j] :
                    $document->payload = $raw[$i+$j];
            }
            if (!$noContent) {
                $fields = $raw[$i + ($documentWidth - 1)];
                if (is_array($fields)) {
                    for ($j = 0; $j < count($fields); $j += 2) {
                        $documentsAsArray ?
                            $document[$fields[$j]] = $fields[$j + 1] :
                            $document->{$fields[$j]} = $fields[$j + 1];
                    }
                }
            }
            $documents[] = $document;
        }
        return new SearchResult($count, $documents);
    }
}