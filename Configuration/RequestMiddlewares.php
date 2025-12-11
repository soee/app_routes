<?php

declare(strict_types=1);

use Sinso\AppRoutes\Middleware\AppRoutesMiddleware;

/**
 * Request middleware configuration for app routes.
 * Registers the app routes middleware in the frontend stack.
 */
return [
    'frontend' => [
        'sinso/app-routes/route' => [
            'target' => AppRoutesMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                'typo3/cms-frontend/base-redirect-resolver',
            ],
        ],
    ],
];