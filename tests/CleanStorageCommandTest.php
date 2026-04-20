<?php

namespace InfinitieTechnologies\StorageCleaner\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

class CleanStorageCommandTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'storage-cleaner-command-' . uniqid();
        mkdir($this->tempPath, 0777, true);

        config()->set('storage-cleaner.enabled', true);
        config()->set('storage-cleaner.safety.confirm_before_delete', true);
        config()->set('storage-cleaner.safety.sample_limit', 5);
        config()->set('storage-cleaner.drivers.file.enabled', true);
        config()->set('storage-cleaner.drivers.file.paths', [$this->tempPath]);
        config()->set('storage-cleaner.drivers.file.delete_older_than_days', 15);
        config()->set('storage-cleaner.drivers.file.max_size_mb', null);
        config()->set('storage-cleaner.drivers.disk.enabled', false);
        config()->set('storage-cleaner.drivers.database.enabled', false);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->tempPath);

        parent::tearDown();
    }

    public function test_dry_run_json_includes_counts_and_sample_paths_without_deleting(): void
    {
        $oldFile = $this->tempPath . DIRECTORY_SEPARATOR . 'old.log';

        file_put_contents($oldFile, 'old');
        touch($oldFile, now()->subDays(20)->getTimestamp());

        Artisan::call('storage:clean', [
            '--dry-run' => true,
            '--json' => true,
        ]);

        $summary = json_decode(Artisan::output(), true);

        $this->assertSame(1, $summary['file']['matched']);
        $this->assertSame(1, $summary['file']['deleted']);
        $this->assertSame([$oldFile], $summary['file']['sample_paths']);
        $this->assertFileExists($oldFile);
    }

    public function test_interactive_cleanup_can_be_cancelled_by_confirmation(): void
    {
        $oldFile = $this->tempPath . DIRECTORY_SEPARATOR . 'old.log';

        file_put_contents($oldFile, 'old');
        touch($oldFile, now()->subDays(20)->getTimestamp());

        $this->artisan('storage:clean')
            ->expectsConfirmation('This will permanently delete files and records matching your storage-cleaner policies. Continue?', 'no')
            ->expectsOutput('Storage cleanup cancelled.')
            ->assertSuccessful();

        $this->assertFileExists($oldFile);
    }

    public function test_force_skips_confirmation_and_deletes_matching_files(): void
    {
        $oldFile = $this->tempPath . DIRECTORY_SEPARATOR . 'old.log';

        file_put_contents($oldFile, 'old');
        touch($oldFile, now()->subDays(20)->getTimestamp());

        $this->artisan('storage:clean', [
            '--force' => true,
            '--no-progress' => true,
        ])->assertSuccessful();

        $this->assertFileDoesNotExist($oldFile);
    }
}
