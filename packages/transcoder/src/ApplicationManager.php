<?php

namespace Baander\Transcoder;

use Amp\Redis\Connection\RedisConnector;
use Amp\Redis\RedisClient;
use Amp\Redis\RedisConfig;
use Amp\Redis\RedisSubscriber;
use App\Modules\Transcoder\TranscoderContextFactory;
use Baander\Common\Streaming\TranscodeOptions;
use League\Container\Container;
use League\Container\ReflectionContainer;
use Monolog\Logger;
use Amp\Log;
use function Amp\ByteStream\getStdout;
use function Amp\Redis\createRedisClient;
use function Amp\Redis\createRedisConnector;

class ApplicationManager
{
    private Container $container;

    public function __construct(TranscoderContext $transcoderContext)
    {
        $this->container = new Container();

        $this->container->delegate(new ReflectionContainer());

        $this->container->add(TranscoderContext::class, function () use ($transcoderContext) {
            return clone $transcoderContext;
        });

        $this->container->add(Application::class, function () {
            $context = $this->container->get(TranscoderContext::class);

            $this->container->add(RedisClient::class, function () use ($context) {
                $config = RedisConfig::fromUri("redis://{$context->redisHost}:{$context->redisPort}");
                if ($context->redisPassword) {
                    $config = $config->withPassword($context->redisPassword)
                        ->withDatabase($context->redisDb);
                }

                $this->container->add(RedisConfig::class, $config);

                return createRedisClient($config);
            });

            $this->container->add(RedisConnector::class, function () use ($context) {
                return createRedisConnector($context->container->get(RedisConfig::class));
            });

            $this->container->add(Logger::class, function () use ($context) {
                $handler = new Log\StreamHandler(getStdout());
                $handler->setFormatter(new Log\ConsoleFormatter);

                $context->logger->pushHandler($handler);

                return $context->logger;
            });

            return new Application($context);
        });
    }

    public function getApplication(): Application
    {
        return $this->container->get(Application::class);
    }
}