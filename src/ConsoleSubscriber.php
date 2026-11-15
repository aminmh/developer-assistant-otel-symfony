<?php

declare(strict_types=1);

namespace Indexr\Otel\Symfony;

use OpenTelemetry\API\Trace\SpanInterface;
use Indexr\Otel\Core\SpanRunner;
use Indexr\Otel\Core\SymbolIdNormalizer;
use Indexr\Otel\Core\ProfileExportQueue;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

final class ConsoleSubscriber implements EventSubscriberInterface
{
    /** @param list<string> $applicationNamespaces */
    public function __construct(
        private readonly RuntimeState $state,
        private readonly bool $enabled,
        private readonly array $applicationNamespaces = ['App'],
        private readonly string $projectRoot = '',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::COMMAND => 'onCommand', ConsoleEvents::TERMINATE => 'onTerminate'];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        try {
            if ($this->enabled) {
                $command = $event->getCommand();
                $name = $command?->getName();
                if (is_string($name)) {
                    $attributes = ['indexer.command.name' => $name];
                    if ($command !== null) {
                        try {
                            $attributes['indexer.symbol_id'] = (string) (new SymbolIdNormalizer())->normalize([
                                'kind' => 'method', 'class' => $command::class, 'method' => 'execute',
                            ]);
                        } catch (Throwable) {
                        }
                    }
                    $this->state->commandSpan()?->finish();
                    $this->state->setCommandSpan((new SpanRunner())->begin('cli.command', $attributes));
                    $this->state->startProfile($this->applicationNamespaces, $attributes['indexer.symbol_id'] ?? null);
                }
            }
        } catch (Throwable) {
        }
    }

    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        try {
            $this->state->finishProfile($this->state->commandSpan(), $event->getExitCode() === 0 ? 200 : 500, $this->projectRoot);
            $this->state->commandSpan()?->finish();
            $this->state->setCommandSpan(null);
            ProfileExportQueue::flushAfterResponse();
        } catch (Throwable) {
        }
    }

    public function reset(): void
    {
        try {
            $this->state->reset();
        } catch (Throwable) {
        }
    }
}
