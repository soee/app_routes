<?php

declare(strict_types=1);

defined('TYPO3') || die();

// Configure app_routes cache
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['app_routes'] ??= [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend' => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
    'options' => [
        'defaultLifetime' => 60 * 60 * 24 * 7, // Cache route configuration for a week
    ],
];