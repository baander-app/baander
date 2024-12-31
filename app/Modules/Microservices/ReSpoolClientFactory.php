<?php

namespace App\Modules\Microservices;

use Amp\Http\Client\{Connection\DefaultConnectionFactory, Connection\UnlimitedConnectionPool, HttpClientBuilder};
use Amp\Rpc\Client\RpcClient;
use Amp\Rpc\Interceptor\RoundRobinBalancer;
use Amp\Serialization\NativeSerializer;
use Amp\Socket\{ClientTlsContext, ConnectContext};
use App\Baander;
use ProxyManager\Factory\RemoteObjectFactory;

class ReSpoolClientFactory
{
    public function __construct(public readonly  string $url, public readonly string $certPath)
    {
    }

    public function make(): RespoolProxy
    {
        $serializer = new NativeSerializer;
        $context = (new ConnectContext)
            ->withTlsContext(new ClientTlsContext(Baander::getPeerName())->withCaFile($this->certPath));

        $httpConnectionPool = new UnlimitedConnectionPool(new DefaultConnectionFactory(null, $context));

        $httpClient = (new HttpClientBuilder)->usingPool($httpConnectionPool)->build();

        $factory = new RemoteObjectFactory(new RoundRobinBalancer([
            new RpcClient($this->url, $serializer, $httpClient),
        ]));

        return new RespoolProxy($factory);
    }
}