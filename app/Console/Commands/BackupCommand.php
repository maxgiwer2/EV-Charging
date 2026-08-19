<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Ifsnop\Mysqldump\Mysqldump;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Database and receipt backup (docs/03 -> backup/restore procedure).
 *
 * Two things are backed up because losing either loses the system: the database
 * holds the financial records, and the private disk holds the receipts that
 * evidence them. A database-only backup would restore totals with no supporting
 * documents, which for auditable records is only half a recovery.
 *
 * The dump is produced in PHP over PDO rather than by shelling out to
 * mysqldump. That is not a preference -- the first implementation did shell out
 * and failed in two ways worth recording:
 *
 *  1. Alpine ships MariaDB's client, which cannot authenticate against MySQL 8
 *     (no caching_sha2_password plugin), so every dump was empty.
 *  2. The command was `mysqldump | gzip > file`, and a shell pipeline reports
 *     the exit code of its *last* command. gzip succeeded, so the command
 *     reported success while writing a 20-byte file.
 *
 * Going through PDO uses the same connection the application already
 * authenticates with, and surfaces failures as exceptions rather than silence.
 */
class BackupCommand extends Command
{
    protected $signature = 'backup:run
        {--path= : Directory to write into (default storage/app/private/backups)}
        {--database-only : Skip the receipt files}
        {--keep=7 : How many previous backups to retain}';

    protected $description = 'Back up the database and receipt files';

    public function handle(): int
    {
        $directory = (string) ($this->option('path') ?: storage_path('app/private/backups'));
        File::ensureDirectoryExists($directory, 0750);

        $stamp = now()->format('Ymd-His');
        $sqlPath = "{$directory}/db-{$stamp}.sql.gz";

        $this->info("Backing up database to {$sqlPath}");

        if (! $this->dumpDatabase($sqlPath)) {
            return self::FAILURE;
        }

        if (! $this->option('database-only')) {
            $archivePath = "{$directory}/receipts-{$stamp}.tar.gz";
            $this->info("Backing up receipts to {$archivePath}");

            if (! $this->archiveReceipts($archivePath)) {
                return self::FAILURE;
            }
        }

        $this->prune($directory, (int) $this->option('keep'));

        // Verified here rather than left to the operator: a backup command that
        // reports success on an unusable file is worse than one that fails.
        $this->info('Verifying...');

        if ($this->call('backup:verify', ['file' => basename($sqlPath), '--path' => $directory]) !== self::SUCCESS) {
            $this->error('Backup completed but failed verification.');

            return self::FAILURE;
        }

        $this->info('Backup complete and verified.');

        return self::SUCCESS;
    }

    private function dumpDatabase(string $path): bool
    {
        $connection = (string) config('database.default');
        $config = config("database.connections.{$connection}");

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            $config['host'],
            $config['port'],
            $config['database'],
        );

        try {
            $dump = new Mysqldump($dsn, $config['username'], $config['password'], [
                // gzip so the stored file matches what backup:verify checks.
                'compress' => Mysqldump::GZIP,
                // Consistent snapshot without locking, so a backup does not
                // block charging entries being recorded.
                'single-transaction' => true,
                'add-drop-table' => true,
                // Routines travel with the schema.
                'routines' => true,
                'default-character-set' => Mysqldump::UTF8MB4,
            ]);

            $dump->start($path);
        } catch (Throwable $e) {
            // Shown to the operator rather than logged: the message can name
            // the host and user (docs/10 rule 13).
            $this->error('Database dump failed: '.$e->getMessage());

            return false;
        }

        return true;
    }

    private function archiveReceipts(string $path): bool
    {
        $root = storage_path('app/private/receipts');

        if (! is_dir($root)) {
            $this->warn('No receipt directory to archive.');

            return true;
        }

        // No pipeline here, so the exit code is tar's own.
        $process = Process::fromShellCommandline(
            'tar -czf "$OUT" -C "$ROOT" .',
            null,
            ['OUT' => $path, 'ROOT' => $root],
        );

        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('tar failed: '.trim($process->getErrorOutput()));

            return false;
        }

        return true;
    }

    /**
     * Keep the most recent $keep of each kind.
     *
     * Retention is capped rather than unbounded because these files contain
     * complete financial records: every extra copy is another place they can
     * leak from.
     */
    private function prune(string $directory, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        foreach (['db-', 'receipts-'] as $prefix) {
            $files = collect(File::files($directory))
                ->filter(fn ($file): bool => str_starts_with($file->getFilename(), $prefix))
                ->sortByDesc(fn ($file): int => $file->getMTime())
                ->values();

            foreach ($files->slice($keep) as $stale) {
                File::delete($stale->getPathname());
                $this->line("Pruned {$stale->getFilename()}");
            }
        }
    }
}
