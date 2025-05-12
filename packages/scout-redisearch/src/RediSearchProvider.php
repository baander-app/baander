<?php

namespace Baander\ScoutRediSearch;

use Baander\RedisStack\RedisStack;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;

class RediSearchProvider extends ServiceProvider
{
    public function boot()
    {
        $this->app[EngineManager::class]->extend('redisearch', static function ($app) {
            return new RediSearch($app->get('scout.redisearch'));
        });

        $this->registerMacros();
    }

    public function register(): void
    {
        $this->app->singleton(RediSearch::class, static function () {
            $client = new RedisStack(Config::get('scout.redisearch'));

            return new RediSearch($client);
        });

        $this->app->alias(RediSearch::class, 'redisearch');
    }

    private function registerMacros(): void
    {
        Builder::mixin($this->app->make(BuilderMixin::class));
    }
}