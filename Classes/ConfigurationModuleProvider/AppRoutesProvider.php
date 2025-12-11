<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\ConfigurationModuleProvider;

use Sinso\AppRoutes\Service\RoutesConfigurationLoader;
use TYPO3\CMS\Lowlevel\ConfigurationModuleProvider\AbstractProvider;

/**
 * Configuration module provider for app routes.
 * Displays loaded routes in the TYPO3 backend configuration module.
 */
final class AppRoutesProvider extends AbstractProvider
{
    public function __construct(
        private readonly RoutesConfigurationLoader $routesConfigurationLoader,
    ) {}

    public function getConfiguration(): array
    {
        return $this->routesConfigurationLoader->getRoutesConfiguration();
    }
}