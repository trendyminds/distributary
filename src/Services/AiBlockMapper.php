<?php

namespace Trendyminds\Distributary\Services;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\AnonymousAgent;
use RuntimeException;
use Trendyminds\Distributary\Support\ImportStore;

class AiBlockMapper
{
    /** Bump when the system prompt template changes so existing caches are invalidated. */
    private const PROMPT_VERSION = 2;

    public function __construct(private ImportStore $store) {}

    /**
     * Identifier representing this mapper's configuration, used as part of mapping
     * cache keys so changes to provider, model, or prompt version invalidate stale results.
     */
    public function signature(): string
    {
        return implode(':', [
            (string) config('distributary.provider'),
            (string) config('distributary.model'),
            'p'.self::PROMPT_VERSION,
        ]);
    }

    /**
     * @param  array<string, mixed>  $blueprint  Output of {@see BlueprintIntrospector::inspect()}.
     * @param  array<string, array{path: string, url: string, alt: string}>  $imageMap  import-id => asset info.
     * @param  ?string  $fileHash  Provide to enable caching keyed on file + blueprint + mapper signature.
     */
    public function map(
        string $documentHtml,
        array $blueprint,
        array $imageMap,
        ?string $documentTitle = null,
        ?string $fileHash = null,
    ): MappedDocument {
        $cacheKey = $fileHash !== null ? $this->cacheKey($fileHash, $blueprint) : null;

        if ($cacheKey !== null && $cached = $this->cachedMapping($cacheKey)) {
            return $cached;
        }

        $mapped = $this->callAgent($documentHtml, $blueprint, $imageMap, $documentTitle);

        if ($cacheKey !== null) {
            $this->store->putMapping($cacheKey, [
                'title' => $mapped->title,
                'blocks' => $mapped->blocks,
                'meta' => $mapped->meta,
            ]);
        }

        return $mapped;
    }

    private function cachedMapping(string $cacheKey): ?MappedDocument
    {
        $cached = $this->store->mapping($cacheKey);

        if (! is_array($cached) || ! isset($cached['title'], $cached['blocks'])) {
            return null;
        }

        return new MappedDocument(
            title: (string) $cached['title'],
            blocks: (array) $cached['blocks'],
            meta: array_merge((array) ($cached['meta'] ?? []), ['cached' => true]),
        );
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  array<string, array{path: string, url: string, alt: string}>  $imageMap
     */
    private function callAgent(
        string $documentHtml,
        array $blueprint,
        array $imageMap,
        ?string $documentTitle,
    ): MappedDocument {
        $provider = config('distributary.provider');
        $model = config('distributary.model');

        $agent = new AnonymousAgent(
            instructions: $this->buildSystemPrompt($blueprint, $imageMap),
            messages: [],
            tools: [],
        );

        $response = $agent->prompt(
            prompt: $this->buildUserMessage($documentHtml, $documentTitle),
            provider: $provider,
            model: $model,
        );

        $payload = $this->extractJson($response->text);

        if (! is_array($payload) || ! isset($payload['blocks']) || ! is_array($payload['blocks'])) {
            Log::warning('distributary: AI response did not include a blocks array', [
                'raw' => mb_substr($response->text, 0, 1500),
            ]);

            throw new RuntimeException('The AI response did not include a valid blocks array. See logs for details.');
        }

        return new MappedDocument(
            title: (string) ($payload['title'] ?? $documentTitle ?? 'Untitled draft'),
            blocks: $this->normalizeBlocks($payload['blocks']),
            meta: [
                'provider' => $provider,
                'model' => $model,
                'raw_length' => mb_strlen($response->text),
                'cached' => false,
            ],
        );
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @return array<int, array{type: string, fields: array<string, mixed>}>
     */
    private function normalizeBlocks(array $blocks): array
    {
        $normalized = [];

        foreach ($blocks as $block) {
            if (! is_array($block) || empty($block['type'])) {
                continue;
            }

            $normalized[] = [
                'type' => (string) $block['type'],
                'fields' => is_array($block['fields'] ?? null) ? $block['fields'] : [],
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $blueprint
     */
    private function cacheKey(string $fileHash, array $blueprint): string
    {
        $blueprintHash = hash('sha256', (string) json_encode($blueprint));

        return 'distributary:mapping:'.$fileHash.':'.$blueprintHash.':'.$this->signature();
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  array<string, array{path: string, url: string, alt: string}>  $imageMap
     */
    private function buildSystemPrompt(array $blueprint, array $imageMap): string
    {
        $blockCatalog = $this->renderBlockCatalog($blueprint['sets'] ?? []);
        $imageCatalog = $this->renderImageCatalog($imageMap);

        return <<<PROMPT
You are an editor migrating long-form content into a Statamic CMS page that is composed
of typed blocks. Your job is to read the document HTML and emit a JSON object describing
the page's title and an ordered list of blocks that best represent the document's content.

The collection is "{$blueprint['collection']}" and its replicator field is "{$blueprint['replicator_field']}".

# AVAILABLE BLOCK TYPES
{$blockCatalog}

# IMAGES EXTRACTED FROM THE DOCUMENT
{$imageCatalog}

# OUTPUT
Return a single JSON object with no other commentary, prose, or markdown fences. Shape:

{
  "title": "Page title — use the document's H1 if present, otherwise infer a concise title.",
  "blocks": [
    { "type": "<one of the block handles above>", "fields": { /* per-block fields */ } }
  ]
}

Nested replicator fields (any field listed with `nested_sets:` in the catalog) accept an
ARRAY of items. Each item MUST use the same `{ "type": "...", "fields": { ... } }` shape
as a top-level block, where `type` is one of the `nested_sets` handles. Example:

{
  "type": "<block_with_nested_replicator>",
  "fields": {
    "<replicator_field_handle>": [
      {
        "type": "<nested_set_handle>",
        "fields": {
          "<nested_field_a>": "value",
          "<nested_field_b>": "<p>value</p>"
        }
      }
    ]
  }
}

# RULES
- Use only block handles that appear in AVAILABLE BLOCK TYPES.
- Every block and every nested replicator item MUST include populated fields. NEVER emit
  an item that has only a `type` with no `fields`, or whose `fields` object is empty —
  either fill it in from the document, or omit the item entirely.
- For Bard (rich_text_html) fields, return clean HTML using only the tags listed in
  `allowed_block_tags` and `allowed_inline_tags` for that field. No <h1>; the page title
  is rendered separately. Use <h2> for top-level sections inside rich text.
- For asset_reference fields, set the value to the relevant import-id (e.g. "import-img-1")
  from the IMAGES list. Omit the field entirely if no image fits.
- For callout/link fields, return an object: {"url": "<href>", "label": "<text>", "new_window": false}.
- Group consecutive prose (headings + paragraphs + lists) into a single rich_text block
  rather than many small ones. When in doubt, prefer rich_text.
- If the document contains a clearly bounded set of Q&A or expand/collapse items, use accordions.
- If the document contains a pull-quote with attribution, use testimonial.
- If the document contains a video URL, use the video block (set `video` to the URL).
- Skip embedded images inside rich_text — use cta_image (or similar image-bearing block) when
  the image is meaningful, otherwise drop the image.
- The first H1 in the document is the page title and SHOULD NOT appear again in any block.
- Do not invent content. If a required field has no source in the document, choose a different
  block type that fits the source content, or use rich_text.

PROMPT;
    }

    private function buildUserMessage(string $documentHtml, ?string $title): string
    {
        $prefix = $title !== null && $title !== ''
            ? "Document title (detected from H1): {$title}\n\n"
            : '';

        return $prefix.<<<MSG
Map the following document HTML into a JSON page-block structure following the rules above.

<document_html>
{$documentHtml}
</document_html>
MSG;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sets
     */
    private function renderBlockCatalog(array $sets): string
    {
        if (empty($sets)) {
            return '(no blocks available)';
        }

        return collect($sets)
            ->map(fn (array $set) => $this->renderSet($set))
            ->implode("\n\n");
    }

    /**
     * @param  array<string, mixed>  $set
     */
    private function renderSet(array $set): string
    {
        $lines = ["## {$set['handle']} — {$set['display']}"];

        if (! empty($set['instructions'])) {
            $lines[] = $set['instructions'];
        }

        $lines[] = 'Fields:';
        $lines[] = $this->renderFields($set['fields'] ?? [], 1);

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    private function renderFields(array $fields, int $depth): string
    {
        $indent = str_repeat('  ', $depth);

        return collect($fields)
            ->map(fn (array $field) => $this->renderField($field, $indent, $depth))
            ->implode("\n");
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function renderField(array $field, string $indent, int $depth): string
    {
        $line = "{$indent}- {$field['handle']} ({$field['type']})";

        if (! empty($field['required'])) {
            $line .= ' [required]';
        }
        if (! empty($field['display']) && $field['display'] !== $field['handle']) {
            $line .= " — {$field['display']}";
        }
        if (! empty($field['instructions'])) {
            $line .= ' · '.$field['instructions'];
        }
        if (! empty($field['allowed_block_tags'])) {
            $line .= ' · allowed_tags: '.implode(',', $field['allowed_block_tags']);
        }
        if (! empty($field['options'])) {
            $line .= ' · options: '.implode('|', $field['options']);
        }
        if (! empty($field['note'])) {
            $line .= ' · note: '.$field['note'];
        }
        if (! empty($field['shape'])) {
            $shape = collect($field['shape'])
                ->map(fn ($v, $k) => "{$k}: {$v}")
                ->implode(', ');
            $line .= ' · shape: { '.$shape.' }';
        }

        if (empty($field['nested_sets'])) {
            return $line;
        }

        $nested = ["{$indent}  nested_sets:"];
        foreach ($field['nested_sets'] as $nestedSet) {
            $nested[] = "{$indent}    - {$nestedSet['handle']} ({$nestedSet['display']})";
            $nested[] = $this->renderFields($nestedSet['fields'] ?? [], $depth + 3);
        }

        return $line."\n".implode("\n", $nested);
    }

    /**
     * @param  array<string, array{path: string, url: string, alt: string}>  $imageMap
     */
    private function renderImageCatalog(array $imageMap): string
    {
        if (empty($imageMap)) {
            return '(no images extracted from document)';
        }

        return collect($imageMap)
            ->map(fn (array $info, string $importId) => "- {$importId}: alt=\"".($info['alt'] ?: '(no alt text)').'"')
            ->values()
            ->implode("\n");
    }

    /**
     * Pull JSON out of the model response, tolerating fenced code blocks and trailing prose.
     *
     * @return array<string, mixed>|null
     */
    private function extractJson(string $text): ?array
    {
        $text = trim($text);

        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/m', $text, $matches)) {
            $text = trim($matches[1]);
        }

        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }

        $candidate = substr($text, $start);

        if (is_array($decoded = json_decode($candidate, true))) {
            return $decoded;
        }

        $end = strrpos($candidate, '}');
        if ($end === false) {
            return null;
        }

        $decoded = json_decode(substr($candidate, 0, $end + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
