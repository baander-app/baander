<?php

declare(strict_types=1);

namespace Baander\Tooling\Configuration;

final class LocalFile
{
    /** @return array<string,mixed> */
    public static function read(string $path): array
    {
        $configuration = JsonObject::object($path);
        $data = get_object_vars($configuration);
        JsonObject::keys($data, ['libraries', 'tooling'], 'development configuration');
        if (array_key_exists('libraries', $data) && !is_array($data['libraries'])) {
            throw new \InvalidArgumentException('Development libraries must be an array.');
        }
        if (array_key_exists('tooling', $data)) {
            if (!$data['tooling'] instanceof \stdClass) {
                throw new \InvalidArgumentException('Development tooling settings must be an object.');
            }
            $data['tooling'] = self::tooling($data['tooling']);
        }
        return $data;
    }

    /** @return array<string,mixed> */
    private static function tooling(\stdClass $configuration): array
    {
        $tooling = [];
        foreach (get_object_vars($configuration) as $section => $settings) {
            if (!$settings instanceof \stdClass) {
                throw new \InvalidArgumentException('Development tooling sections must be objects.');
            }
            $tooling[$section] = $section === 'images' ? self::images($settings) : get_object_vars($settings);
        }
        return $tooling;
    }

    /** @return array<string,array<string,mixed>> */
    private static function images(\stdClass $settings): array
    {
        $images = [];
        foreach (get_object_vars($settings) as $role => $image) {
            if (!$image instanceof \stdClass) {
                throw new \InvalidArgumentException('Development image roles must be objects.');
            }
            $images[$role] = get_object_vars($image);
        }
        return $images;
    }
}
