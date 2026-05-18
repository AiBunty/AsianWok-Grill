<?php

declare(strict_types=1);

namespace AWG\Controllers;

use AWG\Services\DiagnosticsService;

final class DiagnosticsController
{
    public function __construct(private readonly DiagnosticsService $diagnosticsService)
    {
    }

    public function serverConnections(): array
    {
        return $this->diagnosticsService->serverConnections();
    }
}
