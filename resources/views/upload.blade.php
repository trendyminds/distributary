@extends('statamic::layout')
@section('title', config('distributary.display_name'))

@section('content')
	<ui-header title="{{ config('distributary.display_name') }}" icon="wand" />

	<ui-text as="p" class="-mt-2 mb-6 max-w-3xl text-gray-600 dark:text-gray-400">
		Upload a Google Docs "Web Page" export (.zip). Claude will read the HTML and bundled
		images, then propose a draft page mapped into your existing blocks. You'll review
		the result before anything is saved.
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

	<ui-card>
		<form method="POST" action="{{ cp_route('utilities.distributary.upload') }}" enctype="multipart/form-data" class="flex flex-col gap-6">
			@csrf

			<ui-field>
				<ui-label for="document" required>Web Page (.zip)</ui-label>
				<label for="document" class="inline-flex items-center gap-3 cursor-pointer">
					<input
						type="file"
						name="document"
						id="document"
						accept=".zip,application/zip"
						required
						class="sr-only"
						@change="$el.parentNode.querySelector('[data-distributary-filename]').textContent = $event.target.files[0]?.name || 'No file selected'">
					<ui-button as="span" variant="default" icon="upload-cloud" text="Choose file" />
					<span data-distributary-filename class="text-sm text-gray-600 dark:text-gray-400">No file selected</span>
				</label>
				<ui-description>
					In Google Docs, choose
					<em>File &rarr; Download &rarr; Web Page (.html, zipped)</em>.
				</ui-description>
			</ui-field>

			<ui-text as="div" size="sm" class="rounded-lg bg-gray-50 dark:bg-gray-900 px-4 py-3 text-gray-600 dark:text-gray-400">
				Target collection: <code class="text-gray-900 dark:text-gray-200">pages</code>.
				Imports always create a <strong>draft</strong> entry for your review.
			</ui-text>

			<div class="flex items-center gap-3">
				<ui-button type="submit" variant="primary" text="Upload &amp; preview" icon="upload-cloud" />
			</div>
		</form>
	</ui-card>
@endsection
