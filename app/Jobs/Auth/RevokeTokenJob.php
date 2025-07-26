<?php

namespace App\Jobs\Auth;

use App\Modules\Auth\AccessTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RevokeTokenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly string $token,
    )
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $service = app(AccessTokenService::class);

        if (!$service->revokeToken($this->token)) {
            $this->fail('Unable to revoke token.');
        }
    }
}
