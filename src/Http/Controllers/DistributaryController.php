<?php

namespace Trendyminds\Distributary\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Statamic\Http\Controllers\CP\CpController;
use Throwable;
use Trendyminds\Distributary\Http\Requests\ConfirmImportRequest;
use Trendyminds\Distributary\Http\Requests\UploadDocumentRequest;
use Trendyminds\Distributary\Jobs\ProcessImport;
use Trendyminds\Distributary\Services\EntryBuilder;
use Trendyminds\Distributary\Services\MappedDocument;
use Trendyminds\Distributary\Support\ImportStatus;
use Trendyminds\Distributary\Support\ImportStore;

class DistributaryController extends CpController
{
    public function show(): View
    {
        return view('distributary::upload');
    }

    public function upload(UploadDocumentRequest $request, ImportStore $store): RedirectResponse
    {
        $importId = (string) Str::uuid();
        $storedPath = $request->file('document')->store('distributary');

        $store->setStatus($importId, ImportStatus::Queued, message: 'Queued for processing…');

        ProcessImport::dispatch(
            importId: $importId,
            storedPath: $storedPath,
            originalFilename: $request->file('document')->getClientOriginalName(),
        );

        return redirect(cp_route('utilities.distributary.processing', ['importId' => $importId]));
    }

    public function processing(string $importId, ImportStore $store): View|RedirectResponse
    {
        $status = $store->status($importId);

        if (! $status) {
            return $this->expired('Import not found or has expired. Please re-upload the document.');
        }

        if ($status['state'] === ImportStatus::Complete->value && ! empty($status['preview_url'])) {
            return redirect($status['preview_url']);
        }

        return view('distributary::processing', [
            'importId' => $importId,
            'initialStatus' => $status,
            'statusUrl' => cp_route('utilities.distributary.status', ['importId' => $importId]),
            'cancelUrl' => cp_route('utilities.distributary'),
        ]);
    }

    public function status(string $importId, ImportStore $store): JsonResponse
    {
        $status = $store->status($importId);

        if (! $status) {
            return response()->json([
                'state' => ImportStatus::Failed->value,
                'message' => null,
                'error' => 'Import not found or has expired.',
                'preview_url' => null,
            ], 404);
        }

        return response()->json($status);
    }

    public function preview(string $importId, ImportStore $store): View|RedirectResponse
    {
        $state = $store->preview($importId);

        if (! $state) {
            return $this->expired('Preview expired. Please re-upload the document.');
        }

        return view('distributary::preview', [
            'importId' => $importId,
            'title' => $state['mapped']['title'],
            'blocks' => $state['mapped']['blocks'],
            'meta' => $state['mapped']['meta'],
            'imageMap' => $state['image_map'],
            'sourceFilename' => $state['source_filename'],
            'collection' => $state['blueprint']['collection'],
            'usedCache' => $state['used_cache'] ?? false,
        ]);
    }

    public function confirm(
        string $importId,
        ConfirmImportRequest $request,
        ImportStore $store,
        EntryBuilder $builder,
    ): RedirectResponse {
        $state = $store->preview($importId);

        if (! $state) {
            return $this->expired('Preview expired. Please re-upload the document.');
        }

        try {
            $entry = $builder->build(
                new MappedDocument(
                    title: $request->validated('title'),
                    blocks: $state['mapped']['blocks'],
                    meta: $state['mapped']['meta'] ?? [],
                ),
                $state['blueprint'],
                $state['image_map'],
            );
        } catch (Throwable $e) {
            Log::error('distributary: entry build failed', ['message' => $e->getMessage()]);

            return back()->withErrors(['document' => 'Could not create entry: '.$e->getMessage()]);
        }

        $store->forget($importId);

        return redirect($entry->editUrl())
            ->with('success', "Draft entry created from {$state['source_filename']}. Review and publish when ready.");
    }

    private function expired(string $message): RedirectResponse
    {
        return redirect(cp_route('utilities.distributary'))
            ->withErrors(['document' => $message]);
    }
}
