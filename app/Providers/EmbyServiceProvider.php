<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\EmbyService;

class EmbyServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(EmbyService::class, function ($app) {
            return new EmbyService();
        });
    }

    public function boot()
    {
        // 移除用户观察者，改为依赖定时任务同步
        // User::observe(UserObserver::class); // 删除这行
        
        // 可以在这里添加其他启动逻辑
        $this->registerEmbyCommands();
    }

    /**
     * 注册Emby相关的Artisan命令
     */
    private function registerEmbyCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\EmbySync::class,
                \App\Console\Commands\EmbyCleanup::class,
            ]);
        }
    }
}