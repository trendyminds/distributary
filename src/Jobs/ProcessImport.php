<?php

namespace Trendyminds\Distributary\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Trendyminds\Distributary\Actions\ImportDocument;
use Trendyminds\Distributary\Support\ImportStatus;
use Trendyminds\Distributary\Support\ImportStore;

class ProcessImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Bard mapping can take 30–60s; the full pipeline rarely exceeds 3 min. */
    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public string $importId,
        public string $storedPath,
        public string $originalFilename,
    ) {}

    public function handle(ImportDocument $import): void
    {
        $import($this->importId, $this->storedPath, $this->originalFilename);
    }

    public function failed(Throwable $exception, ImportStore $store): void
    {
        $store->setStatus($this->importId, ImportStatus::Failed, error: $exception->getMessage());
        Storage::disk('local')->delete($this->storedPath);
    }
}
