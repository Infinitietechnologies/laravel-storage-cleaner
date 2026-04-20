<?php

namespace InfinitieTechnologies\StorageCleaner\Tests;

use Illuminate\Filesystem\Filesystem;
use InfinitieTechnologies\StorageCleaner\Services\CleanerService;

class CleanerServiceTest extends TestCase
{
    private string $tempPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'storage-cleaner-' . uniqid();
        mkdir($this->tempPath, 0777, true);

        config()->set('storage-cleaner.enabled', true);
        config()->set('storage-cleaner.drivers.file.enabled', true);
        config()->set('storage-cleaner.drivers.file.paths', [$this->tempPath]);
        config()->set('storage-cleaner.drivers.file.delete_older_than_days', 15);
        config()->set('storage-cleaner.drivers.file.max_size_mb', null);
        config()->set('storage-cleaner.drivers.database.enabled', false);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->tempPath);

        parent::tearDown();
    }

    public function test_it_deletes_files_older_than_the_configured_retention(): void
    {
        $oldFile = $this->tempPath . DIRECTORY_SEPARATOR . 'old.log';
        $newFile = $this->tempPath . DIRECTORY_SEPARATOR . 'new.log';

        file_put_contents($oldFile, 'old');
        file_put_contents($newFile, 'new');
        touch($oldFile, now()->subDays(20)->getTimestamp());

        $summary = app(CleanerService::class)->run(['file']);

        $this->assertSame(1, $summary['file']['deleted']);
        $this->assertFileDoesNotExist($oldFile);
        $this->assertFileExists($newFile);
    }

    public function test_dry_run_reports_deletes_without_removing_files(): void
    {
        $oldFile = $this->tempPath . DIRECTORY_SEPARATOR . 'old.log';

        file_put_contents($oldFile, 'old');
        touch($oldFile, now()->subDays(20)->getTimestamp());

        $summary = app(CleanerService::class)->run(['file'], dryRun: true);

        $this->assertSame(1, $summary['file']['deleted']);
        $this->assertFileExists($oldFile);
    }

    public function test_it_preserves_excluded_files(): void
    {
        $gitignore = $this->tempPath . DIRECTORY_SEPARATOR . '.gitignore';

        file_put_contents($gitignore, '*');
        touch($gitignore, now()->subDays(20)->getTimestamp());

        $summary = app(CleanerService::class)->run(['file']);

        $this->assertSame(0, $summary['file']['deleted']);
        $this->assertSame(1, $summary['file']['skipped']);
        $this->assertFileExists($gitignore);
    }
}
