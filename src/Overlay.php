<?php

declare(strict_types=1);

namespace hkyss\Sonar;

/**
 * Inline styles, data and script inserted before the closing body tag.
 *
 * @phpstan-import-type SonarSnapshot from Collector
 */
final class Overlay
{
    private const ASSETS = __DIR__ . '/Assets';

    public function __construct(private readonly string $headerPrefix = 'X-Sonar-')
    {
    }

    /**
     * For one response among many, which may be a fragment of a page rather than
     * a page: output with no closing body tag is left alone, because an overlay
     * inside a partial swapped into a live document is fifteen kilobytes landing
     * in a corner of it.
     *
     * @param SonarSnapshot|array{} $snapshot
     */
    public function injectInto(string $html, array $snapshot): string
    {
        return $this->insert($html, $snapshot, false);
    }

    /**
     * For output that is the whole page. A template is free to render a document
     * with no body tag at all — Evolution CMS ships one — and there the overlay
     * goes on the end rather than nowhere. Only a caller that knows the string is
     * a complete page can tell that apart from a fragment, so it says so by
     * calling this instead.
     *
     * @param SonarSnapshot|array{} $snapshot
     */
    public function injectIntoPage(string $html, array $snapshot): string
    {
        return $this->insert($html, $snapshot, true);
    }

    /** @param SonarSnapshot|array{} $snapshot */
    private function insert(string $html, array $snapshot, bool $whole): string
    {
        if (str_contains($html, 'id="sonar-data"')) {
            return $html;
        }

        $position = strripos($html, '</body>');

        if ($position === false) {
            return $whole ? $html . $this->html($snapshot) : $html;
        }

        return substr($html, 0, $position) . $this->html($snapshot) . substr($html, $position);
    }

    /** @param SonarSnapshot|array{} $snapshot */
    public function html(array $snapshot): string
    {
        $payload = json_encode(
            ['server' => $snapshot, 'headerPrefix' => $this->headerPrefix],
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return "\n<style id=\"sonar-style\">" . $this->asset('overlay.css') . "</style>\n"
            . '<script type="application/json" id="sonar-data">' . ($payload === false ? '{}' : $payload) . "</script>\n"
            . '<script id="sonar-script">' . $this->asset('overlay.js') . "</script>\n";
    }

    public static function isHtml(string $contentType): bool
    {
        return stripos($contentType, 'text/html') === 0 || stripos($contentType, 'application/xhtml+xml') === 0;
    }

    private function asset(string $name): string
    {
        $path = self::ASSETS . '/' . $name;

        return is_readable($path) ? (string) file_get_contents($path) : '';
    }
}
