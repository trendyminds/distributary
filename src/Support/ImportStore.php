<?php

namespace Trendyminds\Distributary\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Single repository for everything an import keeps in the cache: live status,
 * preview state handed off between the job and the preview/confirm requests,
 * and long-lived AI mapping results.
 */
class ImportStore
{
    private const STATUS_PREFIX = 'distributary:status:';

    private const PREVIEW_PREFIX = 'distributary:';

    private const STATUS_TTL_HOURS = 2;

    private const PREVIEW_TTL_MINUTES = 60;

    private const MAPPING_TTL_DAYS = 30;

    public function setStatus(
        string $importId,
        ImportStatus $state,
        ?string $message = null,
        ?string $error = null,
        ?string $previewUrl = null,
    ): void {
        Cache::put($this->statusKey($importId), [
            'state' => $state->value,
            'message' => $message,
            'error' => $error,
            'preview_url' => $previewUrl,
        ], now()->addHours(self::STATUS_TTL_HOURS));
    }

    /**
     * @return array{state: string, message: ?string, error: ?string, preview_url: ?string}|null
     */
    public function status(string $importId): ?array
    {
        $raw = Cache::get($this->statusKey($importId));

        return is_array($raw) ? $raw : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function putPreview(string $importId, array $payload): void
    {
        Cache::put(
            $this->previewKey($importId),
            $payload,
            now()->addMinutes(self::PREVIEW_TTL_MINUTES),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function preview(string $importId): ?array
    {
        $raw = Cache::get($this->previewKey($importId));

        return is_array($raw) ? $raw : null;
    }

    public function forget(string $importId): void
    {
        Cache::forget($this->statusKey($importId));
        Cache::forget($this->previewKey($importId));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function mapping(string $key): ?array
    {
        $raw = Cache::get($key);

        return is_array($raw) ? $raw : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function putMapping(string $key, array $payload): void
    {
        Cache::put($key, $payload, now()->addDays(self::MAPPING_TTL_DAYS));
    }

    private function statusKey(string $importId): string
    {
        return self::STATUS_PREFIX.$importId;
    }

    private function previewKey(string $importId): string
    {
        return self::PREVIEW_PREFIX.$importId;
    }
}
