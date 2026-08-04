<?php

declare(strict_types=1);

namespace Hkyss\Sonar;

/** Inline styles, data and script inserted before the closing body tag. */
final class Overlay
{
    private const ASSETS = __DIR__ . '/Assets';

    public function __construct(private readonly string $headerPrefix = 'X-Sonar-')
    {
    }

    /** @param array<string, mixed> $snapshot */
    public function injectInto(string $html, array $snapshot): string
    {
        $position = strripos($html, '</body>');

        if ($position === false || str_contains($html, 'id="sonar-data"')) {
            return $html;
        }

        return substr($html, 0, $position) . $this->html($snapshot) . substr($html, $position);
    }

    /** @param array<string, mixed> $snapshot */
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
