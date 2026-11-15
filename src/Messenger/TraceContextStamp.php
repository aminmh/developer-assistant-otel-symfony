<?php

declare(strict_types=1);

namespace Indexr\Otel\Symfony\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/** W3C trace context of the span that dispatched a message, restored as the parent when it is consumed. */
final class TraceContextStamp implements StampInterface
{
    public function __construct(
        public readonly string $traceparent,
        public readonly ?string $tracestate = null,
    ) {
    }
}
