<?php

namespace App\Services\Widgets\Types;

class MainNavBarLink extends Widget
{
    public function __construct(
        public string  $label,
        public ?string $href = null,
        public ?string $to = null
    )
    {
    }

    public function toArray(): array
    {
        $data = [
            'label' => $this->label,
        ];

        if (null !== $this->href) {
            $data['href'] = $this->href;
        };

        if (null !== $this->to) {
            $data['to'] = $this->to;
        }

        return $data;
    }

    public static function getName(): string
    {
        return 'MainNavBarLink';
    }

    public static function getSchema(): array
    {
        return array_merge(parent::getSchema(), [
            'properties' => [
                'label' => [
                    'type' => 'string',
                ],
                'href'  => [
                    'type' => 'string',
                ],
                'to'    => [
                    'type' => 'string',
                ],
            ],
            'required'   => [
                'label' => true,
            ],
        ]);
    }
}