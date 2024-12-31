<?php

namespace App\Services\Widgets\Builders;

use App\Models\User;
use App\Services\Widgets\Types\{MainNavBar, MainNavBarLink, MainNavBarSection};
use JetBrains\PhpStorm\ArrayShape;

class MainNavBarBuilder implements BuilderInterface
{
    protected MainNavBar $mainNavBar;
    /** @var User */
    private $user;

    #[ArrayShape([
        'user' => 'mixed',
    ])] public function __construct(array $context)
    {
        $this->user = $context['user'];
        $this->mainNavBar = new MainNavBar();
    }

    public function build(): array
    {
        if ($this->user->isAdmin()) {
            $this->mainNavBar->setFooter($this->getLinksSection());
        }

        return $this->mainNavBar->toArray();
    }

    private function getLinksSection()
    {
        $links = [];

        $links[] = new MainNavBarLink(
            label: __('API Docs'),
            href: route('scramble.docs.ui'),
            to: null,
        );

        $links[] = new MainNavBarLink(
            label: 'Horizon',
            href: route('horizon.index'),
            to: null,
        );

        $links[] = new MainNavBarLink(
            label: 'Pulse',
            href: route('pulse'),
            to: null,
        );

        $links[] = new MainNavBarLink(
            label: 'Clockwork',
            href: secure_url('/clockwork/app'),
            to: null,
        );

        $links[] = new MainNavBarLink(
            label: 'Github',
            href: 'https://github.com/baander-app/baander',
            to: null,
        );

        return new MainNavBarSection(
            label: 'Links',
            iconName: 'oui:documentation',
            links: $links,
        );
    }
}