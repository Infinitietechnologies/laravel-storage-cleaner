<?php

namespace InfinitieTechnologies\StorageCleaner\Services;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SplFileInfo;
use Throwable;

class CleanerService
{
    public function __construct(private readonly Filesystem $files)
    {
    }

    /**
     * @param array<int, string> $drivers
     * @return array<string, array{
     *     scanned: int,
     *     matched: int,
     *     deleted: int,
     *     skipped: int,
     *     bytes_deleted: int,
     *     sample_paths: array<int, string>,
     *     errors: array<int, string>
     * }>
     */
    public function run(array $drivers = [], bool $dryRun = false, ?callable $progress = null): array
    {
        if (! config('storage-cleaner.enabled')) {
            return [];
        }

        $drivers = $drivers ?: ['file', 'disk', 'database'];
        $summary = [];

        if (in_array('file', $drivers, true) && config('storage-cleaner.drivers.file.enabled')) {
            $summary['file'] = $this->cleanLocalFiles($dryRun, $progress);
        }

        if (in_array('disk', $drivers, true) && config('storage-cleaner.drivers.disk.enabled')) {
            $summary['disk'] = $this->cleanStorageDisks($dryRun, $progress);
        }

        if (in_array('database', $drivers, true) && config('storage-cleaner.drivers.database.enabled')) {
            $summary['database'] = $this->cleanDatabase($dryRun);
        }

        return $summary;
    }

    /**
     * @return array{
     *     scanned: int,
     *     matched: int,
     *     deleted: int,
     *     skipped: int,
     *     bytes_deleted: int,
     *     sample_paths: array<int, string>,
     *     errors: array<int, string>
     * }
     */
    private function cleanLocalFiles(bool $dryRun, ?callable $progress): array
    {
        $result = $this->emptyResult();

        foreach ($this->localFileTargets() as $target) {
            if (! $this->files->isDirectory($target['path'])) {
                $result['skipped']++;
                continue;
            }

            $remainingFiles = $this->cleanLocalTargetOlderThan($target, $dryRun, $result, $progress);
            $this->enforceLocalTargetSizeCap($remainingFiles, $target, $dryRun, $result);
        }

        return $result;
    }

    /**
     * @param array{
     *     path: string,
     *     retention_days: int,
     *     max_size_mb: mixed,
     *     recursive: bool,
     *     include_hidden: bool,
     *     exclude: array<int, string>
     * } $target
     * @param array{
     *     scanned: int,
     *     matched: int,
     *     deleted: int,
     *     skipped: int,
     *     bytes_deleted: int,
     *     sample_paths: array<int, string>,
     *     errors: array<int, string>
     * } $result
     * @return array<int, SplFileInfo>
     */
    private function cleanLocalTargetOlderThan(array $target, bool $dryRun, array &$result, ?callable $progress): array
    {
        $remainingFiles = [];
        $threshold = CarbonImmutable::now()->subDays($target['retention_days']);
        $files = $target['recursive']
            ? $this->files->allFiles($target['path'], $target['include_hidden'])
            : $this->files->files($target['path'], $target['include_hidden']);

        foreach ($files as $file) {
            $result['scanned']++;
            $progress && $progress();

            if ($this->shouldSkipPath($file->getFilename(), $this->relativeLocalPath($target['path'], $file), $target['exclude'])) {
                $result['skipped']++;
                continue;
            }

            if (CarbonImmutable::createFromTimestamp($file->getMTime())->lessThan($threshold)) {
                $this->deleteLocalFile($file, $dryRun, $result);
                continue;
            }

            $remainingFiles[] = $file;
        }

        return $remainingFiles;
    }

    /**
     * @param array<int, SplFileInfo> $files
     * @param array{
     *     max_size_mb: mixed,
     *     exclude: array<int, string>,
     *     path: string
     * } $target
     * @param array{
     *     scanned: int,
     *     matched: int,
     *     deleted: int,
     *     skipped: int,
     *     bytes_deleted: int,
     *     sample_paths: array<int, string>,
     *     errors: array<int, string>
     * } $result
     */
    private function enforceLocalTargetSizeCap(array $files, array $target, bool $dryRun, array &$result): void
    {
        $maxBytes = $this->maxBytes($target['max_size_mb']);

        if ($maxBytes === null) {
            return;
        }

        usort($files, fn (SplFileInfo $a, SplFileInfo $b): int => $a->getMTime() <=> $b->getMTime());

        $totalBytes = array_sum(array_map(fn (SplFileInfo $file): int => $file->getSize(), $files));

        foreach ($files as $file) {
            if ($totalBytes <= $maxBytes) {
                break;
            }

            $totalBytes -= $file->getSize();
            $this->deleteLocalFile($file, $dryRun, $result);
        }
    }

    /**
     * @param array{
     *     scanned: int,
     *     matched: int,
     *     deleted: int,
     *     skipped: int,
     *     bytes_deleted: int,
     *     sample_paths: array<int, string>,
     *     errors: array<int, string>
     * } $result
     */
    private function deleteLocalFile(SplFileInfo $file, bool $dryRun, array &$result): void
    {
        $path = (string) $file->getRealPath();
        $size = $file->getSize();

        $this->recordDeleteCandidate($path, $result);

        if ($dryRun) {
            $this->recordDeletedBytes($size, $result);

            return;
        }

        try {
            if ($this->files->delete($path)) {
                $this->recordDeletedBytes($size, $result);
                $this->logDeletedPath('file', $path, $size);
            } else {
                $result['errors'][] = 'Unable to delete file: ' . $path;
            }
        } catch (Throwable $exception) {
            $result['errors'][] = $exception->getMessage();
        }
    }

    /**
     * @return array{
     *     scanned: int,
     *     matched: int,
     *     deleted: int,
     *     skipped: int,
     *     bytes_deleted: int,
     *     sample_paths: array<int, string>,
     *     errors: array<int, string>
     * }
     */
    private function cleanStorageDisks(bool $dryRun, ?callable $progress): array
    {
        $result = $this->emptyResult();

        foreach ($this->diskTargets() as $target) {
            try {
                $disk = Storage::disk($target['disk']);
                $paths = $target['recursive'] ? $disk->allFiles($target['path']) : $disk->files($target['path']);
            } catch (Throwable $exception) {
                $result['errors'][] = $exception->getMessage();
                continue;
            }

            $remainingFiles = $this->cleanDiskTargetOlderThan($target, $paths, $dryRun, $result, $progress);
            $this->enforceDiskTargetSizeCap($target, $remainingFiles, $dryRun, $result);
        }

        return $result;
    }

    /**
     * @param array{
     *     disk: string,
     *     path: string,
     *     retention_days: int,
     *     exclude: array<int, string>
     * } $target
     * @param array<int, string> $paths
     * @param array{
     *     scanned: int,
     *     matched: int,
     *     deleted: int,
     *     skipped: int,
     *     bytes_deleted: int,
     *     sample_paths: array<int, string>,
     *     errors: array<int, string>
     * } $result
     * @return array<int, array{path: string, modified: int, size: int}>
     */
    private function cleanDiskTargetOlderThan(array $target, array $paths, bool $dryRun, array &$result, ?callable $progress): array
    {
        $remainingFiles = [];
        $threshold = CarbonImmutable::now()->subDays($target['retention_days'])->getTimestamp();
        $disk = Storage::disk($target['disk']);

        foreach ($paths as $path) {
            $result['scanned']++;
            $progress && $progress();

            if ($this->shouldSkipPath(basename($path), $path, $target['exclude'])) {
                $result['skipped']++;
                continue;
            }

            try {
                $modified = $disk->lastModified($path);
                $size = $disk->size($path);
            } catch (Throwable $exception) {
                $result['errors'][] = $exception->getMessage();
                continue;
            }

            if ($modified < $threshold) {
                $this->deleteDiskFile($target['disk'], $path, $size, $dryRun, $result);
                continue;
            }

            $remainingFiles[] = [
                'path' => $path,
                'modified' => $modified,
                'size' => $size,
            ];
        }

        return $remainingFiles;
    }

    /**
     * @param array{
     *     disk: string,
     *     max_size_mb: mixed
     * } $target
     * @param array<int, array{path: string, modified: int, size: int}> $files
     * @param array{
     *     scanned: int,
     *     matched: int,
     *     deleted: int,
     *     skipped: int,
     *     bytes_deleted: int,
     *     sample_paths: array<int, string>,
     *     errors: array<int, string>
     * } $result
     */
    private function enforceDiskTargetSizeCap(array $target, array $files, bool $dryRun, array &$result): void
    {
        $maxBytes = $this->maxBytes($target['max_size_mb']);

        if ($maxBytes === null) {
            return;
        }

        usort($files, fn (array $a, array $b): int => $a['modified'] <=> $b['modified']);

        $totalBytes = array_sum(array_column($files, 'size'));

        foreach ($files as $file) {
            if ($totalBytes <= $maxBytes) {
                break;
            }

            $totalBytes -= $file['size'];
            $this->deleteDiskFile($target['disk'], $file['path'], $file['size'], $dryRun, $result);
        }
    }

    /**
     * @param array{
     *     scanned: int,
     *     matched: int,
     *     deleted: int,
     *     skipped: int,
     *     bytes_deleted: int,
     *     sample_paths: array<int, string>,
     *     errors: array<int, string>
     * } $result
     */
    private function deleteDiskFile(string $diskName, string $path, int $size, bool $dryRun, array &$result): void
    {
        $displayPath = $diskName . ':' . $path;

        $this->recordDeleteCandidate($displayPath, $result);

        if ($dryRun) {
            $this->recordDeletedBytes($size, $result);

            return;
        }

        try {
            if (Storage::disk($diskName)->delete($path)) {
                $this->recordDeletedBytes($size, $result);
                $this->logDeletedPath('disk', $displayPath, $size);
            } else {
                $result['errors'][] = 'Unable to delete disk file: ' . $displayPath;
            }
        } catch (Throwable $exception) {
            $result['errors'][] = $exception->getMessage();
        }
    }

    private function shouldSkipPath(string $filename, string $relativePath, array $patterns): bool
    {
        $relativePath = str_replace('\\', '/', $relativePath);

        foreach ($patterns as $pattern) {
            $pattern = str_replace('\\', '/', $pattern);

            if (Str::is($pattern, $filename) || Str::is($pattern, $relativePath)) {
                return true;
            }
        }

        return false;
    }

    private function relativeLocalPath(string $basePath, SplFileInfo $file): string
    {
        $basePath = rtrim(str_replace('\\', '/', realpath($basePath) ?: $basePath), '/');
        $filePath = str_replace('\\', '/', (string) $file->getRealPath());

        return ltrim(Str::after($filePath, $basePath), '/');
    }

    /**
     * @return array<int, array{
     *     name: string,
     *     path: string,
     *     retention_days: int,
     *     max_size_mb: mixed,
     *     recursive: bool,
     *     include_hidden: bool,
     *     exclude: array<int, string>
     * }>
     */
    private function localFileTargets(): array
    {
        $targets = [];
        $globalExclude = (array) config('storage-cleaner.drivers.file.exclude', []);

        foreach ((array) config('storage-cleaner.drivers.file.paths', []) as $key => $target) {
            if (is_string($target)) {
                $targets[] = [
                    'name' => is_string($key) ? $key : $target,
                    'path' => $target,
                    'retention_days' => (int) config('storage-cleaner.drivers.file.delete_older_than_days', 15),
                    'max_size_mb' => config('storage-cleaner.drivers.file.max_size_mb'),
                    'recursive' => true,
                    'include_hidden' => true,
                    'exclude' => $globalExclude,
                ];

                continue;
            }

            if (! is_array($target) || empty($target['path']) || ! is_string($target['path'])) {
                continue;
            }

            $targets[] = [
                'name' => (string) ($target['name'] ?? (is_string($key) ? $key : $target['path'])),
                'path' => $target['path'],
                'retention_days' => (int) ($target['retention_days'] ?? $target['delete_older_than_days'] ?? config('storage-cleaner.drivers.file.delete_older_than_days', 15)),
                'max_size_mb' => $target['max_size_mb'] ?? config('storage-cleaner.drivers.file.max_size_mb'),
                'recursive' => (bool) ($target['recursive'] ?? true),
                'include_hidden' => (bool) ($target['include_hidden'] ?? true),
                'exclude' => array_values(array_unique(array_merge($globalExclude, (array) ($target['exclude'] ?? [])))),
            ];
        }

        return $targets;
    }

    /**
     * @return array<int, array{
     *     disk: string,
     *     name: string,
     *     path: string,
     *     retention_days: int,
     *     max_size_mb: mixed,
     *     recursive: bool,
     *     exclude: array<int, string>
     * }>
     */
    private function diskTargets(): array
    {
        $targets = [];
        $globalExclude = (array) config('storage-cleaner.drivers.disk.exclude', []);

        foreach ((array) config('storage-cleaner.drivers.disk.disks', []) as $diskName => $diskTargets) {
            foreach ((array) $diskTargets as $key => $target) {
                if (is_string($target)) {
                    $targets[] = [
                        'disk' => (string) $diskName,
                        'name' => is_string($key) ? $key : $target,
                        'path' => trim($target, '/'),
                        'retention_days' => (int) config('storage-cleaner.drivers.disk.delete_older_than_days', 15),
                        'max_size_mb' => config('storage-cleaner.drivers.disk.max_size_mb'),
                        'recursive' => true,
                        'exclude' => $globalExclude,
                    ];

                    continue;
                }

                if (! is_array($target) || ! array_key_exists('path', $target)) {
                    continue;
                }

                $targets[] = [
                    'disk' => (string) ($target['disk'] ?? $diskName),
                    'name' => (string) ($target['name'] ?? (is_string($key) ? $key : $target['path'])),
                    'path' => trim((string) $target['path'], '/'),
                    'retention_days' => (int) ($target['retention_days'] ?? $target['delete_older_than_days'] ?? config('storage-cleaner.drivers.disk.delete_older_than_days', 15)),
                    'max_size_mb' => $target['max_size_mb'] ?? config('storage-cleaner.drivers.disk.max_size_mb'),
                    'recursive' => (bool) ($target['recursive'] ?? true),
                    'exclude' => array_values(array_unique(array_merge($globalExclude, (array) ($target['exclude'] ?? [])))),
                ];
            }
        }

        return $targets;
    }

    private function maxBytes(mixed $maxSizeMb): ?int
    {
        if ($maxSizeMb === null || $maxSizeMb === '') {
            return null;
        }

        $maxBytes = (int) $maxSizeMb * 1024 * 1024;

        return $maxBytes > 0 ? $maxBytes : null;
    }

    /**
     * @param array{
     *     scanned: int,
     *     matched: int,
     *     deleted: int,
     *     skipped: int,
     *     bytes_deleted: int,
     *     sample_paths: array<int, string>,
     *     errors: array<int, string>
     * } $result
     */
    private function recordDeleteCandidate(string $path, array &$result): void
    {
        $result['matched']++;

        if (count($result['sample_paths']) < (int) config('storage-cleaner.safety.sample_limit', 10)) {
            $result['sample_paths'][] = $path;
        }
    }

    /**
     * @param array{
     *     scanned: int,
     *     matched: int,
     *     deleted: int,
     *     skipped: int,
     *     bytes_deleted: int,
     *     sample_paths: array<int, string>,
     *     errors: array<int, string>
     * } $result
     */
    private function recordDeletedBytes(int $size, array &$result): void
    {
        $result['deleted']++;
        $result['bytes_deleted'] += $size;
    }

    private function logDeletedPath(string $driver, string $path, int $size): void
    {
        if (! config('storage-cleaner.safety.log_deleted_files', false)) {
            return;
        }

        Log::channel(config('storage-cleaner.safety.log_channel'))
            ->info('Storage cleaner deleted file.', [
                'driver' => $driver,
                'path' => $path,
                'bytes' => $size,
            ]);
    }

    /**
     * @return array{
     *     scanned: int,
     *     matched: int,
     *     deleted: int,
     *     skipped: int,
     *     bytes_deleted: int,
     *     sample_paths: array<int, string>,
     *     errors: array<int, string>
     * }
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
                $result['matched'] += $query->count();
                $result['deleted'] = $result['matched'];
                continue;
            }

            $deleted = $query->delete();
            $result['matched'] += $deleted;
            $result['deleted'] += $deleted;
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
     * @return array{
     *     scanned: int,
     *     matched: int,
     *     deleted: int,
     *     skipped: int,
     *     bytes_deleted: int,
     *     sample_paths: array<int, string>,
     *     errors: array<int, string>
     * }
     */
    private function emptyResult(): array
    {
        return [
            'scanned' => 0,
            'matched' => 0,
            'deleted' => 0,
            'skipped' => 0,
            'bytes_deleted' => 0,
            'sample_paths' => [],
            'errors' => [],
        ];
    }
}
