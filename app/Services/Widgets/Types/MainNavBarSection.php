<?php

namespace App\Services\Widgets\Types;

class MainNavBarSection extends Widget
{
    public function __construct(
        public string $label,
        public string $iconName,
        public array  $links,
    )
    {
    }

    public function toArray(): array
    {
        return [
            'label'    => $this->label,
            'iconName' => $this->iconName,
            'links'    => $this->links,
        ];
    }

    public static function getName(): string
    {
        return 'MainNavBarSection';
    }

    public static function getSchema(): array
    {
        return array_merge(parent::getSchema(), [
            'properties' => [
                'label'    => [
                    'type' => 'string',
                ],
                'iconName' => [
                    'type' => 'string',
                ],
                'links'    => [
                    'type' => 'array',
                    '$ref' => MainNavBarLink::getId(),
                ],
            ],
            'required'   => ['label', 'iconName', 'links'],
        ]);
    }
}