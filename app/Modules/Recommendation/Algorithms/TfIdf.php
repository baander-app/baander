<?php

namespace App\Modules\Recommendation\Algorithms;

use App\Modules\Recommendation\Contracts\AlgorithmInterface;

class TfIdf implements AlgorithmInterface
{
    /**
     * @var array Collection of documents (arrays of terms)
     */
    private array $documents;

    /**
     * Constructor
     *
     * @param array $documents Array of documents (arrays of terms)
     */
    public function __construct(array $documents)
    {
        $this->documents = $documents;
    }

    /**
     * Calculate TF-IDF for a collection of documents
     *
     * @return array TF-IDF vectors for each document
     */
    public function calculate(): array
    {
        $documentCount = count($this->documents);

        if ($documentCount === 0) {
            return [];
        }

        // Get all unique terms
        $allTerms = [];
        foreach ($this->documents as $document) {
            foreach ($document as $term) {
                $allTerms[$term] = true;
            }
        }
        $allTerms = array_keys($allTerms);

        // Calculate term frequencies for each document
        $termFrequencies = [];
        foreach ($this->documents as $docId => $document) {
            $termFrequencies[$docId] = $this->calculateTermFrequency($document);
        }

        // Calculate inverse document frequency for each term
        $idf = [];
        foreach ($allTerms as $term) {
            $documentsWithTerm = 0;
            foreach ($this->documents as $document) {
                if (in_array($term, $document)) {
                    $documentsWithTerm++;
                }
            }
            // IDF formula: log(total documents / documents containing term)
            $idf[$term] = log($documentCount / max(1, $documentsWithTerm));
        }

        // Calculate TF-IDF vectors
        $tfidfVectors = [];
        foreach ($termFrequencies as $docId => $tf) {
            $tfidfVectors[$docId] = [];
            foreach ($allTerms as $term) {
                $termTf = $tf[$term] ?? 0;
                $tfidfVectors[$docId][$term] = $termTf * ($idf[$term] ?? 0);
            }
        }

        return $tfidfVectors;
    }

    /**
     * Calculate term frequency for a document
     *
     * @param array $document Array of terms
     * @return array Term frequencies
     */
    private function calculateTermFrequency(array $document): array
    {
        $tf = [];
        $totalTerms = count($document);

        foreach ($document as $term) {
            if (!isset($tf[$term])) {
                $tf[$term] = 0;
            }
            $tf[$term]++;
        }

        // Normalize by document length
        if ($totalTerms > 0) {
            foreach ($tf as $term => $count) {
                $tf[$term] = $count / $totalTerms;
            }
        }

        return $tf;
    }
}
