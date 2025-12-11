# TYPO3 App Routes

## Route any URL to your application.

You use this package if you want to route certain URLs directly to your controllers, completely ignoring the TYPO3 page routing.<br>
This is especially useful to create REST APIs.

**Compatibility:** TYPO3 v13.4+ with PHP 8.3+

### Installation

```bash
composer require sinso/app-routes
```

### Configuration

This package will look for `Configuration/AppRoutes.yaml` files in any loaded extension. Creating this file is all you need to get started:

```yaml
myApp:
  prefix: /myApi/v2
  routes:
    - name: orders
      path: /orders
      defaults:
        handler: MyVendor\MyExtension\Api\OrdersEndpoint
    - name: order
      path: /order/{orderUid}
      defaults:
        handler: MyVendor\MyExtension\Api\OrderEndpoint
```

The class you provide as `defaults.handler` has to implement `\Psr\Http\Server\RequestHandlerInterface`.
The routing parameters will be available in `$request->getQueryParams()`.

### Handler Examples

#### Simple Handler

```php
<?php

declare(strict_types=1);

namespace MyVendor\MyExtension\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

final readonly class OrderEndpoint implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $orderUid = (int)$request->getQueryParams()['orderUid'];

        // Your logic here
        // ...

        $orderData = ['uid' => $orderUid, 'status' => 'pending'];

        return new JsonResponse($orderData);
    }
}
```

#### Handler with Dependency Injection

TYPO3's dependency injection container automatically resolves constructor dependencies:

```php
<?php

declare(strict_types=1);

namespace MyVendor\MyExtension\Api;

use MyVendor\MyExtension\Domain\Repository\OrderRepository;
use MyVendor\MyExtension\Service\OrderService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

final readonly class OrderEndpoint implements RequestHandlerInterface
{
    public function __construct(
        private OrderRepository $orderRepository,
        private OrderService $orderService,
        private PersistenceManagerInterface $persistenceManager,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $orderUid = (int)$request->getQueryParams()['orderUid'];
        $order = $this->orderRepository->findByUid($orderUid);

        if ($order === null) {
            return new JsonResponse(
                ['error' => 'Order not found'],
                404
            );
        }

        $this->orderService->processOrder($order);
        $this->persistenceManager->persistAll();

        return new JsonResponse([
            'uid' => $order->getUid(),
            'status' => $order->getStatus(),
            'total' => $order->getTotal(),
        ]);
    }
}
```

### Options

Under the hood [symfony/routing](https://github.com/symfony/routing) is used.

Everything that is available as YAML configuration option in `symfony/routing` should work with this package out of the box.

This package offers these additional options:

* `defaults.cache: true` - If true, then responses are cached (see more details below). (default: `false`)
* `defaults.requiresTsfe: true` - If true, then `$GLOBALS['TSFE']` will be initialized before your handler is called (default: `false`).

### When to Use `requiresTsfe`

Set `requiresTsfe: true` when your handler needs:
- Full TypoScript configuration and rendering
- ContentObjectRenderer (cObj) functionality
- TypoLink URL generation based on TypoScript
- Extbase functionality that depends on TypoScript
- TSFE-based services (like some URL/link generators)

For simple API endpoints that only use repositories and services, you can leave it as `false` for better performance.

### Generate Route URLs

To generate URLs you can use the `Sinso\AppRoutes\Service\Router`:

```php
use Sinso\AppRoutes\Service\Router;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$router = GeneralUtility::makeInstance(Router::class);
$url = $router->getUrlGenerator()->generate('myApp.order', ['orderUid' => 42]);
// https://www.example.com/myApi/v2/order/42
```

If you need to generate a URL in a Fluid template, there's also a ViewHelper for that:

```html
<html
	xmlns:approutes="http://typo3.org/ns/Sinso/AppRoutes/ViewHelpers"
	data-namespace-typo3-fluid="true"
>

<approutes:route routeName="myApp.order" parameters="{orderUid: 42}" />

</html>
```

### Configuration Module

In the TYPO3 backend configuration module (Admin Tools → Configuration), there's an entry "App Routes" that shows all configured routes.

**Available in TYPO3 v13+**

### Server Side Caching

* Caching can be enabled per route via configuration `defaults.cache: true`.
* The TYPO3 `pages` cache is used to cache API responses.
* Your request handler will not be called at all if the request can be served from cache.
* Only responses for `GET` and `HEAD` requests can be cached.
* The cache key is built from all query parameters that were matched by your route.
* If `$GLOBALS['TSFE']` was involved in handling the request and cache tags were added to it via `$tsfe->addCacheTags($tags)`, those are applied to the cache entry.
* If you have `$GLOBALS['TYPO3_CONF_VARS']['FE']['debug']` enabled, the HTTP response contains headers describing its cache status.
* Responses with `Cache-Control: no-cache` or `Cache-Control: no-store` are not cached.
* Responses with `Cache-Control: max-age=300` overwrite the default TTL of the `pages` cache.

### ETag

Setting an `ETag` header in your response will enable conditional requests, i.e. the client doesn't need to download the response body if it already has the latest version.

* If your response contains an `ETag` header and it matches the `If-None-Match` header of the request, the response HTTP status will be `304 Not Modified` and the response body will be empty.

### Advanced Configuration

#### Custom Cache Configuration

The package uses the `app_routes` cache for route configuration. You can customize it in your system configuration file `config/system/additional.php` or `config/system/settings.php`:

```php
<?php

return [
    'SYS' => [
        'caching' => [
            'cacheConfigurations' => [
                'app_routes' => [
                    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
                    'backend' => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
                    'options' => [
                        'defaultLifetime' => 60 * 60 * 24 * 30, // 30 days
                    ],
                ],
            ],
        ],
    ],
];
```

**Note:** The default configuration in the package's `ext_localconf.php` is already optimized. Only customize if you need specific cache backend or lifetime settings.

#### Clearing Caches

After modifying your `AppRoutes.yaml` files, clear the following caches:
- System caches (to reload route configuration)
- Pages cache (if using response caching)

### TYPO3 v13 Improvements

This version has been modernized for TYPO3 v13.4 with:
- ✅ Simplified and lightweight TSFE initialization for API routes
- ✅ Modern dependency injection with `#[Autowire]` attributes
- ✅ Proper TypoScript loading via `FrontendTypoScriptFactory`
- ✅ PHP 8.4 typed properties and readonly classes
- ✅ Optimized performance with minimal overhead
- ✅ Full PSR-12 coding standards compliance

### Troubleshooting

**Routes not working?**
1. Clear all caches (System caches + Pages cache)
2. Check that your `Configuration/AppRoutes.yaml` file is in the correct location
3. Verify your handler implements `RequestHandlerInterface`

**Need TSFE but getting errors?**
1. Set `requiresTsfe: true` in your route configuration
2. Ensure you have TypoScript templates set up on your root page or imported through Sets

**Performance issues?**
1. Only use `requiresTsfe: true` when absolutely necessary
2. Enable route caching with `cache: true` for GET/HEAD requests
3. Use ETags for conditional requests
