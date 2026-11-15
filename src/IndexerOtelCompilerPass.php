<?php

declare(strict_types=1);

namespace Indexr\Otel\Symfony;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Captures compile-time registrations so export does not need a database or network. */
final class IndexerOtelCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $services = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if (!$definition->isAbstract() && !$definition->isSynthetic() && !str_starts_with((string) $id, '.')) {
                $services[] = ['id' => (string) $id, 'class' => $definition->getClass()];
            }
        }
        sort($services);

        $queues = [];
        foreach ($container->findTaggedServiceIds('messenger.message_handler') as $id => $tags) {
            foreach ($tags as $tag) {
                $definition = $container->getDefinition($id);
                $class = $definition->getClass();
                $handler = null;
                $messageClass = $tag['handles'] ?? null;
                if (is_string($class) && class_exists($class)) {
                    try {
                        $reflection = new \ReflectionClass($class);
                        $handlerMethod = $reflection->hasMethod('__invoke') ? $reflection->getMethod('__invoke') : ($reflection->hasMethod('handle') ? $reflection->getMethod('handle') : null);
                        if ($handlerMethod !== null) {
                            $handler = $class . '::' . $handlerMethod->getName();
                            $type = $handlerMethod->getParameters()[0]->getType() ?? null;
                            if ($messageClass === null && $type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                                $messageClass = $type->getName();
                            }
                        }
                    } catch (\Throwable) {
                    }
                }
                $queues[] = ['service' => (string) $id, 'handler' => $handler, 'bus' => $tag['bus'] ?? null, 'handles' => $messageClass];
            }
        }

        $schedules = [];
        foreach ($container->findTaggedServiceIds('scheduler.schedule_provider') as $id => $tags) {
            $schedules[] = ['service' => (string) $id];
        }

        $container->setParameter('indexr_otel.export.services', $services);
        $container->setParameter('indexr_otel.export.queue_bindings', $queues);
        $container->setParameter('indexr_otel.export.scheduled_tasks', $schedules);

    }
}
