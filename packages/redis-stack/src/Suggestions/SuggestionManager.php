<?php

namespace Baander\RedisStack\Suggestions;

use Baander\RedisStack\RedisStack;
use Redis;

class SuggestionManager
{
    private Redis $redis;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Add a new suggestion or update the score of an existing one.
     *
     * @param string $dictionaryName The name of the suggestion dictionary.
     * @param string $suggestion The suggestion text.
     * @param float $score The score for this suggestion (higher is better).
     * @param bool $increment Whether to increment the score (if true) or overwrite it (if false).
     * @return bool True if the suggestion was successfully added.
     */
    public function addSuggestion(string $dictionaryName, string $suggestion, float $score, bool $increment = false): bool
    {
        $command = ['FT.SUGADD', $dictionaryName, $suggestion, $score];

        if ($increment) {
            $command[] = 'INCR';
        }

        RedisStack::getLogger()?->debug('Adding suggesting', [
            'dictionary' => $dictionaryName,
            'suggestion' => $suggestion,
            'score'      => $score,
            'increment'  => $increment,
            'command'    => implode(' ', $command),
        ]);

        return (bool)$this->redis->rawCommand(...$command);
    }

    /**
     * Get autocomplete suggestions based on the provided prefix.
     *
     * @param string $dictionaryName The name of the suggestion dictionary.
     * @param string $prefix The prefix to search for.
     * @param bool $fuzzy Whether to allow fuzzy matching.
     * @param int $maxSuggestions Maximum number of suggestions to retrieve.
     * @param bool $withScores If true, include scores for each suggestion.
     * @param bool $withPayloads If true, include payloads associated with each suggestion.
     * @return array List of suggestions.
     */
    public function getSuggestions(
        string $dictionaryName,
        string $prefix,
        bool   $fuzzy = false,
        int    $maxSuggestions = 10,
        bool   $withScores = false,
        bool   $withPayloads = false,
    ): array
    {
        $command = ['FT.SUGGET', $dictionaryName, $prefix, 'MAX', $maxSuggestions];

        if ($fuzzy) {
            $command[] = 'FUZZY';
        }

        if ($withScores) {
            $command[] = 'WITHSCORES';
        }

        if ($withPayloads) {
            $command[] = 'WITHPAYLOADS';
        }

        RedisStack::getLogger()?->debug('getSuggestions', [
            'directory'      => $dictionaryName,
            'prefix'         => $prefix,
            'fuzzy'          => $fuzzy,
            'maxSuggestions' => $maxSuggestions,
            'withScores'     => $withScores,
            'withPayloads'   => $withPayloads,
            'command'        => implode(' ', $command),
        ]);

        return $this->redis->rawCommand(...$command);
    }

    /**
     * Remove a specific suggestion from the dictionary.
     *
     * @param string $dictionaryName The name of the suggestion dictionary.
     * @param string $suggestion The suggestion entry to remove.
     * @return bool True if the suggestion was successfully deleted.
     */
    public function deleteSuggestion(string $dictionaryName, string $suggestion): bool
    {
        $result = (bool)$this->redis->rawCommand('FT.SUGDEL', $dictionaryName, $suggestion);

        RedisStack::getLogger()?->debug('Deleting suggestion', [
            'dictionary' => $dictionaryName,
            'suggestion' => $suggestion,
            'success'    => $result,
        ]);

        return $result;
    }

    /**
     * Get the current number of suggestions in the dictionary.
     *
     * @param string $dictionaryName The name of the suggestion dictionary.
     * @return int The number of suggestions.
     */
    public function getSuggestionsCount(string $dictionaryName): int
    {
        $result = (int)$this->redis->rawCommand('FT.SUGLEN', $dictionaryName);

        RedisStack::getLogger()?->debug('Getting suggestions count', [
            'dictionary' => $dictionaryName,
            'count'      => $result,
        ]);

        return $result;
    }
}