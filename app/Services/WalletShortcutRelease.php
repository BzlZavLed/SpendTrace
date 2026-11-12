<?php

namespace App\Services;

class WalletShortcutRelease
{
    public function origin(): ?string
    {
        $value = rtrim(config('spendtrace.wallet_public_url') ?: config('app.url'), '/');
        $parts = parse_url($value);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! empty($parts['path'])) {
            return null;
        }

        return $value;
    }

    public function present(): array
    {
        $url = config('spendtrace.wallet_shortcut_url');
        $valid = is_string($url) && preg_match('~^https://www\.icloud\.com/shortcuts/[a-f0-9]{32}$~iD', $url)
            && config('spendtrace.wallet_shortcut_verified')
            && preg_match('/^\d+\.\d+\.\d+$/D', (string) config('spendtrace.wallet_shortcut_version'));

        return ['name' => 'SpendTrace — Capture Purchase', 'version' => config('spendtrace.wallet_shortcut_version'),
            'available' => (bool) $valid, 'url' => $valid ? $url : null, 'pairing_available' => $this->origin() !== null,
            'reason' => $valid ? null : 'No validated shortcut release is published yet. Installation is unavailable; pairing and ingestion services are ready for release validation.'];
    }
}
