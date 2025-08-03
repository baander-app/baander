<?php

namespace PHPSTORM_META {

    // Tell PhpStorm that properties with LogChannel attribute are provided by the container
    override(\Psr\Log\LoggerInterface::class, map([
        '' => '@|\App\Modules\Logging\Attributes\LogChannel',
    ]));

    // Alternative approach - tell PhpStorm about the service provider injection
    expectedArguments(
        \App\Modules\Logging\Attributes\LogChannel::__construct(),
        0,
        argumentsSet('logChannels')
    );

    registerArgumentsSet('logChannels',
        \App\Modules\Logging\Channel::Daily,
        \App\Modules\Logging\Channel::Emergency,
        \App\Modules\Logging\Channel::Errorlog,
        \App\Modules\Logging\Channel::Jobs,
        \App\Modules\Logging\Channel::MusicBrainz,
        \App\Modules\Logging\Channel::MusicJobs,
        \App\Modules\Logging\Channel::Notifications,
        \App\Modules\Logging\Channel::Null,
        \App\Modules\Logging\Channel::Otel,
        \App\Modules\Logging\Channel::OtelDebug,
        \App\Modules\Logging\Channel::Security,
        \App\Modules\Logging\Channel::Single,
        \App\Modules\Logging\Channel::Stack,
        \App\Modules\Logging\Channel::Stderr,
        \App\Modules\Logging\Channel::Stdout,
        \App\Modules\Logging\Channel::Syslog,
    );
}