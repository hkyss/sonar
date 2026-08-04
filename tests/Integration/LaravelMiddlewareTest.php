<?php

declare(strict_types=1);

namespace Sonar\Tests\Integration;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;
use Sonar\Config;
use Sonar\Integration\Laravel\SonarMiddleware;
use Sonar\Sonar;

final class LaravelMiddlewareTest extends TestCase
{
    private SonarMiddleware $middleware;

    protected function setUp(): void
    {
        Sonar::reset();
        Sonar::boot(Config::fromValue(true));
        Sonar::record('select 1', 4.0);
        Sonar::record('select 2', 6.0);

        $this->middleware = new SonarMiddleware();
    }

    protected function tearDown(): void
    {
        Sonar::reset();
    }

    public function testReportsTheRequestInHeaders(): void
    {
        $response = $this->middleware->handle(Request::create('/api/offers'), static fn () => new JsonResponse(['ok' => true]));

        self::assertSame('2', $response->headers->get('X-Sonar-Queries'));
        self::assertSame('10', $response->headers->get('X-Sonar-Db-Time'));
        self::assertStringContainsString('X-Sonar-Queries', (string) $response->headers->get('Access-Control-Expose-Headers'));
    }

    public function testInjectsTheOverlayIntoHtml(): void
    {
        $response = $this->middleware->handle(
            Request::create('/'),
            static fn () => new Response('<html><body>page</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8'])
        );

        self::assertStringContainsString('id="sonar-data"', $response->getContent());
    }

    public function testLeavesJsonBodiesAlone(): void
    {
        $response = $this->middleware->handle(Request::create('/api/offers'), static fn () => new JsonResponse(['ok' => true]));

        self::assertSame('{"ok":true}', $response->getContent());
    }

    public function testStaysSilentWhenNotVisible(): void
    {
        Sonar::reset();
        Sonar::boot(Config::off());

        $response = $this->middleware->handle(Request::create('/api/offers'), static fn () => new JsonResponse(['ok' => true]));

        self::assertFalse($response->headers->has('X-Sonar-Queries'));
    }
}
