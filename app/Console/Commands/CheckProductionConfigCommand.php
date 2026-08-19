<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Pre-deployment configuration check (docs/09 M6).
 *
 * ProductionServiceProvider refuses to boot on an unsafe configuration, which
 * is the right behaviour but a late discovery. This command answers the same
 * questions before a release goes out, and can run against any environment.
 */
class CheckProductionConfigCommand extends Command
{
    protected $signature = 'app:check-production';

    protected $description = 'Report configuration that would be unsafe in production';

    public function handle(): int
    {
        $failures = 0;

        foreach ($this->checks() as $label => $check) {
            [$ok, $detail] = $check;

            if ($ok) {
                $this->line(sprintf('  <fg=green>ok</>    %s', $label));

                continue;
            }

            $failures++;
            $this->line(sprintf('  <fg=red>FAIL</>  %s — %s', $label, $detail));
        }

        $this->newLine();

        if ($failures > 0) {
            $this->error("{$failures} check(s) failed. See docs/14-deployment.md.");

            return self::FAILURE;
        }

        $this->info('Configuration is production-safe.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{0: bool, 1: string}>
     */
    private function checks(): array
    {
        $disk = (string) config('receipts.disk');

        return [
            'APP_DEBUG is off' => [
                config('app.debug') === false,
                'stack traces and env values would be disclosed',
            ],
            'APP_KEY is set' => [
                ! in_array((string) config('app.key'), ['', 'base64:'], true),
                'sessions and encrypted values cannot be secured',
            ],
            'APP_URL uses https' => [
                str_starts_with((string) config('app.url'), 'https://'),
                'generated links would downgrade to http',
            ],
            'session cookie is secure' => [
                config('session.secure') === true,
                'the session cookie would travel over plain HTTP',
            ],
            'session is encrypted' => [
                config('session.encrypt') === true,
                'session contents would be readable in the store',
            ],
            'session cookie is http-only' => [
                config('session.http_only') === true,
                'JavaScript could read the session cookie',
            ],
            'receipts disk is private' => [
                $disk !== 'public' && config("filesystems.disks.{$disk}.url") === null,
                'private receipts would be reachable by URL (AT-007)',
            ],
            'receipts disk is not served directly' => [
                config("filesystems.disks.{$disk}.serve") !== true,
                'receipts would bypass the ownership check',
            ],
            'queue is not synchronous' => [
                config('queue.default') !== 'sync',
                'OCR would run inside the request and time out',
            ],
            'database is MySQL' => [
                config('database.default') === 'mysql',
                'DECIMAL and ENUM semantics are relied upon',
            ],
        ];
    }
}
