@extends('statamic::layout')
@section('title', config('distributary.display_name').' — Preview')

@section('content')
	<ui-header title="{{ config('distributary.display_name') }} — Preview" icon="wand">
		<template #actions>
			<ui-button href="{{ cp_route('utilities.distributary') }}" variant="default" text="Start over" icon="arrow-left" />
		</template>
	</ui-header>

	<ui-text as="p" class="-mt-2 mb-6 max-w-3xl text-gray-600 dark:text-gray-400">
		Proposed page structure from <strong>{{ $sourceFilename }}</strong>.
		Review below, then create the draft entry — nothing is saved until you confirm.
	</ui-text>

	@if ($errors->any())
		<div class="mb-6">
			<ui-alert variant="error" heading="Something went wrong">
				@foreach ($errors->all() as $error)
					<p>{{ $error }}</p>
				@endforeach
			</ui-alert>
		</div>
	@endif

	<form method="POST" action="{{ cp_route('utilities.distributary.confirm', ['importId' => $importId]) }}" class="flex flex-col gap-6">
		@csrf

		<ui-card>
			<ui-field
				label="Page title"
				instructions="Pre-filled from the document. Edit before creating the draft if you'd like."
				:required="true"
			>
				<ui-input
					name="title"
					model-value="{{ $title }}"
					placeholder="Page title"
					:required="true"
				/>
			</ui-field>
			<div class="mt-4 flex flex-wrap items-center gap-2">
				<ui-badge color="blue" text="Collection: {{ $collection }}" />
				<ui-badge color="default" text="{{ count($blocks) }} block(s)" />
				@if (! empty($meta['model']))
					<ui-badge color="purple" text="{{ $meta['provider'] ?? 'ai' }} · {{ $meta['model'] }}" />
				@endif
				@if ($usedCache)
					<ui-badge color="green" text="Cached" />
				@endif
			</div>
		</ui-card>

		<ui-card>
			<ui-subheading text="Proposed blocks" />
			<div class="mt-3">
				<ui-table>
					<ui-table-columns>
						<ui-table-column class="w-12">#</ui-table-column>
						<ui-table-column class="w-48">Block</ui-table-column>
						<ui-table-column>Preview</ui-table-column>
					</ui-table-columns>
					<ui-table-rows>
						@foreach ($blocks as $i => $block)
							@php
								$fields = $block['fields'] ?? [];
								$primary = $fields['heading'] ?? $fields['title'] ?? '';
								$secondaryRaw = $fields['subheading']
									?? $fields['body']
									?? $fields['quote']
									?? $fields['short_description']
									?? '';
								$secondary = is_string($secondaryRaw) ? trim(strip_tags($secondaryRaw)) : '';
								$childCount = null;
								foreach (['callouts', 'accordions', 'ctas'] as $childKey) {
									if (isset($fields[$childKey]) && is_array($fields[$childKey])) {
										$childCount = [
											'count' => count($fields[$childKey]),
											'key' => $childKey,
										];
										break;
									}
								}
							@endphp
							<ui-table-row>
								<ui-table-cell>
									<span class="text-gray-500">{{ $i + 1 }}</span>
								</ui-table-cell>
								<ui-table-cell>
									<ui-badge color="default" text="{{ $block['type'] }}" />
								</ui-table-cell>
								<ui-table-cell>
									@if ($primary)
										<div class="font-medium text-gray-900 dark:text-gray-200">{{ $primary }}</div>
									@endif
									@if ($secondary)
										<div class="text-gray-600 dark:text-gray-400 text-sm mt-1">
											{{ \Illuminate\Support\Str::limit($secondary, 180) }}
										</div>
									@endif
									@if ($childCount)
										<div class="text-gray-500 text-xs mt-1">
											{{ $childCount['count'] }} {{ \Illuminate\Support\Str::singular($childCount['key']) }}{{ $childCount['count'] === 1 ? '' : 's' }}
										</div>
									@endif
									@if (! $primary && ! $secondary && ! $childCount)
										<span class="text-gray-500 text-sm italic">(no preview)</span>
									@endif
								</ui-table-cell>
							</ui-table-row>
						@endforeach
					</ui-table-rows>
				</ui-table>
			</div>
		</ui-card>

		@if (! empty($imageMap))
			<ui-card>
				<ui-subheading text="Imported images ({{ count($imageMap) }})" />
				<div class="mt-3 flex flex-wrap gap-3">
					@foreach ($imageMap as $imageImportId => $imageInfo)
						<div class="text-xs">
							<img
								src="{{ $imageInfo['url'] }}"
								alt="{{ $imageInfo['alt'] }}"
								height="80"
								class="rounded-md ring-1 ring-gray-200 dark:ring-gray-700"
								style="height: 5rem; width: auto;"
							>
							<div class="mt-1 font-mono text-gray-500">{{ $imageImportId }}</div>
						</div>
					@endforeach
				</div>
			</ui-card>
		@endif

		<div class="flex items-center gap-3">
			<ui-button type="submit" variant="primary" text="Create draft entry" icon="checkmark" />
			<ui-button href="{{ cp_route('utilities.distributary') }}" variant="default" text="Cancel" />
		</div>
	</form>
@endsection
