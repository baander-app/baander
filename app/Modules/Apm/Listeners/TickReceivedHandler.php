<?php

namespace App\Modules\Apm\Listeners;

use App\Modules\Apm\OctaneApmManager;
use Laravel\Octane\Events\TickReceived;

class TickReceivedHandler
{
    /**
     * Handle the event.
     *
     * @param TickReceived $event
     *
     * @return void
     */
    public function handle(TickReceived $event): void
    {
        /** @var OctaneApmManager $manager */
        $manager = $event->app->make(OctaneApmManager::class);

        $manager->beginTransaction('Tick', 'tick');
    }
}