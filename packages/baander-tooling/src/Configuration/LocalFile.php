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
            $tooling = [];
            foreach (get_object_vars($data['tooling']) as $section => $settings) {
                if (!$settings instanceof \stdClass) {
                    throw new \InvalidArgumentException('Development tooling sections must be objects.');
                }
                $tooling[$section] = get_object_vars($settings);
                if ($section === 'images') {
                    foreach ($tooling[$section] as $role => $image) {
                        if (!$image instanceof \stdClass) {
                            throw new \InvalidArgumentException('Development image roles must be objects.');
                        }
                        $tooling[$section][$role] = get_object_vars($image);
                    }
                }
            }
            // Validate supported settings without touching library paths or creating resources.
            ToolingConfiguration::resolve(local: $tooling);
            $data['tooling'] = $tooling;
        }
        return $data;
    }
}
