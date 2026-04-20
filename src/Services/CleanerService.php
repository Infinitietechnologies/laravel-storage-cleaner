<?php

namespace InfinitieTechnologies\StorageCleaner\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SplFileInfo;

class CleanerService
{
    public function __construct(private readonly Filesystem $files)
    {
    }

    /**
     * @param array<int, string> $drivers
     * @return array<string, array{deleted: int, skipped: int, bytes_deleted: int, errors: array<int, string>}>
     */
    public function run(array $drivers = [], bool $dryRun = false): array
    {
        if (! config('storage-cleaner.enabled')) {
            return [];
        }

        $drivers = $drivers ?: ['file', 'database'];
        $summary = [];

        if (in_array('file', $drivers, true) && config('storage-cleaner.drivers.file.enabled')) {
            $summary['file'] = $this->cleanFiles($dryRun);
        }

        if (in_array('database', $drivers, true) && config('storage-cleaner.drivers.database.enabled')) {
            $summary['database'] = $this->cleanDatabase($dryRun);
        }

        return $summary;
    }

    /**
     * @return array{deleted: int, skipped: int, bytes_deleted: int, errors: array<int, string>}
     */
    private function cleanFiles(bool $dryRun): array
    {
        $result = $this->emptyResult();
        $paths = config('storage-cleaner.drivers.file.paths', []);
        $days = (int) config('storage-cleaner.drivers.file.delete_older_than_days', 15);
        $threshold = CarbonImmutable::now()->subDays($days);

        foreach ($paths as $path) {
            if (! is_string($path) || ! $this->files->isDirectory($path)) {
                $result['skipped']++;
                continue;
            }

            $files = $this->cleanFilesOlderThan($path, $threshold, $dryRun, $result);
            $this->enforcePathSizeCap($files, $dryRun, $result);
        }

        return $result;
    }

    /**
     * @param array{deleted: int, skipped: int, bytes_deleted: int, errors: array<int, string>} $result
     * @return array<int, SplFileInfo>
     */
    private function cleanFilesOlderThan(string $path, CarbonImmutable $threshold, bool $dryRun, array &$result): array
    {
        $remainingFiles = [];

        foreach ($this->files->allFiles($path, true) as $file) {
            if ($this->shouldSkipFile($file)) {
                $result['skipped']++;
                continue;
            }

            if (CarbonImmutable::createFromTimestamp($file->getMTime())->lessThan($threshold)) {
                $this->deleteFile($file, $dryRun, $result);
                continue;
            }

            $remainingFiles[] = $file;
        }

        return $remainingFiles;
    }

    /**
     * @param array<int, SplFileInfo> $files
     * @param array{deleted: int, skipped: int, bytes_deleted: int, errors: array<int, string>} $result
     */
    private function enforcePathSizeCap(array $files, bool $dryRun, array &$result): void
    {
        $maxSizeMb = config('storage-cleaner.drivers.file.max_size_mb');

        if ($maxSizeMb === null || $maxSizeMb === '') {
            return;
        }

        $maxBytes = (int) $maxSizeMb * 1024 * 1024;

        if ($maxBytes <= 0) {
            return;
        }

        usort($files, fn (SplFileInfo $a, SplFileInfo $b): int => $a->getMTime() <=> $b->getMTime());

        $totalBytes = array_sum(array_map(fn (SplFileInfo $file): int => $file->getSize(), $files));

        foreach ($files as $file) {
            if ($totalBytes <= $maxBytes) {
                break;
            }

            $totalBytes -= $file->getSize();
            $this->deleteFile($file, $dryRun, $result);
        }
    }

    private function shouldSkipFile(SplFileInfo $file): bool
    {
        return in_array($file->getFilename(), config('storage-cleaner.drivers.file.exclude', []), true);
    }

    /**
     * @param array{deleted: int, skipped: int, bytes_deleted: int, errors: array<int, string>} $result
     */
    private function deleteFile(SplFileInfo $file, bool $dryRun, array &$result): void
    {
        $result['deleted']++;
        $result['bytes_deleted'] += $file->getSize();

        if ($dryRun) {
            return;
        }

        try {
            $this->files->delete($file->getRealPath());
        } catch (FileNotFoundException $exception) {
            $result['errors'][] = $exception->getMessage();
        }
    }

    /**
     * @return array{deleted: int, skipped: int, bytes_deleted: int, errors: array<int, string>}
     */
    private function cleanDatabase(bool $dryRun): array
    {
        $result = $this->emptyResult();

        foreach (config('storage-cleaner.drivers.database.tables', []) as $table => $policy) {
            $policy = $this->normalizeDatabasePolicy($policy);

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $policy['date_column'])) {
                $result['skipped']++;
                continue;
            }

            $query = DB::table($table)->where(
                $policy['date_column'],
                '<',
                $this->databaseThreshold($policy['retention_days'], $policy['date_type'])
            );

            if ($dryRun) {
                $result['deleted'] += $query->count();
                continue;
            }

            $result['deleted'] += $query->delete();
        }

        return $result;
    }

    /**
     * @param mixed $policy
     * @return array{retention_days: int, date_column: string, date_type: string}
     */
    private function normalizeDatabasePolicy(mixed $policy): array
    {
        if (is_numeric($policy)) {
            return [
                'retention_days' => (int) $policy,
                'date_column' => 'created_at',
                'date_type' => 'datetime',
            ];
        }

        return [
            'retention_days' => (int) ($policy['retention_days'] ?? 30),
            'date_column' => (string) ($policy['date_column'] ?? 'created_at'),
            'date_type' => (string) ($policy['date_type'] ?? 'datetime'),
        ];
    }

    private function databaseThreshold(int $days, string $dateType): mixed
    {
        $threshold = CarbonImmutable::now()->subDays($days);

        return $dateType === 'unix' ? $threshold->getTimestamp() : $threshold;
    }

    /**
     * @return array{deleted: int, skipped: int, bytes_deleted: int, errors: array<int, string>}
     */
    private function emptyResult(): array
    {
        return [
            'deleted' => 0,
            'skipped' => 0,
            'bytes_deleted' => 0,
            'errors' => [],
        ];
    }
}
