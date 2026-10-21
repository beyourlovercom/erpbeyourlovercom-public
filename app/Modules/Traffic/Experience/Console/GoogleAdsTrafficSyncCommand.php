<?php

namespace App\Modules\Traffic\Experience\Console;

use App\Modules\Traffic\Application\GoogleAdsTrafficSyncService;
use Illuminate\Console\Command;
use Throwable;

final class GoogleAdsTrafficSyncCommand extends Command
{
    protected $signature = 'traffic:google-ads:sync
        {--project= : Business project key}
        {--account= : Google Ads customer account ID}
        {--from= : Inclusive start date YYYY-MM-DD}
        {--to= : Inclusive end date YYYY-MM-DD}
        {--dry-run : Explicitly run without writes}
        {--apply : Apply idempotent Traffic writes}';

    protected $description = 'Read Google Ads account/campaign daily metrics into Traffic OS';

    public function handle(GoogleAdsTrafficSyncService $service): int
    {
        $project = trim((string) $this->option('project'));
        $account = trim((string) $this->option('account'));
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to'));
        $mode = $this->option('apply') ? 'apply' : 'dry_run';

        if ($this->option('apply') && $this->option('dry-run')) {
            return $this->emit($this->blocked($mode, $project, $account, $from, $to, 'option_conflict'));
        }
        if ($project === '' || $account === '' || $from === '' || $to === '') {
            return $this->emit($this->blocked($mode, $project, $account, $from, $to, 'missing_required_option'));
        }

        try {
            return $this->emit($service->sync($project, $account, $from, $to, $mode));
        } catch (Throwable) {
            return $this->emit($this->blocked($mode, $project, $account, $from, $to, 'unexpected_error'));
        }
    }

    /** @param array<string, mixed> $summary */
    private function emit(array $summary): int
    {
        $this->line(json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return ($summary['blocked'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function blocked(string $mode, string $project, string $account, string $from, string $to, string $reason): array
    {
        return [
            'mode' => $mode,
            'project_key' => $project,
            'external_account_id' => $account,
            'date_from' => $from,
            'date_to' => $to,
            'campaigns_seen' => 0,
            'daily_rows_seen' => 0,
            'accounts_applied' => 0,
            'campaigns_applied' => 0,
            'daily_rows_applied' => 0,
            'blocked' => [$reason],
        ];
    }
}
