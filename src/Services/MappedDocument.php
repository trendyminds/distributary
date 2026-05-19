<?php

namespace Trendyminds\Distributary\Services;

class MappedDocument
{
    /**
     * @param  array<int, array{type: string, fields: array<string, mixed>}>  $blocks
     * @param  array<string, mixed>  $meta  Free-form metadata (model, usage, etc.)
     */
    public function __construct(
        public readonly string $title,
        public readonly array $blocks,
        public readonly array $meta = [],
    ) {}
}
