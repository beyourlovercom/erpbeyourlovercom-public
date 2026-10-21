<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VisualStatusContrastContractTest extends TestCase
{
    #[Test]
    public function content_status_and_readonly_surfaces_use_semantic_tokens_not_navigation_hover(): void
    {
        $css = file_get_contents(resource_path('css/shell.css'));
        $status = $this->rule($css, '.mvp-status');
        $readonly = $this->rule($css, '.mvp-readonly');

        $this->assertStringContainsString('background: var(--byl-status-bg)', $status);
        $this->assertStringContainsString('color: var(--byl-status-ink)', $status);
        $this->assertStringNotContainsString('--byl-color-shell-nav-hover', $status);
        $this->assertStringContainsString('background: var(--byl-readonly-bg)', $readonly);
        $this->assertStringContainsString('color: var(--byl-readonly-ink)', $readonly);
        $this->assertStringNotContainsString('--byl-color-shell-nav-hover', $readonly);
    }

    #[Test]
    public function light_status_pair_meets_normal_text_contrast_floor(): void
    {
        $tokens = file_get_contents(resource_path('css/tokens.css'));
        $background = $this->tokenHex($tokens, '--byl-color-shell-status-bg');
        $foreground = $this->tokenHex($tokens, '--byl-color-shell-status-ink');

        $this->assertGreaterThanOrEqual(4.5, $this->contrast($background, $foreground));
    }
    private function rule(string $css, string $selector): string
    {
        preg_match('/'.preg_quote($selector, '/').'\s*\{([^}]*)\}/s', $css, $matches);
        $this->assertArrayHasKey(1, $matches, "Missing CSS rule {$selector}");

        return $matches[1];
    }

    private function tokenHex(string $css, string $token): string
    {
        preg_match('/'.preg_quote($token, '/').'\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $css, $matches);
        $this->assertArrayHasKey(1, $matches, "Missing color token {$token}");

        return $matches[1];
    }

    private function contrast(string $first, string $second): float
    {
        $lighter = max($this->luminance($first), $this->luminance($second));
        $darker = min($this->luminance($first), $this->luminance($second));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function luminance(string $hex): float
    {
        $channels = [
            hexdec(substr($hex, 1, 2)) / 255,
            hexdec(substr($hex, 3, 2)) / 255,
            hexdec(substr($hex, 5, 2)) / 255,
        ];
        $linear = array_map(
            fn (float $value): float => $value <= 0.04045
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4,
            $channels,
        );

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }
}
