<?php

namespace Trendyminds\Distributary\Actions;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Trendyminds\Distributary\Services\AiBlockMapper;
use Trendyminds\Distributary\Services\AssetImporter;
use Trendyminds\Distributary\Services\BlueprintIntrospector;
use Trendyminds\Distributary\Services\HtmlZipParser;
use Trendyminds\Distributary\Support\ImportStatus;
use Trendyminds\Distributary\Support\ImportStore;

class ImportDocument
{
    public function __construct(
        private HtmlZipParser $parser,
        private AssetImporter $assetImporter,
        private BlueprintIntrospector $introspector,
        private AiBlockMapper $mapper,
        private ImportStore $store,
    ) {}

    public function __invoke(string $importId, string $storedPath, string $originalFilename): void
    {
        $absolutePath = Storage::disk('local')->path($storedPath);
        $parsed = null;

        try {
            $parsed = $this->step(
                $importId,
                'Parsing document…',
                fn () => $this->parser->parse($absolutePath),
                'Could not parse this .zip file.',
            );

            $imageMap = $this->step(
                $importId,
                'Importing images…',
                fn () => $this->assetImporter->import($parsed->images),
                'Could not import images from the document.',
            );

            $blueprint = $this->introspector->inspect();

            $mapped = $this->step(
                $importId,
                'Mapping content with AI…',
                fn () => $this->mapper->map(
                    documentHtml: $parsed->html,
                    blueprint: $blueprint,
                    imageMap: $imageMap,
                    documentTitle: $parsed->title,
                    fileHash: (string) hash_file('sha256', $absolutePath),
                ),
                'AI mapping failed.',
            );

            $this->store->putPreview($importId, [
                'mapped' => [
                    'title' => $mapped->title,
                    'blocks' => $mapped->blocks,
                    'meta' => $mapped->meta,
                ],
                'blueprint' => $blueprint,
                'image_map' => $imageMap,
                'source_filename' => $originalFilename,
                'used_cache' => (bool) ($mapped->meta['cached'] ?? false),
            ]);

            $this->store->setStatus(
                $importId,
                ImportStatus::Complete,
                message: 'Done',
                previewUrl: cp_route('utilities.distributary.preview', ['importId' => $importId]),
            );
        } finally {
            $parsed?->cleanup();
            Storage::disk('local')->delete($storedPath);
        }
    }

    /**
     * Run one pipeline step: surface its progress message before, and on failure
     * record a user-facing error before re-throwing so the queue marks the job failed.
     */
    private function step(string $importId, string $message, Closure $work, string $errorPrefix): mixed
    {
        $this->store->setStatus($importId, ImportStatus::Processing, message: $message);

        try {
            return $work();
        } catch (Throwable $e) {
            $error = trim($errorPrefix.' '.$e->getMessage());
            Log::error('distributary: '.$error);
            $this->store->setStatus($importId, ImportStatus::Failed, error: $error);

            throw $e;
        }
    }
}
