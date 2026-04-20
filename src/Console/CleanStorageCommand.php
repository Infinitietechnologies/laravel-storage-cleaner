<?php

namespace InfinitieTechnologies\StorageCleaner\Console;

use Illuminate\Console\Command;
use InfinitieTechnologies\StorageCleaner\Services\CleanerService;

class CleanStorageCommand extends Command
{
    protected $signature = 'storage:clean
        {--driver=* : Limit cleanup to one or more drivers, such as file or database}
        {--dry-run : Report what would be deleted without deleting anything}
        {--json : Output the cleanup summary as JSON}';

    protected $description = 'Clean old storage files and configured database records.';

    public function handle(CleanerService $cleaner): int
    {
        $summary = $cleaner->run(
            drivers: $this->option('driver'),
            dryRun: (bool) $this->option('dry-run'),
        );

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        foreach ($summary as $driver => $result) {
            $this->line(sprintf(
                '%s: %d deleted, %d skipped%s',
                ucfirst($driver),
                $result['deleted'],
                $result['skipped'],
                $this->option('dry-run') ? ' (dry run)' : ''
            ));
        }

        $this->info('Storage cleanup completed.');

        return self::SUCCESS;
    }
}
