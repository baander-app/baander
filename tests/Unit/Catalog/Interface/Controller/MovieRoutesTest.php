<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Controller;

use App\Catalog\Interface\Controller\MovieController;
use App\Catalog\Interface\Controller\MovieDirectorController;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

final class MovieRoutesTest extends TestCase
{
    public function testStaticDirectorRoutesAndMovieIdentifiersAreUnambiguous(): void
    {
        $routes = new RouteCollection();
        $loader = new AttributeRouteControllerLoader('prod');
        $routes->addCollection($loader->load(MovieController::class, 'attribute'));
        $routes->addCollection($loader->load(MovieDirectorController::class, 'attribute'));
        $context = new RequestContext();
        $matcher = new UrlMatcher($routes, $context);
        $id = 'V1StGXR8_Z5jdHi6B-myT';
        self::assertSame('movie_director_index', $matcher->match('/api/movies/directors')['_route']);
        self::assertSame('movie_director_show', $matcher->match('/api/movies/directors/' . $id)['_route']);
        self::assertSame('movie_director_movies', $matcher->match('/api/movies/directors/' . $id . '/movies')['_route']);
        self::assertSame('movie_show', $matcher->match('/api/movies/' . $id)['_route']);
        foreach (['PATCH' => 'movie_update', 'DELETE' => 'movie_destroy'] as $method => $route) {
            $context->setMethod($method);
            self::assertSame($route, $matcher->match('/api/movies/' . $id)['_route']);
        }
        $context->setMethod('GET');
        $this->expectException(ResourceNotFoundException::class);
        $matcher->match('/api/movies/not-an-id');
    }
}
