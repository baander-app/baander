<?php

namespace App\Modules\Octane;

use Closure;
use Illuminate\Http\Request;
use Laravel\Octane\Contracts\Client;
use Laravel\Octane\Contracts\Worker;
use Laravel\Octane\OctaneResponse;
use Laravel\Octane\RequestContext;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Whoops\Handler\PlainTextHandler;
use Whoops\Run;

class EmergencyWorker implements Worker
{
    protected Run $whoops;

    public function __construct(
        protected Client    $client,
        protected Throwable $exception,
        protected array     $serverState,
    )
    {
        $this->whoops = new Run();
        $this->whoops->allowQuit(false);
        $this->whoops->writeToOutput(false);
        $this->whoops->pushHandler(new PlainTextHandler());
    }

    public function handle(Request $request, RequestContext $context): void
    {
        $response = new Response();
        $response->setStatusCode(500);
        $response->headers->add(['Content-Type' => 'text/plain']);
        if ($this->showError()) {
            $response->setContent($this->whoops->handleException($this->exception));
        } else {
            $response->setContent('Server error.');
        }

        $this->client->respond($context, new OctaneResponse($response));
    }

    private function showError(): bool
    {
        return $this->serverState['octaneConfig']['swoole']['show_fatal_error'] ?? false;
    }

    public function boot(): void
    {
    }

    public function handleTask($data)
    {
    }

    public function terminate(): void
    {
    }

    public function handleTick(): void
    {
    }

    public function onRequestHandled(Closure $callback): self
    {
        return $this;
    }
}