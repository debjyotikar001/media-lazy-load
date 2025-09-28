<?php

namespace Debjyotikar001\MediaLazyLoad;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Debjyotikar001\MediaLazyLoad\Middleware\MedLazyLoad;

class MediaLazyLoadServiceProvider extends ServiceProvider
{
  /**
   * Bootstrap the application events.
   */
  public function boot(Router $router)
  {
    // Custom directive for @lazyHtml
    Blade::directive('lazyHtml', function () {
      return '<template class="lazy-html" data-lazyhtml="true">';
    });

    // Custom directive for @endLazyHtml
    Blade::directive('endLazyHtml', function () {
      return '</template data-lazyhtml>';
    });
    
    $router->aliasMiddleware('medialazyload', MedLazyLoad::class);
    $this->publishes([
      __DIR__.'/../config/medialazyload.php' => config_path('medialazyload.php'),
    ], 'config');
  }

  /**
   * Register the service provider.
   */
  public function register()
  {
    $this->mergeConfigFrom(__DIR__.'/../config/medialazyload.php', 'medialazyload.php');
  }
}
