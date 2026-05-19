<?php

namespace Trendyminds\Distributary\Services;

use RuntimeException;
use Statamic\Facades\Collection;

class BlueprintIntrospector
{
    /** "listings" sets don't accept content — they're dynamic queries. */
    private const SKIPPED_GROUPS = ['listings'];

    /**
     * - form: author needs to choose an existing form, not something AI can infer.
     * - scroll_landmark: anchor markers, irrelevant for document content.
     */
    private const SKIPPED_SETS = ['form', 'scroll_landmark'];

    /** "spacing" is a style preset; the default is fine for AI imports. */
    private const SKIPPED_FIELDS = ['spacing'];

    /** "spacing" is also imported by handle in many sets — skip it everywhere. */
    private const SKIPPED_IMPORTS = ['spacing'];

    /**
     * Inspect a collection's entry blueprint and return a structured description of
     * the replicator blocks available for AI mapping.
     *
     * @return array{
     *     collection: string,
     *     replicator_field: string,
     *     sets: array<int, array{handle: string, display: string, instructions: string, fields: array<int, array<string, mixed>>}>
     * }
     */
    public function inspect(string $collectionHandle = 'pages', string $replicatorField = 'blocks'): array
    {
        $collection = Collection::find($collectionHandle);

        if (! $collection) {
            throw new RuntimeException("Collection [{$collectionHandle}] not found.");
        }

        $field = $collection->entryBlueprint()->field($replicatorField);

        if (! $field || $field->type() !== 'replicator') {
            throw new RuntimeException("Blueprint for [{$collectionHandle}] has no `{$replicatorField}` replicator field.");
        }

        return [
            'collection' => $collectionHandle,
            'replicator_field' => $replicatorField,
            'sets' => $this->summarizeSets($field->config()['sets'] ?? []),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function summarizeSets(array $groups): array
    {
        $sets = [];

        foreach ($groups as $groupHandle => $group) {
            if (in_array($groupHandle, self::SKIPPED_GROUPS, true)) {
                continue;
            }

            foreach (($group['sets'] ?? []) as $setHandle => $set) {
                if (in_array($setHandle, self::SKIPPED_SETS, true)) {
                    continue;
                }

                $sets[] = [
                    'handle' => $setHandle,
                    'display' => $set['display'] ?? $setHandle,
                    'instructions' => $set['instructions'] ?? '',
                    'fields' => $this->summarizeFields($set['fields'] ?? []),
                ];
            }
        }

        return $sets;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function summarizeFields(array $fields): array
    {
        $summary = [];

        foreach ($fields as $fieldDef) {
            if (isset($fieldDef['import'])) {
                if (in_array($fieldDef['import'], self::SKIPPED_IMPORTS, true)) {
                    continue;
                }
                $summary[] = $this->summarizeImport($fieldDef['import']);

                continue;
            }

            $handle = $fieldDef['handle'] ?? null;
            $config = $fieldDef['field'] ?? [];

            if (! $handle || in_array($handle, self::SKIPPED_FIELDS, true)) {
                continue;
            }

            $summary[] = $this->summarizeField($handle, $config);
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function summarizeField(string $handle, array $config): array
    {
        $type = $config['type'] ?? 'text';

        $entry = [
            'handle' => $handle,
            'type' => $this->normalizeType($type),
            'display' => $config['display'] ?? $handle,
        ];

        if (! empty($config['instructions'])) {
            $entry['instructions'] = $config['instructions'];
        }
        if (in_array('required', (array) ($config['validate'] ?? []), true)) {
            $entry['required'] = true;
        }

        return array_merge($entry, $this->typeSpecificDetails($type, $config));
    }

    /**
     * @return array<string, mixed>
     */
    private function summarizeImport(string $name): array
    {
        if ($name === 'callout') {
            return [
                'handle' => 'callout',
                'type' => 'link',
                'display' => 'Callout',
                'instructions' => 'A link. Provide an absolute or relative URL. Label is the visible link text. Set new_window=true to open in a new tab.',
                'shape' => [
                    'url' => 'string (URL)',
                    'label' => 'string (link text)',
                    'new_window' => 'boolean',
                ],
            ];
        }

        return [
            'handle' => $name,
            'type' => 'imported_fieldset',
            'display' => $name,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function typeSpecificDetails(string $type, array $config): array
    {
        return match ($type) {
            'bard' => [
                'format' => 'HTML',
                'allowed_inline_tags' => ['strong', 'em', 'a'],
                'allowed_block_tags' => $this->bardButtonsToTags($config['buttons'] ?? []),
            ],
            'assets' => [
                'accepts' => ((int) ($config['max_files'] ?? 1)) === 1
                    ? 'single image (asset reference)'
                    : 'array of asset references',
                'note' => 'Provide the import-id of an extracted document image (e.g. "import-img-1") to use that image. Omit if no image is appropriate.',
            ],
            'replicator' => [
                'nested_sets' => $this->summarizeNestedSets($config['sets'] ?? []),
            ],
            'select' => array_filter([
                'options' => array_keys($config['options'] ?? []),
                'default' => $config['default'] ?? null,
            ], fn ($v) => $v !== null && $v !== []),
            'form' => [
                'note' => 'The handle of an existing form. Skip this block type unless the doc references a specific form.',
            ],
            'video' => [
                'note' => 'A URL to a YouTube, Vimeo, or similar video.',
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function summarizeNestedSets(array $groups): array
    {
        $nested = [];

        foreach ($groups as $group) {
            foreach (($group['sets'] ?? []) as $handle => $set) {
                $nested[] = [
                    'handle' => $handle,
                    'display' => $set['display'] ?? $handle,
                    'fields' => $this->summarizeFields($set['fields'] ?? []),
                ];
            }
        }

        return $nested;
    }

    private function normalizeType(string $type): string
    {
        return match ($type) {
            'text' => 'string',
            'textarea' => 'string (multi-line)',
            'bard' => 'rich_text_html',
            'assets' => 'asset_reference',
            'toggle' => 'boolean',
            default => $type,
        };
    }

    /**
     * @param  array<int, string>  $buttons
     * @return array<int, string>
     */
    private function bardButtonsToTags(array $buttons): array
    {
        $map = [
            'h2' => 'h2',
            'h3' => 'h3',
            'h4' => 'h4',
            'bold' => 'strong',
            'italic' => 'em',
            'unorderedlist' => 'ul',
            'orderedlist' => 'ol',
            'quote' => 'blockquote',
            'anchor' => 'a',
            'image' => 'img',
            'table' => 'table',
        ];

        $tags = collect($buttons)
            ->map(fn (string $button) => $map[$button] ?? null)
            ->filter()
            ->prepend('p')
            ->unique()
            ->values()
            ->all();

        return $tags;
    }
}
