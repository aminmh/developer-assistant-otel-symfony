<?php

declare(strict_types=1);

namespace Indexr\Otel\Symfony\DependencyInjection;

use Indexr\Otel\Symfony\ConsoleSubscriber;
use Indexr\Otel\Symfony\Doctrine\IndexerDbalMiddleware;
use Indexr\Otel\Symfony\IndexerExportCommand;
use Indexr\Otel\Symfony\KernelSubscriber;
use Indexr\Otel\Symfony\RuntimeState;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class IndexerOtelExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        foreach ([
            'enabled' => 'INDEXER_OTEL_ENABLED',
            'db' => 'INDEXER_OTEL_DB',
            'cache' => 'INDEXER_OTEL_CACHE',
            'caller_attribution' => 'INDEXER_OTEL_CALLER_ATTRIBUTION',
            'trust_traceparent' => 'INDEXER_OTEL_TRUST_TRACEPARENT',
        ] as $key => $environment) {
            $value = $_SERVER[$environment] ?? $_ENV[$environment] ?? getenv($environment);
            if (is_string($value) && $value !== '') {
                $config[$key] = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $config[$key];
            }
        }
        $hooksEnabled = $_SERVER['INDEXER_OTEL_HOOKS'] ?? $_ENV['INDEXER_OTEL_HOOKS'] ?? getenv('INDEXER_OTEL_HOOKS');
        if (is_string($hooksEnabled) && $hooksEnabled !== '') {
            $config['hooks'] = filter_var($hooksEnabled, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $config['hooks'];
        }
        $hooksFile = $_SERVER['INDEXER_OTEL_HOOKS_FILE'] ?? $_ENV['INDEXER_OTEL_HOOKS_FILE'] ?? getenv('INDEXER_OTEL_HOOKS_FILE');
        if (is_string($hooksFile) && $hooksFile !== '') {
            $config['hooks_file'] = $hooksFile;
        }
        $hookNamespaces = $_SERVER['INDEXER_OTEL_HOOK_NAMESPACES'] ?? $_ENV['INDEXER_OTEL_HOOK_NAMESPACES'] ?? getenv('INDEXER_OTEL_HOOK_NAMESPACES');
        if (is_string($hookNamespaces) && $hookNamespaces !== '') {
            $config['hook_namespaces'] = array_values(array_filter(array_map('trim', explode(',', $hookNamespaces))));
        }
        $hooksRefresh = $_SERVER['INDEXER_OTEL_HOOKS_REFRESH'] ?? $_ENV['INDEXER_OTEL_HOOKS_REFRESH'] ?? getenv('INDEXER_OTEL_HOOKS_REFRESH');
        if (is_string($hooksRefresh) && preg_match('/\\A\\d+\\z/', trim($hooksRefresh))) {
            $config['hooks_refresh_seconds'] = (int) trim($hooksRefresh);
        }
        $projectRoot = $_SERVER['INDEXER_PROJECT_ROOT'] ?? $_ENV['INDEXER_PROJECT_ROOT'] ?? getenv('INDEXER_PROJECT_ROOT');
        if (is_string($projectRoot) && $projectRoot !== '') {
            $config['project_root'] = $projectRoot;
        }
        foreach ($config as $key => $value) {
            $container->setParameter('indexr_otel.' . $key, $value);
        }

        // Registered here, not in a compiler pass: DoctrineBundle's MiddlewaresPass runs in the same pass
        // phase and, with Doctrine listed first in bundles.php, would never see a tag added later.
        if ($config['enabled'] && $config['db'] && interface_exists(\Doctrine\DBAL\Driver\Middleware::class)) {
            $container->register(IndexerDbalMiddleware::class, IndexerDbalMiddleware::class)
                ->setArguments(['default', '%indexr_otel.project_root%', '%indexr_otel.application_namespaces%', '%indexr_otel.table_prefixes%'])
                ->addTag('doctrine.middleware');
        }

        $container->setDefinition(RuntimeState::class, new Definition(RuntimeState::class))->setPublic(true);
        $kernel = new Definition(KernelSubscriber::class);
        $kernel->addArgument('%indexr_otel.application_namespaces%');
        $kernel->addArgument('%indexr_otel.project_root%');
        $kernel->addArgument('%indexr_otel.table_prefixes%');
        $kernel->addArgument('%indexr_otel.db%');
        $kernel->addArgument('%indexr_otel.enabled%');
        $kernel->addArgument('%indexr_otel.hooks%');
        $kernel->addArgument('%indexr_otel.hooks_file%');
        $kernel->addArgument(new \Symfony\Component\DependencyInjection\Reference('router'));
        $kernel->addArgument(new \Symfony\Component\DependencyInjection\Reference(RuntimeState::class));
        $kernel->addArgument('%indexr_otel.trust_traceparent%');
        $kernel->addArgument('%indexr_otel.hook_namespaces%');
        $kernel->addArgument('%indexr_otel.hooks_refresh_seconds%');
        $kernel->addTag('kernel.event_subscriber');
        $kernel->addTag('kernel.reset', ['method' => 'reset']);
        $container->setDefinition(KernelSubscriber::class, $kernel);

        $console = new Definition(ConsoleSubscriber::class, [new \Symfony\Component\DependencyInjection\Reference(RuntimeState::class), '%indexr_otel.enabled%', '%indexr_otel.application_namespaces%', '%indexr_otel.project_root%']);
        $console->addTag('kernel.event_subscriber');
        $console->addTag('kernel.reset', ['method' => 'reset']);
        $container->setDefinition(ConsoleSubscriber::class, $console);

        $export = new Definition(IndexerExportCommand::class, [
            new \Symfony\Component\DependencyInjection\Reference('router'),
            new \Symfony\Component\DependencyInjection\Reference('event_dispatcher'),
            '%indexr_otel.export.services%',
            '%indexr_otel.export.queue_bindings%',
            '%indexr_otel.export.scheduled_tasks%',
            '%indexr_otel.hook_namespaces%',
            '%indexr_otel.hooks_file%',
            '%indexr_otel.project_root%',
        ]);
        $export->addTag('console.command', ['command' => 'indexer:export']);
        $container->setDefinition(IndexerExportCommand::class, $export);

        if (interface_exists(\Symfony\Component\Messenger\Middleware\MiddlewareInterface::class)) {
            $messengerClass = \Indexr\Otel\Symfony\Messenger\IndexerMessengerMiddleware::class;
            $messenger = new Definition($messengerClass, ['%indexr_otel.application_namespaces%', '%indexr_otel.project_root%', '%indexr_otel.enabled%']);
            $messenger->addTag('messenger.middleware', ['priority' => 100]);
            $container->setDefinition($messengerClass, $messenger);
        }

    }
}
