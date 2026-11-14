<?php

use Illuminate\Support\ServiceProvider;
use Liberu\Cms\Contracts\Api\ApiResourceRegistryInterface;

it('keeps an installed API adapter inactive when the API registry is absent', function (string $provider) {
    expect(is_subclass_of($provider, ServiceProvider::class))->toBeTrue();
    app()->offsetUnset(ApiResourceRegistryInterface::class);
    $routes = count(app('router')->getRoutes());
    (new $provider(app()))->register();
    expect(app()->bound(ApiResourceRegistryInterface::class))->toBeFalse()
        ->and(count(app('router')->getRoutes()))->toBe($routes);
})->with(function () {
    $providers = [];
    foreach (glob(dirname(__DIR__, 3).'/modules/module-cms-*-api/composer.json') as $path) {
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['extra']['laravel']['providers'] ?? [] as $provider) {
            $providers[$manifest['name'].' / '.$provider] = [$provider];
        }
    }
    ksort($providers);

    return $providers;
});
