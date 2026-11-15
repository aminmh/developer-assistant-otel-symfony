<?php

declare(strict_types=1);

namespace Indexr\Otel\Symfony\Messenger;

use Indexr\Otel\Core\SpanRunner;
use Indexr\Otel\Core\ProfileRuntime;
use Indexr\Otel\Core\ProfileExportQueue;
use Indexr\Otel\Core\SymbolIdNormalizer;
use Indexr\Otel\Core\TraceContext;
use OpenTelemetry\API\Trace\SpanKind;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Throwable;

/** Adds safe message type context to the active official Messenger span. */
final class IndexerMessengerMiddleware implements MiddlewareInterface
{
    /** @param list<string> $applicationNamespaces */
    public function __construct(
        private readonly array $applicationNamespaces = ['App'],
        private readonly string $projectRoot = '',
        private readonly bool $enabled = true,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (!$this->enabled) {
            return $stack->next()->handle($envelope, $stack);
        }
        $message = $envelope->getMessage();
        $parent = null;
        try {
            $traceStamp = $envelope->last(TraceContextStamp::class);
            if ($envelope->last(\Symfony\Component\Messenger\Stamp\ReceivedStamp::class) !== null) {
                // Consuming from a transport: continue the dispatcher's trace.
                $parent = $traceStamp instanceof TraceContextStamp ? TraceContext::extract($traceStamp->traceparent, $traceStamp->tracestate) : null;
            } elseif ($traceStamp === null) {
                // Dispatching: record the current context before any span of ours becomes active.
                $context = TraceContext::current();
                if (isset($context['traceparent'])) {
                    $envelope = $envelope->with(new TraceContextStamp($context['traceparent'], $context['tracestate'] ?? null));
                }
            }
        } catch (Throwable) {
        }
        $active = (new SpanRunner())->begin('messaging.process', ['messaging.message.class' => $message::class], SpanKind::KIND_CONSUMER, $parent);
        $profile = new ProfileRuntime();
        $profile->start($this->applicationNamespaces);
        $statusCode = 200;
        try {
            $result = $stack->next()->handle($envelope, $stack);
            foreach ($result->all(\Symfony\Component\Messenger\Stamp\HandledStamp::class) as $stamp) {
                $name = $stamp->getHandlerName();
                if (preg_match('/\A(.+)::([A-Za-z_][A-Za-z0-9_]*)\z/D', $name, $matches)) {
                    try {
                        $symbol = (string) (new SymbolIdNormalizer())->normalize(['kind' => 'method', 'class' => $matches[1], 'method' => $matches[2]]);
                        $active?->enrich(['indexer.symbol_id' => $symbol]);
                        break;
                    } catch (Throwable) {
                    }
                }
            }
            return $result;
        } catch (Throwable $failure) {
            $statusCode = 500;
            $profile->finish($active, $statusCode, $this->projectRoot);
            $active?->finish($failure);
            $active = null;
            throw $failure;
        } finally {
            if ($active !== null) {
                $profile->finish($active, $statusCode, $this->projectRoot);
            }
            $active?->finish();
            ProfileExportQueue::flushAfterResponse();
        }
    }
}
