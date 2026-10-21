<?php

namespace App\Modules\Kol\Application\Approval;

use InvalidArgumentException;

final class KolApprovalPolicyEvaluator
{
    private const array Modes = ['ALL', 'THRESHOLD', 'NONE'];

    /**
     * @param  array<string,int>  $thresholds  Platform => normalized follower threshold.
     * @param  list<array{platform:string,is_active:bool,followers:?int}>  $socialAccounts
     */
    public function evaluate(string $mode, array $thresholds, array $socialAccounts, bool $privilegedCreator = false): KolApprovalDecision
    {
        $mode = strtoupper(trim($mode));
        $this->assertValidMode($mode);
        $thresholds = $this->normalizeThresholds($thresholds);

        if ($privilegedCreator) {
            return new KolApprovalDecision('approved', 'privileged_creator', ['policy_mode' => $mode]);
        }

        return match ($mode) {
            'ALL' => new KolApprovalDecision('pending', 'policy_all_requires_manager', ['policy_mode' => $mode]),
            'NONE' => new KolApprovalDecision('approved', 'policy_none_auto_approved', ['policy_mode' => $mode]),
            'THRESHOLD' => $this->evaluateThreshold($thresholds, $socialAccounts),
        };
    }

    /** @param array<string,int> $thresholds */
    private function evaluateThreshold(array $thresholds, array $socialAccounts): KolApprovalDecision
    {
        if ($thresholds === []) {
            return new KolApprovalDecision('pending', 'threshold_not_configured', ['policy_mode' => 'THRESHOLD']);
        }

        $comparable = [];
        $missingReliableData = false;

        foreach ($socialAccounts as $account) {
            if (($account['is_active'] ?? false) !== true) {
                continue;
            }

            $platform = strtolower(trim((string) ($account['platform'] ?? '')));
            if ($platform === '' || ! array_key_exists($platform, $thresholds)) {
                continue;
            }

            $followers = $account['followers'] ?? null;
            if (! is_int($followers) || $followers < 0) {
                $missingReliableData = true;

                continue;
            }

            $threshold = $thresholds[$platform];
            $comparable[] = ['platform' => $platform, 'followers' => $followers, 'threshold' => $threshold];

            if ($followers >= $threshold) {
                return new KolApprovalDecision('approved', 'threshold_reached', [
                    'policy_mode' => 'THRESHOLD',
                    'matched_platform' => $platform,
                    'followers' => $followers,
                    'threshold' => $threshold,
                ]);
            }
        }

        if ($missingReliableData || $comparable === []) {
            return new KolApprovalDecision('pending', 'threshold_data_missing_or_unreliable', [
                'policy_mode' => 'THRESHOLD',
                'comparable' => $comparable,
            ]);
        }

        return new KolApprovalDecision('pending', 'thresholds_below_requirement', [
            'policy_mode' => 'THRESHOLD',
            'comparable' => $comparable,
        ]);
    }

    private function assertValidMode(string $mode): void
    {
        if (! in_array($mode, self::Modes, true)) {
            throw new InvalidArgumentException('Unsupported KOL approval policy mode.');
        }
    }

    /** @return array<string,int> */
    private function normalizeThresholds(array $thresholds): array
    {
        $normalized = [];
        foreach ($thresholds as $platform => $threshold) {
            $key = strtolower(trim((string) $platform));
            if ($key === '' || ! is_int($threshold) || $threshold < 0) {
                throw new InvalidArgumentException('Invalid KOL approval threshold configuration.');
            }
            $normalized[$key] = $threshold;
        }

        return $normalized;
    }
}
