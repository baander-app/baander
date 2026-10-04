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

        return [
            'payload' => $this->objectToArray($body->payload),
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

    /** @return array<string, mixed> */
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
            // Preserve empty nested objects for the flexible audio JSON payload.
            return get_object_vars($value) === [] ? $value : $this->objectToArray($value);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->convertNestedValue($item);
            }
        }

        return $value;
    }
}
