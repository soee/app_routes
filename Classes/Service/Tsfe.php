<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TimeTracker\TimeTracker;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Page\PageInformationFactory;

final readonly class Tsfe
{
    public function __construct(
        private SiteFinder $siteFinder,
        private Context $context,
        private PageInformationFactory $pageInformationFactory,
        private TimeTracker $timeTracker,
    ) {}

    /**
     * Creates a lightweight TSFE controller for the given page and language.
     * This is optimized for API routes and skips heavy rendering initialization.
     *
     * @throws SiteNotFoundException
     */
    public function getTsfeByPageIdAndLanguageId(
        int $pageId,
        int $languageId = 0,
    ): TypoScriptFrontendController {
        $site = $this->siteFinder->getSiteByPageId($pageId);
        $language = $site->getLanguageById($languageId);

        // Create minimal controller - TYPO3 v13 simplified constructor
        $controller = GeneralUtility::makeInstance(TypoScriptFrontendController::class);
        $controller->sys_page = GeneralUtility::makeInstance(PageRepository::class);
        $controller->id = $pageId;

        return $controller;
    }

    /**
     * Initializes a complete TSFE with request context for content rendering.
     * Use this when you need full TypoScript and cObject functionality.
     *
     * @throws SiteNotFoundException
     */
    public function initializeForRequest(
        ServerRequestInterface $request,
        int $pageId,
        SiteLanguage $language,
    ): ServerRequestInterface {
        $site = $request->getAttribute('site');
        $pageArguments = GeneralUtility::makeInstance(PageArguments::class, $pageId, '0', []);

        try {
            $this->timeTracker->push('AppRoutes: Create PageInformation');
            $pageInformation = $this->pageInformationFactory->create(
                $request
                    ->withAttribute('routing', $pageArguments)
                    ->withAttribute('site', $site)
                    ->withAttribute('language', $language)
            );
            $this->timeTracker->pull();
        } catch (\Throwable $e) {
            $this->timeTracker->pull();
            throw new \RuntimeException(
                'Failed to create page information for page ' . $pageId . ': ' . $e->getMessage(),
                1734000001,
                $e
            );
        }

        $request = $request->withAttribute('frontend.page.information', $pageInformation);

        // Create and initialize controller with minimal setup
        $controller = GeneralUtility::makeInstance(TypoScriptFrontendController::class);
        $controller->id = $pageInformation->getId();
        $controller->page = $pageInformation->getPageRecord();
        $controller->rootLine = $pageInformation->getRootLine();
        $controller->sys_page = GeneralUtility::makeInstance(PageRepository::class);

        // Initialize minimal services
        $controller->initializePageRenderer($request);
        $controller->initializeLanguageService($request);

        $request = $request->withAttribute('frontend.controller', $controller);
        $GLOBALS['TSFE'] = $controller;

        return $request;
    }

    /**
     * @throws SiteNotFoundException
     */
    public function getSiteByPageId(int $pageId): Site
    {
        return $this->siteFinder->getSiteByPageId($pageId);
    }
}
