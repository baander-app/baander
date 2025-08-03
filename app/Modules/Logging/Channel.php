<?php

namespace App\Modules\Logging;

use App\Extensions\EnumExt;

enum Channel: string
{
    use EnumExt;

    case Daily = 'daily';
    case Emergency = 'emergency';
    case Errorlog = 'errorlog';
    case Jobs = 'jobs';
    case MusicBrainz = 'musicbrainz';
    case MusicJobs = 'music_jobs';
    case Notifications = 'notifications';
    case Null = 'null';
    case Otel = 'otel';
    case OtelDebug = 'otel_debug';
    case Security = 'security';
    case Single = 'single';
    case Stack = 'stack';
    case Stderr = 'stderr';
    case Stdout = 'stdout';
    case Syslog = 'syslog';
}
