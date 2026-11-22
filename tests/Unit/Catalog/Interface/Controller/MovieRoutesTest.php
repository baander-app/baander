<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Interface\Controller;

use App\Catalog\Interface\Controller\MovieController;
use App\Catalog\Interface\Controller\MovieDirectorController;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;

final class MovieRoutesTest extends TestCase
{
    /**
     * The director listing wins over a movie ID. Any other value reaches the movie action, which
     * answers a malformed public ID itself: 404 on GET, 422 on PATCH and DELETE.
     */
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
        foreach (['GET' => 'movie_show', 'PATCH' => 'movie_update', 'DELETE' => 'movie_destroy'] as $method => $route) {
            $context->setMethod($method);
            self::assertSame($route, $matcher->match('/api/movies/' . $id)['_route']);
            self::assertSame($route, $matcher->match('/api/movies/not-an-id')['_route']);
        }
    }
}
