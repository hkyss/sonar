<?php

declare(strict_types=1);

namespace Hkyss\Sonar\Integration\Psr15;

use Hkyss\Sonar\Headers;
use Hkyss\Sonar\Overlay;
use Hkyss\Sonar\Sonar;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SonarMiddleware implements MiddlewareInterface
{
    /**
     * @param  bool  $startsRequest  Reopens the collector on the way in, so the
     *                               figures stay per-request in a long-running
     *                               server. Turn it off when this middleware is
     *                               not the outermost one, or anything the
     *                               middleware in front of it did would be lost.
     */
    public function __construct(
        private readonly StreamFactoryInterface $streams,
        private readonly bool $startsRequest = true,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->startsRequest) {
            Sonar::start();
        }

        $response = $handler->handle($request);

        if (!Sonar::visible()) {
            return $response;
        }

        $prefix = Sonar::config()->headerPrefix();
        $snapshot = Sonar::snapshot();

        if (Overlay::isHtml($response->getHeaderLine('Content-Type'))) {
            $body = (string) $response->getBody();
            $html = (new Overlay($prefix))->injectInto($body, $snapshot);

            if ($html !== $body) {
                $response = $response
                    ->withBody($this->streams->createStream($html))
                    ->withoutHeader('Content-Length');
            }
        }

        foreach (Headers::fromSnapshot($snapshot, $prefix) as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withHeader(
            'Access-Control-Expose-Headers',
            Headers::expose($response->getHeaderLine('Access-Control-Expose-Headers'), Headers::names($prefix))
        );
    }
}
