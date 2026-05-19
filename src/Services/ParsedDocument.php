<?php

namespace Trendyminds\Distributary\Services;

use Illuminate\Support\Facades\File;

class ParsedDocument
{
    /**
     * @param  array<int, array{id: string, path: string, extension: string, alt: string}>  $images
     */
    public function __construct(
        public readonly string $html,
        public readonly array $images,
        public readonly ?string $title = null,
        public readonly ?string $tempDir = null,
    ) {}

    /**
     * Remove the temp directory holding extracted image files. Safe to call multiple
     * times — the caller is responsible for invoking it once asset import is done.
     */
    public function cleanup(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
    }
}
