<?php

declare(strict_types=1);

namespace Hkyss\Sonar\Integration\Laravel;

use Closure;
use Hkyss\Sonar\Headers;
use Hkyss\Sonar\Overlay;
use Hkyss\Sonar\Sonar;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SonarMiddleware
{
    public function handle(mixed $request, Closure $next): mixed
    {
        $response = $next($request);

        if (!$response instanceof Response || !Sonar::visible()) {
            return $response;
        }

        $prefix = Sonar::config()->headerPrefix();
        $snapshot = Sonar::snapshot();

        if ($this->isHtml($response)) {
            $response->setContent(
                (new Overlay($prefix))->injectInto((string) $response->getContent(), $snapshot)
            );
        }

        foreach (Headers::fromSnapshot($snapshot, $prefix) as $name => $value) {
            $response->headers->set($name, $value);
        }

        $response->headers->set(
            'Access-Control-Expose-Headers',
            Headers::expose($response->headers->get('Access-Control-Expose-Headers'), Headers::names($prefix))
        );

        return $response;
    }

    protected function isHtml(Response $response): bool
    {
        return !$response instanceof StreamedResponse
            && Overlay::isHtml((string) $response->headers->get('Content-Type', ''));
    }
}
