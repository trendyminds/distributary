# Distributary

A Statamic 6 addon that turns a Google Docs export into a draft page entry. Upload a
"Web Page" `.zip`, and Claude maps the document's HTML and images into your existing
replicator blocks. The import always lands as a draft so an editor can review before
publishing.

## Requirements

- PHP 8.4+
- Statamic 6
- A `pages` collection with a `blocks` replicator field
- An `uploads` asset container
- A configured [`laravel/ai`](https://github.com/laravel/ai) provider (Anthropic by default)

## Installation

```bash
composer require trendyminds/distributary
```

Then set your AI credentials in `.env` (see `laravel/ai`'s docs for the provider you're using):

```env
ANTHROPIC_API_KEY=...
```

## Usage

The addon registers a CP utility at **Utilities → AI Import** (the display name is
configurable — see below).

1. In Google Docs, choose **File → Download → Web Page (.html, zipped)**.
2. Upload the `.zip` in the utility.
3. Wait for the import to process (parse, image upload, AI mapping).
4. Review the proposed blocks on the preview screen, edit the title, and confirm.
5. The addon creates a draft entry in `pages` and drops you on its edit screen.

## Configuration

The defaults work out of the box. To customize, publish the config:

```bash
php artisan vendor:publish --tag=distributary-config
```

| Env | Default | Notes |
|---|---|---|
| `DISTRIBUTARY_DISPLAY_NAME` | `AI Import` | The name shown to content authors in the CP utility list, nav, and page titles. |
| `DISTRIBUTARY_PROVIDER` | `anthropic` | Any provider configured in `config/ai.php`. |
| `DISTRIBUTARY_MODEL` | `claude-opus-4-7` | Model identifier for that provider. |

## How it works

1. **Parse** — extracts HTML and images from the `.zip` to a temporary directory.
2. **Import images** — streams each image into the `uploads` asset container under `distributary/YYYY-MM/`.
3. **Inspect blueprint** — reads the `pages` collection's `blocks` replicator and summarizes each set for the model.
4. **Map** — sends the document HTML, blueprint summary, and image references to Claude, which returns a structured block list.
5. **Preview** — renders the proposed entry for review. Mapping results are cached for 30 days keyed by file hash, so re-uploads are instant.
6. **Confirm** — builds a draft entry from the approved mapping.
