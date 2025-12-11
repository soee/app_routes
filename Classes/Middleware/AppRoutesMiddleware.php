<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinso\AppRoutes\Service\ResponseCachingService;
use Sinso\AppRoutes\Service\Router;
use Sinso\AppRoutes\Service\Tsfe;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Middleware for handling custom app routes.
 * Optimized for TYPO3 v13.4+ with lightweight TSFE initialization.
 */
final readonly class AppRoutesMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Router $router,
        private ResponseCachingService $responseCachingService,
        private Tsfe $tsfeService,
        private Context $context,
        private FrontendTypoScriptFactory $frontendTypoScriptFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $parameters = $this->router->getUrlMatcher()->match($request->getUri()->getPath());
        } catch (MethodNotAllowedException|ResourceNotFoundException) {
            // App routes did not match, continue with regular TYPO3 stack
            return $handler->handle($request);
        }

        $response = $this->handleRequestCached($parameters, $request);
        return $this->replaceWithNotModifiedResponse($request, $response);
    }

    private function handleRequestCached(array $parameters, ServerRequestInterface $request): ResponseInterface
    {
        $ingredients = [
            'routeParameters' => $parameters,
            'language' => (int)($request->getAttribute('language')?->getLanguageId() ?? $request->getQueryParams()['L'] ?? 0),
            'site' => $request->getAttribute('site')?->getIdentifier(),
        ];
        $cacheKey = 'appRoutes_' . md5(serialize($ingredients));

        if (!empty($parameters['cache']) && $this->responseCachingService->isCacheable($request)) {
            if ($this->responseCachingService->has($cacheKey)) {
                return $this->responseCachingService->serveFromCache($cacheKey);
            }
        }

        $response = $this->handleWithParameters(
            $parameters,
            $request->withQueryParams([...$request->getQueryParams(), ...$parameters])
        );

        if (!empty($parameters['cache']) && $response->getStatusCode() < 400) {
            $response = $this->responseCachingService->storeCacheEntry($request, $response, $cacheKey);
        }

        return $response;
    }

    private function handleWithParameters(array $parameters, ServerRequestInterface $request): ResponseInterface
    {
        $site = $request->getAttribute('site');
        if ($site === null || $site instanceof NullSite) {
            $sites = GeneralUtility::makeInstance(SiteFinder::class)->getAllSites();
            $site = $sites[array_key_first($sites)];
        }

        $language = $this->getLanguage($site, $request);
        $request = $request->withAttribute('language', $language);
        $this->context->setAspect('language', LanguageAspectFactory::createFromSiteLanguage($language));

        if (empty($parameters['handler'])) {
            throw new \RuntimeException('Route must return a handler parameter', 1604066046);
        }

        $handler = GeneralUtility::makeInstance($parameters['handler']);
        if (!$handler instanceof RequestHandlerInterface) {
            throw new \RuntimeException(
                'Route must return a handler parameter which implements ' . RequestHandlerInterface::class,
                1604066102
            );
        }

        if ($parameters['requiresTsfe'] ?? false) {
            $request = $this->bootFrontendController($site, $language, $request);
        }

        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $handler->handle($request);
    }

    private function replaceWithNotModifiedResponse(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        if ($response->getStatusCode() !== 200) {
            return $response;
        }

        if ($request->hasHeader('If-None-Match')
            && $response->hasHeader('ETag')
            && $request->getHeader('If-None-Match')[0] === $response->getHeader('ETag')[0]
        ) {
            return $response->withBody(new Stream(fopen('php://temp', 'r+')))->withStatus(304);
        }

        return $response;
    }

    private function bootFrontendController(
        SiteInterface $site,
        SiteLanguage $language,
        ServerRequestInterface $request
    ): ServerRequestInterface {
        if (isset($GLOBALS['TSFE'])) {
            return $request;
        }

        // Use the lightweight TSFE initialization
        $request = $this->tsfeService->initializeForRequest($request, $site->getRootPageId(), $language);
        $controller = $request->getAttribute('frontend.controller');
        $pageInformation = $request->getAttribute('frontend.page.information');

        // Initialize content object for rendering if needed
        $controller->newCObj($request);

        // Load real TypoScript from site configuration for handlers that need it
        // (e.g., for ContentObjectRenderer, TypoLink, Extbase plugin configuration)
        if ($pageInformation !== null && $pageInformation->getSysTemplateRows() !== []) {
            $frontendTypoScript = $this->frontendTypoScriptFactory->createSettingsAndSetupConditions(
                $site,
                $pageInformation->getSysTemplateRows(),
                $this->prepareConditionMatcherVariables($request, $pageInformation),
                null // Don't use TypoScript cache for API routes - they're already cached
            );

            // Load full setup for content rendering (needed by some services)
            $frontendTypoScript = $this->frontendTypoScriptFactory->createSetupConfigOrFullSetup(
                true, // needsFullSetup
                $frontendTypoScript,
                $site,
                $pageInformation->getSysTemplateRows(),
                $this->prepareConditionMatcherVariables($request, $pageInformation),
                '0', // pageType - using default page type for API
                null, // Don't use TypoScript cache
                $request
            );

            $request = $request->withAttribute('frontend.typoscript', $frontendTypoScript);
            $controller->config['config'] = $frontendTypoScript->getConfigArray();
        }

        return $request;
    }

    /**
     * Prepares variables available in TypoScript condition matching.
     */
    private function prepareConditionMatcherVariables(
        ServerRequestInterface $request,
        $pageInformation
    ): array {
        $topDownRootLine = $pageInformation->getRootLine();
        $localRootline = $pageInformation->getLocalRootLine();
        ksort($topDownRootLine);

        return [
            'request' => $request,
            'pageId' => $pageInformation->getId(),
            'page' => $pageInformation->getPageRecord(),
            'fullRootLine' => $topDownRootLine,
            'localRootLine' => $localRootline,
            'site' => $request->getAttribute('site'),
            'siteLanguage' => $request->getAttribute('language'),
            'tsfe' => $request->getAttribute('frontend.controller'),
        ];
    }

    private function getLanguage(SiteInterface $site, ServerRequestInterface $request): SiteLanguage
    {
        $languageUid = (int)($request->getQueryParams()['L'] ?? 0);

        foreach ($site->getLanguages() as $siteLanguage) {
            if ($siteLanguage->getLanguageId() === $languageUid) {
                return $siteLanguage;
            }
        }

        return $site->getDefaultLanguage();
    }
}