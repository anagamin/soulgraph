<?php

namespace App\Providers;

use App\Infrastructure\AI\Contracts\AiProviderInterface;
use App\Infrastructure\AI\Providers\GptunnelProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiProviderInterface::class, GptunnelProvider::class);
    }

    public function boot(): void
    {
        //
    }
}
