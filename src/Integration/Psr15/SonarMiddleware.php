<?php

declare(strict_types=1);

namespace Hkyss\Sonar\Integration\Psr15;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Hkyss\Sonar\Headers;
use Hkyss\Sonar\Overlay;
use Hkyss\Sonar\Sonar;

final class SonarMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly StreamFactoryInterface $streams)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
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
