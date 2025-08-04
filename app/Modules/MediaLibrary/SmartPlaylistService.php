<?php

namespace App\Modules\MediaLibrary;

use App\Models\Song;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class SmartPlaylistService
{
    public function getSongsForRules(array $rules)
    {
        $query = (new \App\Models\Song)->query();

        foreach ($rules as $ruleGroup) {
            $query->where(function (Builder $q) use ($ruleGroup) {
                $operator = $ruleGroup['operator'] ?? 'and';
                $groupRules = $ruleGroup['rules'] ?? [];

                foreach ($groupRules as $index => $rule) {
                    if ($index === 0 || strtolower($operator) === 'and') {
                        $this->applyRule($q, $rule);
                    } else {
                        $q->orWhere(function (Builder $subQuery) use ($rule) {
                            $this->applyRule($subQuery, $rule);
                        });
                    }
                }
            });
        }

        return $query->get();
    }

    protected function applyRule(Builder $query, array $rule)
    {
        $field = $rule['field'];
        $operator = $rule['operator'];
        $value = $rule['value'];

        switch ($field) {
            case 'genre':
                return $this->applyGenreRule($query, $operator, $value);
            case 'artist':
                return $this->applyArtistRule($query, $operator, $value);
            case 'year':
                return $this->applyNumericRule($query, 'year', $operator, $value);
            case 'duration':
                return $this->applyNumericRule($query, 'length', $operator, $value);
        }
    }

    protected function applyGenreRule(Builder $query, $operator, $value)
    {
        dump($value, $operator, $value);

        if ($operator === 'is') {
            return $query->whereHas('genres', function (Builder $q) use ($value) {
                return $q->whereIn('name', Arr::wrap($value));
            });
        }

        if ($operator === 'isNot') {
            return $query->whereDoesntHave('genres', function ($q) use ($value) {
                $q->whereIn('name', Arr::wrap($value));
            });
        }
    }

    protected function applyArtistRule($query, $operator, $value)
    {
        if ($operator === 'is') {
            return $query->whereHas('artists', function ($q) use ($value) {
                $q->whereIn('name', Arr::wrap($value));
            });
        }

        if ($operator === 'isNot') {
            return $query->whereDoesntHave('artists', function ($q) use ($value) {
                $q->whereIn('name', Arr::wrap($value));
            });
        }
    }

    protected function applyNumericRule($query, $field, $operator, $value)
    {
        switch ($operator) {
            case 'is':
                return $query->where($field, $value);
            case 'isNot':
                return $query->where($field, '!=', $value);
            case 'greaterThan':
                return $query->where($field, '>', $value);
            case 'lessThan':
                return $query->where($field, '<', $value);
            case 'between':
                return $query->whereBetween($field, $value);
        }
    }
}
