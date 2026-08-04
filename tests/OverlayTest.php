<?php

declare(strict_types=1);

namespace Sonar\Tests;

use PHPUnit\Framework\TestCase;
use Sonar\Collector;
use Sonar\Overlay;

final class OverlayTest extends TestCase
{
    public function testInjectsBeforeTheClosingBodyTag(): void
    {
        $html = (new Overlay())->injectInto(
            '<html><body><div id="root">page</div></body></html>',
            (new Collector())->snapshot()
        );

        self::assertStringContainsString('id="sonar-data"', $html);
        self::assertStringEndsWith('</body></html>', $html);
        self::assertLessThan(strpos($html, '</body>'), strpos($html, 'sonar-data'));
    }

    public function testLeavesAResponseWithoutABodyTagAlone(): void
    {
        $xml = '<?xml version="1.0"?><urlset><url><loc>/</loc></url></urlset>';

        self::assertSame($xml, (new Overlay())->injectInto($xml, []));
    }

    public function testDoesNotInjectTwice(): void
    {
        $overlay = new Overlay();
        $snapshot = (new Collector())->snapshot();

        $once = $overlay->injectInto('<html><body>page</body></html>', $snapshot);
        $twice = $overlay->injectInto($once, $snapshot);

        self::assertSame($once, $twice);
    }

    public function testEscapesAQueryThatWouldCloseTheDataScript(): void
    {
        $collector = new Collector();
        $collector->record("select '</script><script>alert(1)</script>'", 1.0);

        self::assertStringNotContainsString('<script>alert(1)', (new Overlay())->html($collector->snapshot()));
    }

    public function testShipsTheInlineAssets(): void
    {
        $html = (new Overlay())->html((new Collector())->snapshot());

        self::assertStringContainsString('#sonar-console', $html);
        self::assertStringContainsString('window.__sonar', $html);
    }

    public function testRecognisesHtmlContentTypes(): void
    {
        self::assertTrue(Overlay::isHtml('text/html; charset=UTF-8'));
        self::assertTrue(Overlay::isHtml('application/xhtml+xml'));
        self::assertFalse(Overlay::isHtml('application/json'));
    }
}
