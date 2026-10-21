<?php

namespace Tests\Unit\Kol;

use App\Modules\Kol\Application\Approval\KolApprovalPolicyEvaluator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KolApprovalPolicyEvaluatorTest extends TestCase
{
    private KolApprovalPolicyEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new KolApprovalPolicyEvaluator;
    }

    public function test_all_requires_manager_for_specialist(): void
    {
        $decision = $this->evaluator->evaluate('ALL', [], []);
        self::assertTrue($decision->requiresManagerApproval());
        self::assertSame('policy_all_requires_manager', $decision->reasonCode);
    }

    public function test_none_auto_approves_specialist(): void
    {
        $decision = $this->evaluator->evaluate('NONE', [], []);
        self::assertTrue($decision->isApproved());
        self::assertSame('policy_none_auto_approved', $decision->reasonCode);
    }

    #[DataProvider('privilegedModes')]
    public function test_manager_or_admin_creator_bypasses_specialist_policy(string $mode): void
    {
        $decision = $this->evaluator->evaluate($mode, ['instagram' => 100000], [], true);
        self::assertTrue($decision->isApproved());
        self::assertSame('privileged_creator', $decision->reasonCode);
    }

    public static function privilegedModes(): array
    {
        return [['ALL'], ['THRESHOLD'], ['NONE']];
    }

    public function test_threshold_auto_approves_when_any_reliable_configured_platform_reaches_threshold(): void
    {
        $decision = $this->evaluator->evaluate('THRESHOLD', ['instagram' => 100000, 'youtube' => 50000], [
            ['platform' => 'instagram', 'is_active' => true, 'followers' => 99999],
            ['platform' => 'youtube', 'is_active' => true, 'followers' => 50000],
        ]);
        self::assertTrue($decision->isApproved());
        self::assertSame('threshold_reached', $decision->reasonCode);
        self::assertSame('youtube', $decision->evidence['matched_platform']);
    }

    public function test_threshold_requires_manager_when_every_comparable_value_is_below_threshold(): void
    {
        $decision = $this->evaluator->evaluate('THRESHOLD', ['instagram' => 100000, 'youtube' => 50000], [
            ['platform' => 'instagram', 'is_active' => true, 'followers' => 80000],
            ['platform' => 'youtube', 'is_active' => true, 'followers' => 49999],
        ]);
        self::assertTrue($decision->requiresManagerApproval());
        self::assertSame('thresholds_below_requirement', $decision->reasonCode);
    }

    public function test_threshold_requires_manager_when_reliable_data_is_missing(): void
    {
        $decision = $this->evaluator->evaluate('THRESHOLD', ['instagram' => 100000], [
            ['platform' => 'instagram', 'is_active' => true, 'followers' => null],
        ]);
        self::assertTrue($decision->requiresManagerApproval());
        self::assertSame('threshold_data_missing_or_unreliable', $decision->reasonCode);
    }

    public function test_threshold_requires_manager_when_no_active_configured_platform_is_present(): void
    {
        $decision = $this->evaluator->evaluate('THRESHOLD', ['instagram' => 100000], [
            ['platform' => 'instagram', 'is_active' => false, 'followers' => 200000],
            ['platform' => 'tiktok', 'is_active' => true, 'followers' => 500000],
        ]);
        self::assertTrue($decision->requiresManagerApproval());
        self::assertSame('threshold_data_missing_or_unreliable', $decision->reasonCode);
    }

    public function test_unreliable_other_platform_does_not_block_a_reliable_threshold_match(): void
    {
        $decision = $this->evaluator->evaluate('THRESHOLD', ['instagram' => 100000, 'youtube' => 50000], [
            ['platform' => 'instagram', 'is_active' => true, 'followers' => null],
            ['platform' => 'youtube', 'is_active' => true, 'followers' => 60000],
        ]);
        self::assertTrue($decision->isApproved());
        self::assertSame('threshold_reached', $decision->reasonCode);
    }

    public function test_empty_threshold_configuration_fails_closed_to_manual_approval(): void
    {
        $decision = $this->evaluator->evaluate('THRESHOLD', [], [
            ['platform' => 'instagram', 'is_active' => true, 'followers' => 500000],
        ]);
        self::assertTrue($decision->requiresManagerApproval());
        self::assertSame('threshold_not_configured', $decision->reasonCode);
    }

    public function test_unknown_mode_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->evaluator->evaluate('AUTO', [], []);
    }

    public function test_invalid_threshold_configuration_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->evaluator->evaluate('THRESHOLD', ['instagram' => -1], []);
    }
}
