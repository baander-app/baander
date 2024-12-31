<?php

return [
    'model'               => \App\Packages\JobStatus\JobStatus::class,
    'event_manager'       => \App\Packages\JobStatus\EventManagers\DefaultEventManager::class,
    'database_connection' => null,
];