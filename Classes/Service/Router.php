<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\ServerRequestFactory;

/**
 * Router service for app routes.
 * Manages route collection and provides URL generation and matching.
 */
final readonly class Router
{
    private const string CACHE_KEY = 'appRoutes_Router_routes';

    public function __construct(
        #[Autowire(service: 'cache.runtime')]
        private VariableFrontend $cache,
        private RoutesConfigurationLoader $routeFilesLoader,
    ) {}

    public function getRoutes(): RouteCollection
    {
        if ($this->cache->has(self::CACHE_KEY)) {
            return $this->cache->get(self::CACHE_KEY);
        }

        $routes = new RouteCollection();
        foreach ($this->routeFilesLoader->getRoutesConfiguration() as $appName => $appRoutesConfiguration) {
            $prefix = $appRoutesConfiguration['prefix'] ?? '';
            $routes = $this->populateRouteCollection($routes, $appRoutesConfiguration['routes'], $appName, $prefix);
        }

        $this->cache->set(self::CACHE_KEY, $routes);

        return $routes;
    }

    public function getUrlGenerator(): UrlGenerator
    {
        return new UrlGenerator($this->getRoutes(), $this->createRequestContext());
    }

    public function getUrlMatcher(): UrlMatcher
    {
        return new UrlMatcher($this->getRoutes(), $this->createRequestContext());
    }

    private function createRequestContext(): RequestContext
    {
        if (Environment::isCli()) {
            return new RequestContext();
        }

        $request = ServerRequestFactory::fromGlobals();
        $host = (string)idn_to_ascii($request->getUri()->getHost());

        return new RequestContext(
            '',
            $request->getMethod(),
            $host,
            $request->getUri()->getScheme(),
            80,
            443,
            $request->getUri()->getPath()
        );
    }

    private function populateRouteCollection(
        RouteCollection $routes,
        array $routesConfiguration,
        string $namePrefix,
        string $pathPrefix
    ): RouteCollection {
        foreach ($routesConfiguration as $routeConfiguration) {
            $route = new Route(
                $pathPrefix . $routeConfiguration['path'],
                $routeConfiguration['defaults'] ?? [],
                $routeConfiguration['requirements'] ?? [],
                $routeConfiguration['options'] ?? [],
                $routeConfiguration['host'] ?? '',
                $routeConfiguration['schemes'] ?? [],
                $routeConfiguration['methods'] ?? [],
                $routeConfiguration['condition'] ?? ''
            );
            $routes->add($namePrefix . '.' . $routeConfiguration['name'], $route);
        }

        return $routes;
    }
}