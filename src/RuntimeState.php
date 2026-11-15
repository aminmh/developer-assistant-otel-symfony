<?php

declare(strict_types=1);

namespace Indexr\Otel\Symfony;

use Indexr\Otel\Core\ActiveSpan;
use Indexr\Otel\Core\ProfileRuntime;
use Indexr\Otel\Core\ServerRequestSpan;

/** Mutable data scoped to the current request/command, cleared by kernel.reset. */
final class RuntimeState
{
    private ?string $routeId = null;
    private ServerRequestSpan $request;
    private ?ActiveSpan $commandSpan = null;
    private ?ProfileRuntime $profileRuntime = null;

    public function __construct()
    {
        $this->request = new ServerRequestSpan();
    }

    public function setRouteId(string $routeId): void
    {
        $this->routeId = $routeId;
    }

    public function routeId(): ?string
    {
        return $this->routeId;
    }

    public function request(): ServerRequestSpan
    {
        return $this->request;
    }

    public function reset(): void
    {
        $this->commandSpan?->finish();
        $this->commandSpan = null;
        $this->profileRuntime?->reset();
        $this->profileRuntime = null;
        $this->request->finish();
        $this->routeId = null;
    }

    public function setCommandSpan(?ActiveSpan $span): void
    {
        $this->commandSpan = $span;
    }

    /** @param list<string> $applicationNamespaces */
    public function startProfile(array $applicationNamespaces, ?string $rootSymbolId = null): void
    {
        $this->profileRuntime?->reset();
        $this->profileRuntime = new ProfileRuntime();
        $this->profileRuntime->start($applicationNamespaces, $rootSymbolId);
    }

    public function finishProfile(?ActiveSpan $span, int $statusCode, ?string $projectRoot = null): void
    {
        $this->profileRuntime?->finish($span, $statusCode, $projectRoot);
        $this->profileRuntime = null;
    }

    public function requestSpan(): ?ActiveSpan
    {
        return $this->request->activeSpan();
    }

    public function commandSpan(): ?ActiveSpan
    {
        return $this->commandSpan;
    }
}
