<?php

namespace App\Services\Widgets\Types;

class DashboardNavigationFeature extends Widget
{
    public function __construct(
        public string  $title,
        public array   $links,
        public ?string $iconName = null,
    )
    {
    }


    public static function getName(): string
    {
        return 'DashboardNavigationFeature';
    }

    public function toArray(): array
    {
        return [
            'title'    => $this->title,
            'links'    => $this->links,
            'iconName' => $this->iconName,
        ];
    }

    public static function getSchema(): array
    {
        return array_merge(parent::getSchema(), [
            'properties' => [
                'title'    => [
                    'type'        => 'string',
                    'description' => 'The title of the section',
                ],
                'links'    => [
                    '$ref' => route('api.schemas.widget', ['id' => DashboardNavigationLink::getId()]),
                ],
                'iconName' => [
                    'type'        => 'string',
                    'description' => 'The icon of the widget for use in <Iconify />',
                ],
            ],
            'required'   => ['title', 'links', 'iconName'],
        ]);
    }
}