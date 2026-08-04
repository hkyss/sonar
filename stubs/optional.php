<?php

/**
 * Symbols the optional integrations talk to. Neither Evolution CMS nor Octane
 * is a dev dependency, so static analysis needs to be told they exist.
 */

declare(strict_types=1);

namespace EvolutionCMS {
    class Core
    {
        public int $documentIdentifier = 0;

        public ?int $documentGenerated = null;

        public string $documentOutput = '';

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
