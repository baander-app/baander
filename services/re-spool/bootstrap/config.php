<?php

use Amp\Serialization\NativeSerializer;
use Amp\Serialization\Serializer;
use Amp\Socket\BindContext;
use Monolog\Logger;

return [
    'environment'                   => DI\env('APP_ENV', 'production'),
    'debug'                         => DI\env('APP_DEBUG', false),
    'timezone'                      => DI\env('APP_TIMEZONE', 'UTC'),
    'transcode_dir'                 => storage_path('transcodes'),
    'rpc.host'                      => '127.0.0.1',
    'rpc.port'                      => '43243',
    'rpc.registry'                  => DI\factory(function (\DI\Container $container) {
        $registry = new \Amp\Rpc\Server\RpcRegistry();

        $registry->register(\Baander\Common\Microservices\IStreamService::class, $container->get(\Baander\ReSpool\Rpc\StreamService::class));

        return $registry;
    }),
    BindContext::class              => DI\Factory(function () {
        $certificate = new Amp\Socket\Certificate(__DIR__ . '/../localhost.pem');
        return (new Amp\Socket\BindContext)
            ->withTlsContext((new Amp\Socket\ServerTlsContext)->withDefaultCertificate($certificate));
    }),
    Serializer::class               => Di\factory(function () {
        return new NativeSerializer;
    }),
    \Psr\Log\LoggerInterface::class => DI\factory(function () {
        $handler = new \Amp\Log\StreamHandler(Amp\ByteStream\getStdout());
        $handler->setFormatter(new \Amp\Log\ConsoleFormatter);

        $logger = new Logger('main');
        $logger->pushHandler($handler);

        return $logger;
    }),
];