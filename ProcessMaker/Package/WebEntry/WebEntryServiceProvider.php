<?php

namespace ProcessMaker\Package\WebEntry;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class WebEntryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'webentry');

        Route::middleware('web')
            ->group(__DIR__ . '/routes/web.php');
    }
}