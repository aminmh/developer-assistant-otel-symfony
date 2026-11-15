<?php

declare(strict_types=1);

namespace Indexr\Otel\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('indexer_otel');
        $root = $treeBuilder->getRootNode();
        $root->children()
            ->booleanNode('enabled')->defaultTrue()->end()
            ->booleanNode('db')->defaultTrue()->end()
            ->booleanNode('cache')->defaultFalse()->info('Deprecated; has no effect.')->end()
            ->booleanNode('caller_attribution')->defaultTrue()->end()
            ->booleanNode('trust_traceparent')->defaultFalse()->end()
            ->booleanNode('hooks')->defaultTrue()->end()
            ->scalarNode('hooks_file')->defaultNull()->end()
            ->integerNode('hooks_refresh_seconds')->defaultValue(30)->min(0)->end()
            ->arrayNode('hook_namespaces')->scalarPrototype()->end()->defaultValue(['App'])->end()
            ->scalarNode('project_root')->defaultValue('%kernel.project_dir%')->end()
            ->arrayNode('application_namespaces')->scalarPrototype()->end()->defaultValue(['App'])->end()
            ->arrayNode('table_prefixes')->scalarPrototype()->end()->defaultValue([])->end()
            ->end();

        return $treeBuilder;
    }
}
