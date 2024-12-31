<?php

namespace App\Packages\Nanoid;

use Hidehalo\Nanoid\Client;

class NanoIdService
{
    private Client $client;

    public function __construct(Client $client = null)
    {
        $this->client = new Client();
    }

    public function generateId(int $size = 21)
    {
        return $this->client->generateId($size, Client::MODE_DYNAMIC);
    }
}
