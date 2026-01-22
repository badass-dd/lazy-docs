<?php

namespace Badass\ControllerPhpDocGenerator;

use Illuminate\Support\ServiceProvider;
use Badass\ControllerPhpDocGenerator\Commands\GenerateControllerDocsCommand;

class ControllerPhpDocGeneratorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                GenerateControllerDocsCommand::class,
            ]);
        }

        $this->publishes([
            __DIR__.'/../config/phpdoc-generator.php' => config_path('phpdoc-generator.php'),
        ], 'phpdoc-generator-config');
    }
}
