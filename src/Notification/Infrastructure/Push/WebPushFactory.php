<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Push;

use GuzzleHttp\HandlerStack;
use Minishlink\WebPush\WebPush;

final readonly class WebPushFactory
{
    public function __construct(private PushTransport $transport)
    {
    }

    /** @param array<string, mixed> $auth */
    public function create(array $auth): WebPush
    {
        $handlers = HandlerStack::create($this->transport);
        // Never install a redirect path around the destination-enforcing handler.
        $handlers->remove('allow_redirects');

        return new WebPush($auth, clientOptions: [
            'handler' => $handlers,
            'allow_redirects' => false,
            'proxy' => '',
            'verify' => true,
        ]);
    }
}
