<?php

namespace Anassrojea\Laracrawler;

use Anassrojea\Laracrawler\Commands\FinalizeSitemapCommand;
use Illuminate\Support\ServiceProvider;
use Anassrojea\Laracrawler\Commands\GenerateSitemapCommand;

class LaraCrawlerServiceProvider extends ServiceProvider
{
    /**
     * Merge the sitemap configuration into the application.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/sitemap.php', 'sitemap');
    }

    /**
     * Bootstrap any application services.
     *
     * When the application is running in the console, we will publish the
     * sitemap configuration file and register the commands with the application
     * so that they can be executed.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/sitemap.php' => config_path('sitemap.php'),
            ], 'laracrawler-config');

            $this->commands([
                GenerateSitemapCommand::class,
                FinalizeSitemapCommand::class,
            ]);
        }
    }
}
