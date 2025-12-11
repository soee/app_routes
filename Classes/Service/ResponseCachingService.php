<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Response caching service for app routes.
 * Handles HTTP caching of API route responses.
 */
final readonly class ResponseCachingService
{
    private const CACHEABLE_REQUEST_METHODS = ['GET', 'HEAD'];

    public function __construct(
        #[Autowire(service: 'cache.pages')]
        private VariableFrontend $cache,
    ) {}

    public function isCacheable(ServerRequestInterface $request): bool
    {
        return in_array($request->getMethod(), self::CACHEABLE_REQUEST_METHODS, true);
    }

    public function has(string $cacheKey): bool
    {
        return $this->cache->has($cacheKey);
    }

    public function serveFromCache(string $cacheKey): ResponseInterface
    {
        $cacheEntry = $this->cache->get($cacheKey);
        $response = $cacheEntry['response'];
        $body = new Stream('php://temp', 'rw');
        $body->write($cacheEntry['responseBody']);
        $response = $response->withBody($body);

        if (!empty($GLOBALS['TYPO3_CONF_VARS']['FE']['debug'])) {
            $response = $response->withAddedHeader(
                'X-APP-ROUTES-CACHED',
                date(
                    $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] . ' ' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'],
                    $cacheEntry['tstamp']
                )
            );
        }

        return $response;
    }

    public function storeCacheEntry(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $cacheKey
    ): ResponseInterface {
        if (!in_array($request->getMethod(), self::CACHEABLE_REQUEST_METHODS, true)) {
            if (!empty($GLOBALS['TYPO3_CONF_VARS']['FE']['debug'])) {
                $response = $response->withAddedHeader('X-APP-ROUTES-UNCACHED', 'uncacheable request method');
            }
            return $response;
        }

        $lifetime = null; // Use the default lifetime of the cache
        $cacheControlHeaders = $response->getHeader('Cache-Control');

        foreach ($cacheControlHeaders as $cacheControlHeader) {
            $valueParts = GeneralUtility::trimExplode(',', $cacheControlHeader);
            foreach ($valueParts as $valuePart) {
                if ($valuePart === 'no-cache' || $valuePart === 'no-store') {
                    if (!empty($GLOBALS['TYPO3_CONF_VARS']['FE']['debug'])) {
                        $response = $response->withAddedHeader(
                            'X-APP-ROUTES-UNCACHED',
                            'caching prohibited by Cache-Control header'
                        );
                    }
                    return $response;
                }

                [$key, $value] = GeneralUtility::trimExplode('=', $valuePart);
                if ($key === 'max-age') {
                    $lifetime = (int)$value;
                }
            }
        }

        $cacheTags = $GLOBALS['TSFE']?->getPageCacheTags() ?? [];
        $cacheEntry = [
            'response' => $response,
            'responseBody' => (string)$response->getBody(),
            'tstamp' => $GLOBALS['EXEC_TIME'],
        ];

        if (!empty($GLOBALS['TYPO3_CONF_VARS']['FE']['debug'])) {
            $response = $response->withAddedHeader('X-APP-ROUTES-CACHED', 'now');
            if ($cacheTags !== []) {
                $response = $response->withAddedHeader('X-APP-ROUTES-CACHED-WITH-TAGS', implode(',', $cacheTags));
            }
        }

        $this->cache->set($cacheKey, $cacheEntry, $cacheTags, $lifetime);

        return $response;
    }
}