<?php

namespace Trendyminds\Distributary\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Parses a Google Docs "Web Page (.zip)" export — an HTML file accompanied by an
 * images/ folder — into a normalised ParsedDocument that the rest of the pipeline
 * (AssetImporter, AiBlockMapper) can consume.
 *
 * Strips Google's heavy CSS/class scaffolding, unwraps formatting spans, and
 * replaces <img src="images/..."> with <img data-import-id="..."> placeholders so
 * extracted image bytes can be uploaded to Statamic assets separately.
 */
class HtmlZipParser
{
    /** @var array<int, array{id: string, path: string, extension: string, alt: string}> */
    protected array $images = [];

    protected int $imageCounter = 0;

    protected ?string $documentTitle = null;

    /**
     * Tag names that survive the cleaning pass. Everything else is unwrapped (its
     * children are kept, the tag itself dropped) so the AI sees a tight semantic tree.
     */
    protected const KEEP_TAGS = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'p', 'br',
        'ul', 'ol', 'li',
        'strong', 'b', 'em', 'i',
        'a', 'img',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'blockquote',
    ];

    /**
     * Image dimensions (width × height in px) baked into the TrendyMinds Google Docs
     * letterhead template — the header logo and footer gradient bar. Any <img> matching
     * one of these (within {@see DIMENSION_TOLERANCE_PX}) is dropped during parsing so it
     * never reaches the asset importer or AI mapper.
     *
     * @var array<int, array{width: float, height: float}>
     */
    protected const LETTERHEAD_IMAGE_DIMENSIONS = [
        ['width' => 222.5, 'height' => 50.69], // header logo
        ['width' => 768.0, 'height' => 22.13], // footer gradient bar
    ];

    protected const DIMENSION_TOLERANCE_PX = 2.0;

    public function parse(string $zipPath): ParsedDocument
    {
        $this->images = [];
        $this->imageCounter = 0;
        $this->documentTitle = null;

        $tempDir = sys_get_temp_dir().'/distributary-'.Str::lower(Str::random(12));

        if (! mkdir($tempDir, 0700, true) && ! is_dir($tempDir)) {
            throw new RuntimeException("Could not create temp directory: {$tempDir}");
        }

        try {
            $this->extractZip($zipPath, $tempDir);

            $htmlPath = $this->findHtmlFile($tempDir);
            $rawHtml = (string) file_get_contents($htmlPath);

            $dom = $this->loadHtml($rawHtml);
            $body = $dom->getElementsByTagName('body')->item(0);

            if (! $body instanceof DOMElement) {
                throw new RuntimeException('Could not find <body> in the document HTML.');
            }

            $this->collectImages($dom, dirname($htmlPath));
            $this->cleanNode($body);

            $innerHtml = $this->innerHtml($body);

            return new ParsedDocument(
                html: $this->cleanHtmlOutput($innerHtml),
                images: $this->images,
                title: $this->documentTitle,
                tempDir: $tempDir,
            );
        } catch (Throwable $e) {
            File::deleteDirectory($tempDir);
            throw $e;
        }
    }

    protected function extractZip(string $zipPath, string $destination): void
    {
        $zip = new ZipArchive;
        $opened = $zip->open($zipPath);

        if ($opened !== true) {
            throw new RuntimeException("Could not open zip archive (error code {$opened}).");
        }

        if (! $zip->extractTo($destination)) {
            $zip->close();
            throw new RuntimeException('Could not extract zip archive.');
        }

        $zip->close();
    }

    protected function findHtmlFile(string $directory): string
    {
        $files = glob($directory.'/*.html') ?: [];
        if (empty($files)) {
            // Try one level deeper in case Google nested under a folder.
            $nested = glob($directory.'/*/*.html') ?: [];
            $files = $nested;
        }

        if (empty($files)) {
            throw new RuntimeException('No .html file found in the uploaded zip.');
        }

        return $files[0];
    }

    protected function loadHtml(string $html): DOMDocument
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // Prepend a UTF-8 meta so DOMDocument doesn't mangle non-ASCII characters.
        $prefixed = '<?xml encoding="UTF-8">'.$html;
        $dom->loadHTML($prefixed, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    /**
     * Walk every <img> and turn its src into an import-id placeholder, recording the
     * on-disk path so AssetImporter can stream the bytes into Statamic later.
     */
    protected function collectImages(DOMDocument $dom, string $baseDir): void
    {
        $xpath = new DOMXPath($dom);
        /** @var DOMElement[] $imgs */
        $imgs = iterator_to_array($xpath->query('//img'));

        foreach ($imgs as $img) {
            $src = $img->getAttribute('src');
            $alt = $img->getAttribute('alt');

            if ($this->isLetterheadImage($img)) {
                $img->parentNode?->removeChild($img);

                continue;
            }

            $resolved = $this->resolveImagePath($baseDir, $src);
            if (! $resolved || ! is_file($resolved)) {
                // Image file missing from zip — drop the <img> rather than emit a broken reference.
                $img->parentNode?->removeChild($img);

                continue;
            }

            $this->imageCounter++;
            $id = 'import-img-'.$this->imageCounter;

            $this->images[] = [
                'id' => $id,
                'path' => $resolved,
                'extension' => strtolower(pathinfo($resolved, PATHINFO_EXTENSION)) ?: 'png',
                'alt' => $alt,
            ];

            // Re-create the <img> with only the attributes we care about.
            $clean = $dom->createElement('img');
            $clean->setAttribute('data-import-id', $id);
            if ($alt !== '') {
                $clean->setAttribute('alt', $alt);
            }
            $img->parentNode?->replaceChild($clean, $img);
        }
    }

    /**
     * Detect images baked into the Google Docs letterhead template (header logo,
     * footer gradient) by matching against known pixel dimensions pulled from the
     * inline style attribute Google Docs emits on every <img>.
     */
    protected function isLetterheadImage(DOMElement $img): bool
    {
        $style = $img->getAttribute('style');
        if ($style === '') {
            return false;
        }

        if (! preg_match('/width:\s*([\d.]+)px/i', $style, $widthMatch)) {
            return false;
        }
        if (! preg_match('/height:\s*([\d.]+)px/i', $style, $heightMatch)) {
            return false;
        }

        $width = (float) $widthMatch[1];
        $height = (float) $heightMatch[1];

        foreach (self::LETTERHEAD_IMAGE_DIMENSIONS as $dims) {
            if (
                abs($width - $dims['width']) <= self::DIMENSION_TOLERANCE_PX
                && abs($height - $dims['height']) <= self::DIMENSION_TOLERANCE_PX
            ) {
                return true;
            }
        }

        return false;
    }

    protected function resolveImagePath(string $baseDir, string $src): ?string
    {
        if ($src === '') {
            return null;
        }

        // Reject absolute URLs (e.g. external images) — we only handle bundled ones.
        if (preg_match('#^(https?:|data:)#i', $src)) {
            return null;
        }

        $candidate = $baseDir.'/'.ltrim($src, '/');
        $real = realpath($candidate);

        // Guard against zip-traversal style paths escaping the temp dir.
        if (! $real || ! str_starts_with($real, realpath($baseDir).DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    /**
     * Walk the DOM depth-first, stripping attributes, unwrapping non-semantic tags,
     * and capturing the first H1 as the document title.
     */
    protected function cleanNode(DOMNode $node): void
    {
        // Snapshot children before mutating — modifying childNodes during iteration is unsafe.
        $children = iterator_to_array($node->childNodes);

        foreach ($children as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (! in_array($tag, self::KEEP_TAGS, true)) {
                $this->unwrap($child);

                continue;
            }

            // Recurse first so nested unwraps happen before we evaluate the current node.
            $this->cleanNode($child);

            $this->stripAttributes($child);

            if ($tag === 'h1' && $this->documentTitle === null) {
                $text = trim($child->textContent ?? '');
                if ($text !== '') {
                    $this->documentTitle = $text;
                }
            }
        }
    }

    /**
     * Replace an element with its children. Used to drop wrapper tags like <span>
     * while keeping their text content in place.
     */
    protected function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if (! $parent) {
            return;
        }

        // Recurse into the element so deeper non-semantic wrappers are unwrapped too.
        $this->cleanNode($element);

        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    protected function stripAttributes(DOMElement $element): void
    {
        $keep = match (strtolower($element->tagName)) {
            'a' => ['href'],
            'img' => ['data-import-id', 'alt'],
            default => [],
        };

        $names = [];
        foreach ($element->attributes as $attr) {
            $names[] = $attr->name;
        }

        foreach ($names as $name) {
            if (! in_array(strtolower($name), $keep, true)) {
                $element->removeAttribute($name);
            }
        }
    }

    /**
     * Serialise children of a node as HTML (the node itself is omitted).
     */
    protected function innerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }

        return $html;
    }

    protected function cleanHtmlOutput(string $html): string
    {
        // Drop paragraphs that are empty or contain only whitespace / non-breaking spaces.
        // Delimiter is "~" because &#160; contains "#".
        $html = preg_replace('~<p>(?:\s|&nbsp;|&#160;|<br\s*/?>)*</p>~u', '', $html) ?? $html;
        // Collapse runs of whitespace and tighten inter-tag whitespace.
        $html = preg_replace('/[ \t\r\n]+/u', ' ', $html) ?? $html;
        $html = preg_replace('/>\s+</', '><', $html) ?? $html;

        return trim($html);
    }
}
