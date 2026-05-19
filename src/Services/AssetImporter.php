<?php

namespace Trendyminds\Distributary\Services;

use Illuminate\Support\Str;
use RuntimeException;
use Statamic\Assets\Asset;
use Statamic\Facades\AssetContainer;

class AssetImporter
{
    /**
     * Upload extracted image files into a Statamic asset container, streaming each
     * file directly off disk so we never hold the full image bytes in memory.
     *
     * @param  array<int, array{id: string, path: string, extension: string, alt: string}>  $images
     * @param  string  $folder  Subfolder inside the container, e.g. "distributary/2026-05".
     * @return array<string, array{path: string, url: string, alt: string}> Map of import-id → asset info.
     */
    public function import(array $images, string $containerHandle = 'uploads', string $folder = 'distributary'): array
    {
        if (empty($images)) {
            return [];
        }

        $container = AssetContainer::find($containerHandle);

        if (! $container) {
            throw new RuntimeException("Asset container [{$containerHandle}] not found.");
        }

        $subfolder = trim($folder, '/').'/'.now()->format('Y-m');
        $disk = $container->disk();
        $map = [];

        foreach ($images as $image) {
            $extension = $this->safeExtension($image['extension'] ?? 'png');
            $filename = $this->uniqueFilename($subfolder, $extension);
            $path = $subfolder.'/'.$filename;

            $stream = fopen($image['path'], 'rb');
            if ($stream === false) {
                throw new RuntimeException("Could not read extracted image at {$image['path']}.");
            }

            try {
                $disk->put($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            /** @var Asset $asset */
            $asset = $container->makeAsset($path);
            if (! empty($image['alt'])) {
                $asset->set('alt', $image['alt']);
            }
            $asset->save();

            $map[$image['id']] = [
                'path' => $path,
                'url' => $asset->url(),
                'alt' => $image['alt'] ?? '',
            ];
        }

        return $map;
    }

    protected function safeExtension(string $extension): string
    {
        $extension = strtolower(preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'png');
        $allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];

        return in_array($extension, $allowed, true) ? $extension : 'png';
    }

    protected function uniqueFilename(string $folder, string $extension): string
    {
        return 'ai-'.Str::lower(Str::random(12)).'.'.$extension;
    }
}
