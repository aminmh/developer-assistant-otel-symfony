<?php

declare(strict_types=1);

namespace Indexr\Otel\Symfony;

use Indexr\Otel\Core\ExceptionContext;
use Indexr\Otel\Core\MethodHookManifestRefresher;
use Indexr\Otel\Core\MethodHookRegistry;
use Indexr\Otel\Core\ProfileExportQueue;
use Indexr\Otel\Core\SpanEnricher;
use Indexr\Otel\Core\SymbolIdNormalizer;
use Indexr\Otel\Core\TelemetryBootstrap;
use Indexr\Otel\Core\ServerRequestSpan;
use Indexr\Otel\Core\TraceContext;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

/**
 * Owns the main request's `http.server` span.
 *
 * It opens before routing and the security firewall (so their queries are children, not separate
 * traces), records route/controller once known, status at response time, ends its duration when the
 * response has been sent, and keeps its context until the last kernel.terminate listener.
 */
final class KernelSubscriber implements EventSubscriberInterface
{
    /** Runs before the router (32) and the firewall (8). */
    private const EARLY = 4096;
    private const LATE = -4096;

    /** @param list<string> $applicationNamespaces @param list<string> $tablePrefixes */
    public function __construct(
        private readonly array $applicationNamespaces,
        private readonly string $projectRoot,
        private readonly array $tablePrefixes,
        private readonly bool $dbEnabled,
        private readonly bool $enabled,
        private readonly bool $hooksEnabled,
        private readonly ?string $hooksFile,
        private readonly RouterInterface $router,
        private readonly RuntimeState $state,
        private readonly bool $trustTraceparent = false,
        private readonly array $hookNamespaces = ['App'],
        private readonly int $hooksRefreshSeconds = MethodHookManifestRefresher::DEFAULT_INTERVAL_SECONDS,
    ) {
        if ($this->enabled) {
            TelemetryBootstrap::register($this->applicationNamespaces, $this->projectRoot);
            MethodHookRegistry::registerFromFile($this->hooksFile, $this->hooksEnabled, $this->projectRoot);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.request' => ['onRequest', self::EARLY],
            'kernel.controller' => 'onController',
            'kernel.response' => ['onResponse', self::LATE],
            'kernel.exception' => 'onException',
            'kernel.terminate' => [['onResponseSent', self::EARLY], ['onTerminate', self::LATE]],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        try {
            if (!$this->enabled || !$event->isMainRequest()) {
                return;
            }
            $request = $event->getRequest();
            $parent = $this->trustTraceparent
                ? TraceContext::extract($request->headers->get('traceparent'), $request->headers->get('tracestate'))
                : null;
            $this->state->request()->begin(
                $request->getMethod(),
                $request->server->get('REQUEST_TIME_FLOAT'),
                $request->getScheme(),
                $parent,
            );
            $this->state->request()->tagBenchmark($request->headers->get(ServerRequestSpan::BENCHMARK_HEADER));
        } catch (Throwable) {
        }
    }

    public function onController(ControllerEvent $event): void
    {
        try {
            if (!$this->enabled || !$event->isMainRequest()) {
                return;
            }
            $request = $event->getRequest();
            $controller = $request->attributes->get('_controller');
            if (!$this->recordRoute($request, is_string($controller) ? $controller : null)) {
                return;
            }
            $controllerSymbol = null;
            if (is_string($controller)) {
                try {
                    $controllerSymbol = (string) (new SymbolIdNormalizer())->normalize(['kind' => 'controller', 'controller' => $controller]);
                } catch (Throwable) {
                }
            }
            $this->state->startProfile($this->applicationNamespaces, $controllerSymbol);
        } catch (Throwable) {
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        try {
            if ($this->enabled && $event->isMainRequest()) {
                // A request answered before any controller (security firewall, early listener) still has
                // its matched route; record it without a controller symbol, since no controller ran.
                if (!$this->state->request()->isRouted()) {
                    $this->recordRoute($event->getRequest(), null);
                }
                $this->state->request()->respond($event->getResponse()->getStatusCode());
            }
        } catch (Throwable) {
        }
    }

    /** Records the router-matched route on the server span; false when the request has no known route. */
    private function recordRoute(\Symfony\Component\HttpFoundation\Request $request, ?string $controller): bool
    {
        $routeName = $request->attributes->get('_route');
        $route = is_string($routeName) ? $this->router->getRouteCollection()->get($routeName) : null;
        if ($route === null) {
            return false;
        }
        $routeId = $this->state->request()->route($route->getPath(), array_values($route->getMethods()), $controller);
        if ($routeId !== null) {
            $this->state->setRouteId($routeId);
        }
        return true;
    }

    public function onException(ExceptionEvent $event): void
    {
        try {
            if (!$this->enabled) {
                return;
            }
            $throwable = $event->getThrowable();
            $attributes = (new ExceptionContext())->attributes($throwable, $this->applicationNamespaces);
            $span = Span::getCurrent();
            if ($span->isRecording()) {
                (new SpanEnricher())->enrich($span, $attributes);
            }
            if ($event->isMainRequest()) {
                $this->state->request()->enrich($attributes);
            }
            // Client errors (404, 403, 422, ...) are not server failures; the response status decides those.
            if (!$throwable instanceof HttpExceptionInterface || $throwable->getStatusCode() >= 500) {
                if ($span->isRecording()) {
                    $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR);
                }
                if ($event->isMainRequest()) {
                    $this->state->request()->fail($throwable);
                }
            }
        } catch (Throwable) {
        }
    }

    /** First kernel.terminate listener: the response is out, so the request duration and profile end here. */
    public function onResponseSent(TerminateEvent $event): void
    {
        try {
            if (!$this->enabled) {
                return;
            }
            $this->state->request()->markResponseSent();
            $this->state->finishProfile($this->state->requestSpan(), $event->getResponse()->getStatusCode(), $this->projectRoot);
        } catch (Throwable) {
        }
    }

    /** Last kernel.terminate listener: post-response work has run inside the request's trace. */
    public function onTerminate(TerminateEvent $event): void
    {
        $this->reset();
        ProfileExportQueue::flushAfterResponse();
        $this->refreshHookManifest();
    }

    /** Rewrites the hook manifest after a response when app classes changed, so new code is counted. */
    private function refreshHookManifest(): void
    {
        if (!$this->enabled || !$this->hooksEnabled || $this->hooksRefreshSeconds <= 0) {
            return;
        }
        $path = MethodHookRegistry::resolvePath($this->hooksFile, $this->projectRoot);
        if ($path === null) {
            return;
        }
        $router = $this->router;
        MethodHookManifestRefresher::refreshIfStale($path, $this->hookNamespaces, static function () use ($router): array {
            $classes = [];
            foreach ($router->getRouteCollection() as $route) {
                $controller = $route->getDefault('_controller');
                if (is_string($controller)) {
                    $classes[] = explode('::', $controller, 2)[0];
                }
            }
            return array_values(array_unique($classes));
        }, $this->hooksRefreshSeconds);
    }

    public function reset(): void
    {
        try {
            $this->state->reset();
        } catch (Throwable) {
        }
    }
}
