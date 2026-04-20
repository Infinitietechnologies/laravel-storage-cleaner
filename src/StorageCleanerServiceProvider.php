<?php

namespace InfinitieTechnologies\StorageCleaner;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use InfinitieTechnologies\StorageCleaner\Console\CleanStorageCommand;
use InfinitieTechnologies\StorageCleaner\Services\CleanerService;

class StorageCleanerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/storage-cleaner.php', 'storage-cleaner');

        $this->app->singleton(CleanerService::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/storage-cleaner.php' => config_path('storage-cleaner.php'),
        ], 'storage-cleaner-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CleanStorageCommand::class,
            ]);
        }

        $this->app->booted(function (): void {
            if (! config('storage-cleaner.enabled') || ! config('storage-cleaner.schedule.enabled')) {
                return;
            }

            $event = app(Schedule::class)->command('storage:clean');
            $frequency = config('storage-cleaner.schedule.frequency', 'daily');

            match ($frequency) {
                'daily' => $event->daily(),
                'weekly' => $event->weekly(),
                default => $event->cron(config('storage-cleaner.schedule.cron', '0 0 */15 * *')),
            };
        });
    }
}
