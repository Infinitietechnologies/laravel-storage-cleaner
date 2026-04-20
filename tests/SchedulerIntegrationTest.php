<?php

namespace InfinitieTechnologies\StorageCleaner\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

class SchedulerIntegrationTest extends TestCase
{
    public function test_storage_clean_command_is_registered_with_artisan(): void
    {
        $this->assertArrayHasKey('storage:clean', Artisan::all());
    }

    public function test_storage_clean_command_is_registered_with_laravel_scheduler(): void
    {
        $events = app(Schedule::class)->events();

        $scheduledCommand = collect($events)->first(
            fn ($event): bool => str_contains($event->command, 'storage:clean')
        );

        $this->assertNotNull($scheduledCommand);
        $this->assertSame('0 0 * * *', $scheduledCommand->expression);
    }
}
