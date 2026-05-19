<?php

namespace Trendyminds\Distributary\Services;

use Illuminate\Support\Str;
use RuntimeException;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as EntryFacade;
use Tiptap\Editor as TiptapEditor;

class EntryBuilder
{
    public function __construct(protected ?TiptapEditor $tiptap = null) {}

    /**
     * Create a draft entry for the given mapped document.
     *
     * @param  array<string, mixed>  $blueprint  Output of BlueprintIntrospector::inspect().
     * @param  array<string, array{path: string, url: string, alt: string}>  $imageMap
     */
    public function build(MappedDocument $doc, array $blueprint, array $imageMap): Entry
    {
        $collectionHandle = $blueprint['collection'];
        $collection = Collection::find($collectionHandle);

        if (! $collection) {
            throw new RuntimeException("Collection [{$collectionHandle}] not found.");
        }

        $setIndex = $this->indexSets($blueprint['sets'] ?? []);

        $blocks = collect($doc->blocks)
            ->map(fn (array $block) => $this->buildSet($block, $setIndex, $imageMap))
            ->filter()
            ->values()
            ->all();

        $slug = $this->uniqueSlug($collectionHandle, $doc->title);

        $entry = EntryFacade::make()
            ->collection($collection)
            ->blueprint($collection->entryBlueprint()->handle())
            ->slug($slug)
            ->published(false)
            ->data([
                'title' => $doc->title,
                $blueprint['replicator_field'] => $blocks,
            ]);

        if ($collection->dated()) {
            $entry->date(now());
        }

        $entry->save();

        return $entry;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sets
     * @return array<string, array<string, mixed>>
     */
    protected function indexSets(array $sets): array
    {
        $index = [];
        foreach ($sets as $set) {
            $index[$set['handle']] = $set;
        }

        return $index;
    }

    /**
     * Build a single block (set) row.
     *
     * @param  array{type: string, fields: array<string, mixed>}  $block
     * @param  array<string, array<string, mixed>>  $setIndex
     * @param  array<string, array{path: string, url: string, alt: string}>  $imageMap
     * @return array<string, mixed>|null
     */
    protected function buildSet(array $block, array $setIndex, array $imageMap): ?array
    {
        $type = $block['type'];
        $setDefinition = $setIndex[$type] ?? null;

        if (! $setDefinition) {
            // Unknown block type from AI — drop it rather than corrupt the entry.
            return null;
        }

        $row = [
            'type' => $type,
            'enabled' => true,
        ];

        foreach ($setDefinition['fields'] ?? [] as $fieldDefinition) {
            $handle = $fieldDefinition['handle'] ?? null;
            if (! $handle) {
                continue;
            }

            $rawValue = $block['fields'][$handle] ?? null;
            if ($this->isEmptyValue($rawValue)) {
                continue;
            }

            $converted = $this->convertField($rawValue, $fieldDefinition, $imageMap);
            if ($this->isEmptyValue($converted)) {
                continue;
            }

            $row[$handle] = $converted;
        }

        // A row with only `type` + `enabled` is a hallucinated empty block — drop it
        // rather than leaving the editor with an empty placeholder to clean up.
        if (! $this->rowHasContent($row)) {
            return null;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $fieldDefinition
     * @param  array<string, array{path: string, url: string, alt: string}>  $imageMap
     */
    protected function convertField(mixed $value, array $fieldDefinition, array $imageMap): mixed
    {
        $type = $fieldDefinition['type'] ?? 'string';

        return match ($type) {
            'rich_text_html' => $this->htmlToBard(is_string($value) ? $value : ''),
            'asset_reference' => $this->resolveAsset($value, $imageMap),
            'link' => $this->normalizeCallout($value),
            'replicator' => $this->buildNestedReplicator($value, $fieldDefinition, $imageMap),
            'boolean' => (bool) $value,
            'select' => is_string($value) ? $value : null,
            default => is_scalar($value) ? (string) $value : $value,
        };
    }

    protected function htmlToBard(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $this->tiptap ??= new TiptapEditor;
        $doc = $this->tiptap->setContent($html)->getDocument();

        return $doc['content'] ?? [];
    }

    /**
     * @param  array<string, array{path: string, url: string, alt: string}>  $imageMap
     */
    protected function resolveAsset(mixed $value, array $imageMap): mixed
    {
        if (is_string($value) && isset($imageMap[$value])) {
            return 'uploads::'.$imageMap[$value]['path'];
        }

        // Already an asset reference, leave it. Anything unrecognized → null (skip).
        if (is_string($value) && str_contains($value, '::')) {
            return $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|string  $value
     * @return array<string, mixed>
     */
    protected function normalizeCallout(mixed $value): array
    {
        if (is_string($value)) {
            $value = ['url' => $value];
        }

        if (! is_array($value)) {
            return [];
        }

        return [
            'url' => (string) ($value['url'] ?? ''),
            'label' => (string) ($value['label'] ?? ''),
            'new_window' => (bool) ($value['new_window'] ?? false),
        ];
    }

    /**
     * @param  array<int, mixed>  $value
     * @param  array<string, mixed>  $fieldDefinition
     * @param  array<string, array{path: string, url: string, alt: string}>  $imageMap
     * @return array<int, array<string, mixed>>
     */
    protected function buildNestedReplicator(mixed $value, array $fieldDefinition, array $imageMap): array
    {
        if (! is_array($value)) {
            return [];
        }

        $nestedIndex = [];
        foreach ($fieldDefinition['nested_sets'] ?? [] as $nestedSet) {
            $nestedIndex[$nestedSet['handle']] = $nestedSet;
        }

        // Default to the first nested set if items don't carry a type.
        $defaultType = array_key_first($nestedIndex);

        $rows = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemType = $item['type'] ?? $defaultType;
            $nested = $nestedIndex[$itemType] ?? null;
            if (! $nested) {
                continue;
            }

            $row = [
                'type' => $itemType,
                'enabled' => true,
            ];

            // Items can arrive with either `fields` wrapping or flat keys.
            $itemFields = is_array($item['fields'] ?? null) ? $item['fields'] : $item;

            foreach ($nested['fields'] ?? [] as $nestedFieldDef) {
                $h = $nestedFieldDef['handle'] ?? null;
                if (! $h) {
                    continue;
                }

                $raw = $itemFields[$h] ?? null;
                if ($this->isEmptyValue($raw)) {
                    continue;
                }

                $converted = $this->convertField($raw, $nestedFieldDef, $imageMap);
                if ($this->isEmptyValue($converted)) {
                    continue;
                }

                $row[$h] = $converted;
            }

            // Skip items the AI emitted as `{type: "..."}` with no field content —
            // they'd show up in the editor as empty rows the user has to delete.
            if (! $this->rowHasContent($row)) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * A row "has content" if at least one field beyond the structural `type` / `enabled`
     * keys is populated. Used to drop AI-hallucinated empty blocks and nested items.
     *
     * @param  array<string, mixed>  $row
     */
    protected function rowHasContent(array $row): bool
    {
        foreach ($row as $key => $value) {
            if (in_array($key, ['type', 'enabled'], true)) {
                continue;
            }
            if (! $this->isEmptyValue($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect values the AI returned as "empty" — null, '', [], or normalized callout
     * shapes where every meaningful key is blank.
     */
    protected function isEmptyValue(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (is_array($value) && array_keys($value) === ['url', 'label', 'new_window']) {
            return $value['url'] === '' && $value['label'] === '';
        }

        return false;
    }

    protected function uniqueSlug(string $collectionHandle, string $title): string
    {
        $base = Str::slug($title) ?: 'distributary-'.now()->format('Y-m-d-His');
        $slug = $base;
        $suffix = 1;

        while (EntryFacade::query()->where('collection', $collectionHandle)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
