<?php

declare(strict_types=1);

namespace hkyss\Sonar;

/**
 * @phpstan-import-type SonarSnapshot from Collector
 */
final class Overlay
{
    private const ASSETS = __DIR__ . '/Assets';

    public function __construct(private readonly string $headerPrefix = 'X-Sonar-')
    {
    }

    /**
     * Output with no closing body tag is left alone, because it may be a fragment swapped into a
     * live document rather than a page.
     *
     * @param SonarSnapshot|array{} $snapshot
     */
    public function injectInto(string $html, array $snapshot): string
    {
        return $this->insert($html, $snapshot, false);
    }

    /**
     * A template is free to render a document with no body tag at all — Evolution CMS ships one —
     * and only a caller that knows the string is a whole page can tell that from a fragment.
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
