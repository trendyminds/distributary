<?php

namespace Trendyminds\Distributary;

use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;
use Trendyminds\Distributary\Http\Controllers\DistributaryController;

class ServiceProvider extends AddonServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/distributary.php',
            'distributary'
        );
    }

    public function bootAddon(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'distributary');

        $this->publishes([
            __DIR__.'/../config/distributary.php' => config_path('distributary.php'),
        ], 'distributary-config');

        $this->registerUtility();
    }

    protected function registerUtility(): void
    {
        Utility::extend(function () {
            $displayName = config('distributary.display_name');

            Utility::register('distributary')
                ->action([DistributaryController::class, 'show'])
                ->title($displayName)
                ->navTitle($displayName)
                ->icon('wand')
                ->description('Upload a Google Docs Web Page (.zip) export and have AI map it into Statamic page blocks.')
                ->routes(function ($router) {
                    $router->post('upload', [DistributaryController::class, 'upload'])->name('upload');
                    $router->get('processing/{importId}', [DistributaryController::class, 'processing'])->name('processing');
                    $router->get('status/{importId}', [DistributaryController::class, 'status'])->name('status');
                    $router->get('preview/{importId}', [DistributaryController::class, 'preview'])->name('preview');
                    $router->post('preview/{importId}/confirm', [DistributaryController::class, 'confirm'])->name('confirm');
                });
        });
    }
}
