<?php

namespace App\Services\Widgets\Builders;

use App\Models\User;
use App\Services\Widgets\Types\{DashboardNavigationFeature, DashboardNavigationLink};
use JetBrains\PhpStorm\ArrayShape;

class DashboardMenuBuilder implements BuilderInterface
{
    /** @var User */
    private $user;

    #[ArrayShape([
        'user' => 'mixed',
    ])]
    public function __construct(array $context)
    {
        $this->user = $context['user'];
    }

    public function build(): array
    {
        $features = [];

        $docs = $this->createDocumentationFeature($this->user->isAdmin());
        if ($docs) {
            $features[] = $docs;
        }

        $systemMonitoring = $this->createSystemMonitoringFeature($this->user->isAdmin());
        if ($systemMonitoring) {
            $features[] = $systemMonitoring;
        }

        return $features;
    }

    private function createDocumentationFeature(bool $isAdmin): ?DashboardNavigationFeature
    {
        $links = [];

        if ($isAdmin) {
            $links[] = new DashboardNavigationLink(
                label: __('API Docs'),
                href: route('scramble.docs.ui'),
            );
        }


        if (count($links) > 0) {
            return new DashboardNavigationFeature(
                title: __('Documentation'),
                links: $links,
                iconName: 'oui:documentation',
            );
        }

        return null;
    }

    private function createSystemMonitoringFeature(bool $isAdmin)
    {
        if (!$isAdmin) {
            return null;
        }

        $links = [];

        $links[] = new DashboardNavigationLink(
            label: 'Horizon',
            href: route('horizon.index'),
        );

        $links[] = new DashboardNavigationLink(
            label: 'Pulse',
            href: route('pulse'),
        );

        return new DashboardNavigationFeature(
            title: __('System monitoring'),
            links: $links,
            iconName: 'oui:documentation',
        );
    }
}