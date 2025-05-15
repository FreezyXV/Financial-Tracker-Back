<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        // 'App\Events\ExampleEvent' => [
        //     'App\Listeners\ExampleListener',
        // ],
    ];

    public function boot(): void
    {
        //
    }
}
