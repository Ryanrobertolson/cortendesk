<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Backups\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Write a backup, apply the retention tiers, then bring the off-site target in
 * line with the local list when one is configured. Scheduled daily; `--scheduled` makes it honour the
 * Settings -> Backups switch and run at most once a day, a bare call always
 * runs.
 */
class Backup extends Command
{
    protected $signature = 'cortendesk:backup
        {--scheduled : Run only when automatic backups are on and one is due}
        {--no-copy : Skip the off-site copy}';

    protected $description = 'Back up the database, settings and server files into one archive';

    public function handle(BackupService $backups): int
    {
        $settings = BackupService::settings();
        $scheduled = (bool) $this->option('scheduled');

        if ($scheduled) {
            if (! $settings['enabled']) {
                $this->info('Automatic backups are off.');

                return self::SUCCESS;
            }

            $last = Setting::get('backup_last_scheduled_at');
            $lastAt = $last ? Carbon::parse($last) : null;
            if (! BackupService::due($lastAt)) {
                $this->info('Not due (last '.$lastAt?->toDateTimeString().').');

                return self::SUCCESS;
            }
        }

        $summary = ['at' => now()->toIso8601String(), 'ok' => true, 'trigger' => $scheduled ? 'scheduled' : 'manual', 'message' => ''];

        try {
            $backup = $backups->create($scheduled ? 'scheduled' : 'manual');
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());
            Log::error('Backup failed', ['error' => $e->getMessage()]);
            $this->record(['at' => $summary['at'], 'ok' => false, 'trigger' => $summary['trigger'], 'message' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->line("Wrote {$backup['name']} (".BackupService::humanSize($backup['size']).', '.array_sum($backup['manifest']['tables']).' rows)');
        $summary['name'] = $backup['name'];
        $summary['message'] = 'Wrote '.$backup['name'];

        if ($scheduled) {
            Setting::put('backup_last_scheduled_at', now()->toIso8601String());
        }

        foreach ($backups->applyRetention($settings['keep']) as $name) {
            $this->line("Removed {$name} (retention)");
        }

        // The off-site copy mirrors the local list, so it is brought in line
        // after retention: the new archive goes up, anything pruned or
        // deleted here comes down.
        $target = $this->option('no-copy') ? null : BackupService::target();
        if ($target !== null) {
            try {
                $sync = $backups->syncRemote($target);
                foreach ($sync['uploaded'] as $name) {
                    $this->line("Copied {$name} to ".$target->label());
                }
                foreach ($sync['deleted'] as $name) {
                    $this->line("Removed {$name} from ".$target->label());
                }
                $summary['message'] .= ', copied to '.$target->label();
            } catch (Throwable $e) {
                $this->warn('Off-site copy failed: '.$e->getMessage());
                Log::warning('Backup off-site copy failed', ['target' => $target->label(), 'error' => $e->getMessage()]);
                $summary['ok'] = false;
                $summary['message'] .= '. Off-site copy failed: '.$e->getMessage();
            }
        }

        $this->record($summary);

        return $summary['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /** What Settings -> Backups shows as the last outcome. */
    private function record(array $summary): void
    {
        Setting::put('backup_last_result', json_encode($summary));
    }
}
