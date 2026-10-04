<?php

declare(strict_types=1);

namespace App\UserPreference\Interface\Request;

use App\UserPreference\Interface\Exception\InvalidPreferenceRequestBody;

final class PreferenceRequestBody
{
    /** @return array{payload: array<string, mixed>, version: int} */
    public function save(string $json): array
    {
        $body = $this->decode($json);
        if (!isset($body->payload) || !$body->payload instanceof \stdClass) {
            throw new InvalidPreferenceRequestBody(422, 'Payload must be a JSON object.');
        }

        $payload = [];
        foreach ($this->objectToArray($body->payload) as $name => $value) {
            if (!is_string($name)) {
                throw new InvalidPreferenceRequestBody(422, 'Preference field names must not be numeric.');
            }
            $payload[$name] = $value;
        }

        return [
            'payload' => $payload,
            'version' => $this->version($body, 0),
        ];
    }

    public function rollback(string $json): int
    {
        return $this->version($this->decode($json), 1);
    }

    private function decode(string $json): \stdClass
    {
        try {
            $body = json_decode($json, associative: false, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidPreferenceRequestBody(400, 'Invalid JSON request body.', $exception);
        }

        if (!$body instanceof \stdClass) {
            throw new InvalidPreferenceRequestBody(422, 'Request body must be a JSON object.');
        }

        return $body;
    }

    private function version(\stdClass $body, int $minimum): int
    {
        if (!isset($body->version) || !is_int($body->version) || $body->version < $minimum) {
            throw new InvalidPreferenceRequestBody(422, sprintf('Version must be an integer of at least %d.', $minimum));
        }

        return $body->version;
    }

    /** @return array<array-key, mixed> */
    private function objectToArray(\stdClass $object): array
    {
        $values = get_object_vars($object);
        foreach ($values as $key => $value) {
            $values[$key] = $this->convertNestedValue($value);
        }

        return $values;
    }

    private function convertNestedValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            // Empty and sequential numeric-key objects must not become JSON lists.
            $properties = $this->objectToArray($value);
            return array_is_list($properties) ? $value : $properties;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->convertNestedValue($item);
            }
        }

        return $value;
    }
}
