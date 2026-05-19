@extends('statamic::layout')
@section('title', config('distributary.display_name').' — Processing')

@section('content')
	<ui-header title="{{ config('distributary.display_name') }}" icon="wand" />

	<div
		x-data="distributaryProcessing({
			statusUrl: @js($statusUrl),
			cancelUrl: @js($cancelUrl),
			initial: @js($initialStatus),
		})"
		x-init="poll()"
		class="max-w-3xl flex flex-col gap-6"
	>
		<ui-card>
			<div class="flex items-start gap-4">
				<ui-icon
					x-show="state !== 'failed'"
					name="loading"
					class="size-6 shrink-0 mt-1 text-blue-500"
					aria-hidden="true"
				/>
				<ui-icon
					x-show="state === 'failed'"
					name="warning-diamond"
					class="size-6 shrink-0 mt-1 text-red-600"
					aria-hidden="true"
				/>

				<div class="flex-1">
					<ui-subheading
						x-text="state === 'failed' ? 'Import failed' : 'Working on your import'"
						text="Working on your import"
					/>
					<ui-text
						as="p"
						class="mt-1 text-gray-600 dark:text-gray-400"
						x-text="state === 'failed' ? (error || 'Something went wrong.') : (message || 'Queued for processing…')"
					>
						{{ $initialStatus['message'] ?? 'Queued for processing…' }}
					</ui-text>

					<ui-text as="p" size="sm" class="mt-3 text-gray-500 dark:text-gray-500" x-show="state !== 'failed'">
						You can safely leave this page open — we'll redirect you to the preview as soon as it's ready.
					</ui-text>
				</div>
			</div>
		</ui-card>

		<div class="flex items-center gap-3">
			<ui-button
				x-show="state === 'failed'"
				:href="cancelUrl"
				href="{{ $cancelUrl }}"
				variant="primary"
				text="Start over"
				icon="upload-cloud"
			/>
			<ui-button
				x-show="state !== 'failed'"
				:href="cancelUrl"
				href="{{ $cancelUrl }}"
				variant="default"
				text="Cancel"
			/>
		</div>
	</div>

	<script>
		function distributaryProcessing({ statusUrl, cancelUrl, initial }) {
			return {
				statusUrl,
				cancelUrl,
				state: initial.state,
				message: initial.message,
				error: initial.error,
				previewUrl: initial.preview_url,

				async poll() {
					// If the server has already finished by the time the page renders, bounce immediately.
					if (this.state === 'complete' && this.previewUrl) {
						window.location.href = this.previewUrl
						return
					}

					while (this.state !== 'complete' && this.state !== 'failed') {
						await new Promise(r => setTimeout(r, 2000))
						try {
							const res = await fetch(this.statusUrl, {
								headers: { Accept: 'application/json' },
								credentials: 'same-origin',
							})
							const data = await res.json()
							this.state = data.state
							this.message = data.message
							this.error = data.error
							this.previewUrl = data.preview_url
						} catch (e) {
							// Network hiccup — keep polling, the next tick will retry.
						}
					}

					if (this.state === 'complete' && this.previewUrl) {
						window.location.href = this.previewUrl
					}
				},
			}
		}
	</script>
@endsection
