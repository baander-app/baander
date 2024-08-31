<?php

namespace App\Services\Widgets\Types;

class DashboardNavigationLink extends Widget
{
    public function __construct(
        public string  $label,
        public ?string $href = null,
        public ?string $to = null,
    )
    {
    }

    public static function getName(): string
    {
        return 'DashboardNavigationLink';
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'href'  => $this->href,
            'to'    => $this->to,
        ];
    }

    public static function getSchema(): array
    {
        return array_merge(parent::getSchema(), [
            'properties' => [
                'label' => [
                    'type'        => 'string',
                    'description' => 'Label of the menu element',
                ],
                'href'  => [
                    'type'        => 'string',
                    'description' => 'External link',
                ],
                'to'    => [
                    'type'        => 'string',
                    'description' => 'An internal react route',
                ],
            ],
            'required'   => ['label'],
        ]);
    }
}