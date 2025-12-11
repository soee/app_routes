<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\ViewHelpers;

use Sinso\AppRoutes\Service\Router;
use Symfony\Component\Routing\Generator\UrlGenerator;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * ViewHelper for generating URLs for app routes.
 *
 * Example usage:
 * <approutes:route routeName="api.users.list" parameters="{id: 123}" />
 */
final class RouteViewHelper extends AbstractViewHelper
{
    public function initializeArguments(): void
    {
        $this->registerArgument('routeName', 'string', 'The name of the route', true);
        $this->registerArgument('parameters', 'array', 'Route parameters', false, []);
    }

    public static function renderStatic(
        array $arguments,
        \Closure $renderChildrenClosure,
        RenderingContextInterface $renderingContext
    ): string {
        $router = GeneralUtility::makeInstance(Router::class);
        return $router->getUrlGenerator()->generate(
            $arguments['routeName'],
            $arguments['parameters'],
            UrlGenerator::ABSOLUTE_URL
        );
    }
}