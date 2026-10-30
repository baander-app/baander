<?php

declare(strict_types=1);

namespace App\Shared\Application\LiveUpdates;

enum LiveFamily: string
{
    case Catalog = 'catalog';
    case Activity = 'activity';
    case Playlists = 'playlists';
    case Libraries = 'libraries';
    case Notifications = 'notifications';
    case Account = 'account';
    case Preferences = 'preferences';
    case Devices = 'devices';
    case Sessions = 'sessions';
    case Radio = 'radio';
    case Lyrics = 'lyrics';
    case Recommendations = 'recommendations';
    case AdminUsers = 'admin-users';
    case AdminSettings = 'admin-settings';
    case AdminJobs = 'admin-jobs';
    case AdminRuntime = 'admin-runtime';
    case AdminAlerts = 'admin-alerts';
    case AdminSecurity = 'admin-security';
    case AdminMetadata = 'admin-metadata';
    case AdminMedia = 'admin-media';
    case DeploymentDefinitions = 'deployment-definitions';
}
