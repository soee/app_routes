<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Package\PackageManager;

/**
 * Loads app routes configuration from YAML files.
 * Scans all active packages for AppRoutes.yaml configuration files within the Configuration directory.
 */
final readonly class RoutesConfigurationLoader
{
    private const string APP_ROUTES_YAML_PATH = 'Configuration/AppRoutes.yaml';
    private const string CACHE_KEY = 'appRoutes_configuration';

    public function __construct(
        #[Autowire(service: 'cache.app_routes')]
        private VariableFrontend $cache,
        private PackageManager $packageManager,
        private YamlFileLoader $yamlFileLoader,
    ) {}

    public function getRoutesConfiguration(): array
    {
        if ($this->cache->has(self::CACHE_KEY)) {
            return $this->cache->get(self::CACHE_KEY);
        }

        $routesConfiguration = [];
        foreach ($this->findAppRouteYamlFiles() as $yamlFile) {
            $routesConfiguration = array_merge_recursive(
                $routesConfiguration,
                $this->yamlFileLoader->load($yamlFile)
            );
        }

        $this->cache->set(self::CACHE_KEY, $routesConfiguration);

        return $routesConfiguration;
    }

    private function findAppRouteYamlFiles(): array
    {
        $paths = [];

        foreach ($this->packageManager->getActivePackages() as $package) {
            $possiblePath = $package->getPackagePath() . self::APP_ROUTES_YAML_PATH;
            if (is_readable($possiblePath)) {
                $paths[] = $possiblePath;
            }
        }

        return $paths;
    }
}
