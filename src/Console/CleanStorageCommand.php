<?php

namespace InfinitieTechnologies\StorageCleaner\Console;

use Illuminate\Console\Command;
use InfinitieTechnologies\StorageCleaner\Services\CleanerService;

class CleanStorageCommand extends Command
{
    protected $signature = 'storage:clean
        {--driver=* : Limit cleanup to one or more drivers, such as file, disk, or database}
        {--dry-run : Report what would be deleted without deleting anything}
        {--pretend : Alias of --dry-run}
        {--force : Skip the interactive confirmation prompt}
        {--no-progress : Disable progress feedback}
        {--json : Output the cleanup summary as JSON}';

    protected $description = 'Clean old storage files and configured database records.';

    protected $aliases = [
        'storage:clean-old',
    ];

    public function handle(CleanerService $cleaner): int
    {
        $dryRun = (bool) ($this->option('dry-run') || $this->option('pretend'));

        if (! $dryRun && $this->shouldConfirm() && ! $this->confirm('This will permanently delete files and records matching your storage-cleaner policies. Continue?')) {
            $this->warn('Storage cleanup cancelled.');

            return self::SUCCESS;
        }

        $progress = $this->shouldShowProgress()
            ? function (): void {
                $this->output->progressAdvance();
            }
            : null;

        if ($progress) {
            $this->output->progressStart();
        }

        $summary = $cleaner->run(
            drivers: $this->option('driver'),
            dryRun: $dryRun,
            progress: $progress,
        );

        if ($progress) {
            $this->output->progressFinish();
        }

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($summary as $driver => $result) {
            $this->line(sprintf(
                '%s: %d scanned, %d matched, %d %s, %d skipped%s',
                ucfirst($driver),
                $result['scanned'],
                $result['matched'],
                $result['deleted'],
                $dryRun ? 'would be deleted' : 'deleted',
                $result['skipped'],
                $dryRun ? ' (dry run)' : ''
            ));

            if ($result['sample_paths'] !== []) {
                $this->line('Sample paths:');

                foreach ($result['sample_paths'] as $path) {
                    $this->line(' - ' . $path);
                }
            }

            foreach ($result['errors'] as $error) {
                $this->error($error);
            }
        }

        $this->info('Storage cleanup completed.');

        return self::SUCCESS;
    }

    private function shouldConfirm(): bool
    {
        return ! $this->option('force')
            && $this->input->isInteractive()
            && (bool) config('storage-cleaner.safety.confirm_before_delete', true);
    }

    private function shouldShowProgress(): bool
    {
        return ! $this->option('json')
            && ! $this->option('no-progress')
            && $this->input->isInteractive();
    }
}
