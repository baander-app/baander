<?php

namespace App\Services\Widgets\Types;

class MainNavBar extends Widget
{
    public function __construct(
        public array              $sections = [],
        public ?MainNavBarSection $footer = null,
    )
    {
    }

    public function addSection(MainNavBarSection $section): MainNavBar
    {
        $this->sections[] = $section;

        return $this;
    }

    public function setFooter(MainNavBarSection $section): MainNavBar
    {
        $this->footer = $section;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'sections' => $this->sections,
            'footer'   => $this->footer,
        ];
    }

    public static function getName(): string
    {
        return 'MainNavBar';
    }

    public static function getSchema(): array
    {
        return array_merge(parent::getSchema(), [
            'properties' => [
                'sections' => [
                    'type'  => 'array',
                    'items' => [
                        '$ref' => route('api.schemas.widgets', ['id' => MainNavBarSection::getId()]),
                    ],
                ],
                'footer'   => [
                    'type'  => 'array',
                    'items' => [
                        '$ref' => route('api.schemas.widgets', ['id' => MainNavBarSection::getId()]),
                    ],
                ],
            ],
        ]);
    }
}