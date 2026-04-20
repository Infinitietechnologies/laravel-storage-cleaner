<?php

namespace InfinitieTechnologies\StorageCleaner\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
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
        config()->set('storage-cleaner.drivers.file.exclude', ['.gitignore', '.gitkeep', '*.gitkeep']);
        config()->set('storage-cleaner.drivers.disk.enabled', false);
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
        $this->assertSame(1, $summary['file']['matched']);
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
        $this->assertSame([$oldFile], $summary['file']['sample_paths']);
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

    public function test_it_applies_exclusion_patterns_to_nested_files(): void
    {
        $nestedPath = $this->tempPath . DIRECTORY_SEPARATOR . 'exports';
        mkdir($nestedPath);

        $importantPdf = $nestedPath . DIRECTORY_SEPARATOR . 'important-file-123.pdf';
        $oldPdf = $nestedPath . DIRECTORY_SEPARATOR . 'old-export.pdf';

        file_put_contents($importantPdf, 'keep');
        file_put_contents($oldPdf, 'delete');
        touch($importantPdf, now()->subDays(20)->getTimestamp());
        touch($oldPdf, now()->subDays(20)->getTimestamp());

        config()->set('storage-cleaner.drivers.file.paths', [
            'exports' => [
                'path' => $nestedPath,
                'retention_days' => 15,
                'exclude' => ['important-file-*.pdf'],
            ],
        ]);

        $summary = app(CleanerService::class)->run(['file']);

        $this->assertSame(1, $summary['file']['deleted']);
        $this->assertSame(1, $summary['file']['skipped']);
        $this->assertFileExists($importantPdf);
        $this->assertFileDoesNotExist($oldPdf);
    }

    public function test_it_respects_non_recursive_local_targets(): void
    {
        $nestedPath = $this->tempPath . DIRECTORY_SEPARATOR . 'nested';
        mkdir($nestedPath);

        $topLevelFile = $this->tempPath . DIRECTORY_SEPARATOR . 'top.log';
        $nestedFile = $nestedPath . DIRECTORY_SEPARATOR . 'nested.log';

        file_put_contents($topLevelFile, 'top');
        file_put_contents($nestedFile, 'nested');
        touch($topLevelFile, now()->subDays(20)->getTimestamp());
        touch($nestedFile, now()->subDays(20)->getTimestamp());

        config()->set('storage-cleaner.drivers.file.paths', [
            'top-level-only' => [
                'path' => $this->tempPath,
                'retention_days' => 15,
                'recursive' => false,
            ],
        ]);

        $summary = app(CleanerService::class)->run(['file']);

        $this->assertSame(1, $summary['file']['deleted']);
        $this->assertFileDoesNotExist($topLevelFile);
        $this->assertFileExists($nestedFile);
    }

    public function test_it_deletes_hidden_files_when_not_excluded(): void
    {
        $hiddenFile = $this->tempPath . DIRECTORY_SEPARATOR . '.old-hidden';

        file_put_contents($hiddenFile, 'hidden');
        touch($hiddenFile, now()->subDays(20)->getTimestamp());

        $summary = app(CleanerService::class)->run(['file']);

        $this->assertSame(1, $summary['file']['deleted']);
        $this->assertFileDoesNotExist($hiddenFile);
    }

    public function test_empty_directories_are_left_in_place_by_default(): void
    {
        $emptyDirectory = $this->tempPath . DIRECTORY_SEPARATOR . 'empty';
        mkdir($emptyDirectory);

        $summary = app(CleanerService::class)->run(['file']);

        $this->assertSame(0, $summary['file']['deleted']);
        $this->assertDirectoryExists($emptyDirectory);
    }

    public function test_it_cleans_configured_storage_disk_targets(): void
    {
        Storage::fake('cleaner');

        Storage::disk('cleaner')->put('temp/old.txt', 'old');
        Storage::disk('cleaner')->put('temp/new.txt', 'new');

        touch(Storage::disk('cleaner')->path('temp/old.txt'), now()->subDays(20)->getTimestamp());

        config()->set('storage-cleaner.drivers.file.enabled', false);
        config()->set('storage-cleaner.drivers.disk.enabled', true);
        config()->set('storage-cleaner.drivers.disk.disks', [
            'cleaner' => [
                'temp' => [
                    'path' => 'temp',
                    'retention_days' => 15,
                ],
            ],
        ]);

        $summary = app(CleanerService::class)->run(['disk']);

        $this->assertSame(1, $summary['disk']['deleted']);
        Storage::disk('cleaner')->assertMissing('temp/old.txt');
        Storage::disk('cleaner')->assertExists('temp/new.txt');
    }
}
