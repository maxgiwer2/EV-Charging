<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Verifies a backup is readable and contains what it should
 * (docs/03 -> backup/restore).
 *
 * A separate command on purpose. An untested backup is a hope rather than a
 * recovery plan, and the failure mode people actually hit is discovering at
 * restore time that the dump was truncated or the gzip stream is corrupt.
 *
 * This does not restore anything, so it is safe to run against production
 * backups on a schedule.
 */
class BackupVerifyCommand extends Command
{
    protected $signature = 'backup:verify
        {file : Backup filename inside the backup directory}
        {--path= : Directory to look in (default storage/app/private/backups)}';

    protected $description = 'Check that a backup file is intact and complete';

    /**
     * Tables whose absence means the dump is not a usable recovery point.
     * Losing any of these loses the financial record itself.
     *
     * @var list<string>
     */
    private const REQUIRED_TABLES = [
        'users',
        'charging_sessions',
        'charging_cost_lines',
        'receipts',
        'tariff_versions',
        'audit_logs',
    ];

    public function handle(): int
    {
        $directory = (string) ($this->option('path') ?: storage_path('app/private/backups'));
        $path = $directory.'/'.$this->argument('file');

        if (! is_file($path)) {
            $this->error("No such backup: {$path}");

            return self::FAILURE;
        }

        $this->line('Size: '.number_format((int) filesize($path) / 1_048_576, 2).' MB');

        if (! $this->isIntact($path)) {
            return self::FAILURE;
        }

        if (str_ends_with($path, '.sql.gz') && ! $this->containsRequiredTables($path)) {
            return self::FAILURE;
        }

        $this->info('Backup verified.');

        return self::SUCCESS;
    }

    /**
     * gzip -t decompresses the whole stream and checks its CRC, which is what
     * catches a dump truncated by a full disk.
     */
    private function isIntact(string $path): bool
    {
        $process = Process::fromShellCommandline('gzip -t "$FILE"', null, ['FILE' => $path]);
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('Archive is corrupt or truncated.');

            return false;
        }

        $this->line('Archive integrity: ok');

        return true;
    }

    /**
     * Confirm the dump actually holds the tables that matter.
     *
     * A gzip stream can be perfectly valid and still contain a dump that failed
     * partway through, so integrity alone is not enough.
     */
    private function containsRequiredTables(string $path): bool
    {
        $missing = [];

        foreach (self::REQUIRED_TABLES as $table) {
            $process = Process::fromShellCommandline(
                'gzip -dc "$FILE" | grep -qE "CREATE TABLE .?'.$table.'.?"',
                null,
                ['FILE' => $path],
            );
            $process->setTimeout(1800);
            $process->run();

            if (! $process->isSuccessful()) {
                $missing[] = $table;
            }
        }

        if ($missing !== []) {
            $this->error('Dump is missing tables: '.implode(', ', $missing));

            return false;
        }

        $this->line('Required tables: all present');

        return true;
    }
}
