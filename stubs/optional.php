<?php

/** Neither Evolution CMS nor Octane is a dev dependency, so static analysis is given the surface instead. */

declare(strict_types=1);

namespace EvolutionCMS {
    class Core
    {
        public int $documentIdentifier = 0;

        public ?int $documentGenerated = null;

        public string $documentOutput = '';

        /** @var array<string, mixed> */
        public array $documentObject = [];

        public function isLoggedIn(string $context = ''): bool
        {
            return false;
        }
    }
}

namespace Laravel\Octane\Events {
    class RequestReceived
    {
    }
}

namespace {
    function evo(): \EvolutionCMS\Core
    {
        return new \EvolutionCMS\Core();
    }
}
