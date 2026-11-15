<?php

declare(strict_types=1);

namespace Indexr\Otel\Symfony;

use Indexr\Otel\Core\IntrospectionDocument;
use Indexr\Otel\Core\MethodHookManifest;
use Indexr\Otel\Core\MethodHookRegistry;
use Indexr\Otel\Core\SymbolIdNormalizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

#[AsCommand(name: 'indexer:export', description: 'Export application structure for Indexr')]
final class IndexerExportCommand extends Command
{
    /** @param list<array<string, mixed>> $services @param list<array<string, mixed>> $queues @param list<array<string, mixed>> $scheduled */
    public function __construct(
        private readonly RouterInterface $router,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly array $services,
        private readonly array $queues,
        private readonly array $scheduled,
        private readonly array $hookNamespaces = ['App'],
        private readonly ?string $hooksFile = null,
        private readonly ?string $projectRoot = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write JSON to a file instead of stdout');
        $this->addOption('hooks-output', null, InputOption::VALUE_REQUIRED, 'Write a bounded method hook manifest');
        $this->addOption('write-hooks', null, InputOption::VALUE_NONE, 'Write the method hook manifest to the configured hooks file');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $normalizer = new SymbolIdNormalizer();
            $routes = [];
            $hookClasses = [];
            foreach ($this->router->getRouteCollection() as $name => $route) {
                foreach ($route->getMethods() ?: ['GET'] as $method) {
                    try {
                        $routeId = (string) $normalizer->normalize(['kind' => 'route', 'method' => $method, 'template' => '/' . ltrim($route->getPath(), '/')]);
                    } catch (Throwable) {
                        continue;
                    }
                    $controllerId = null;
                    $controller = $route->getDefault('_controller');
                    if (is_string($controller)) {
                        $hookClasses[] = explode('::', $controller, 2)[0];
                        try {
                            $controllerId = (string) $normalizer->normalize(['kind' => 'controller', 'controller' => $controller]);
                        } catch (Throwable) {
                        }
                    }
                    $routes[] = ['route_id' => $routeId, 'name' => (string) $name, 'uri' => $route->getPath(), 'controller_id' => $controllerId];
                }
            }
            foreach ($this->services as $service) {
                if (is_string($service)) {
                    $hookClasses[] = $service;
                } elseif (is_array($service) && is_string($service['id'] ?? null)) {
                    $hookClasses[] = $service['id'];
                }
            }
            $listeners = [];
            foreach ($this->dispatcher->getListeners() as $event => $callbacks) {
                foreach ($callbacks as $callback) {
                    $listeners[] = ['event' => (string) $event, 'listener' => is_array($callback) ? (is_object($callback[0]) ? $callback[0]::class : (string) $callback[0]) . '::' . $callback[1] : (is_object($callback) ? $callback::class : (string) $callback)];
                }
            }
            $json = (new IntrospectionDocument($routes, $this->services, $listeners, $this->scheduled, $this->queues))->toJson();
            $writeHooks = (bool) $input->getOption('write-hooks');
            $hooksPath = $input->getOption('hooks-output');
            if ($writeHooks && (!is_string($hooksPath) || $hooksPath === '')) {
                $hooksPath = MethodHookRegistry::resolvePath($this->hooksFile, $this->projectRoot);
                if ($hooksPath === null) {
                    $output->writeln('<error>--write-hooks needs INDEXER_OTEL_HOOKS_FILE (or indexr_otel.hooks_file) to be set.</error>');
                    return self::FAILURE;
                }
            }
            if (is_string($hooksPath) && $hooksPath !== '') {
                $count = MethodHookManifest::write($hooksPath, array_values(array_unique($hookClasses)), $this->hookNamespaces);
                if ($count === null) {
                    $output->writeln('<error>Unable to write method hook manifest.</error>');
                    return self::FAILURE;
                }
                if ($writeHooks && !is_string($input->getOption('output'))) {
                    $output->writeln(sprintf('Wrote %d hooked methods to %s', $count, $hooksPath));
                    return self::SUCCESS;
                }
            }
            $path = $input->getOption('output');
            if (is_string($path) && $path !== '') {
                if (file_put_contents($path, $json, LOCK_EX) === false) {
                    return self::FAILURE;
                }
            } else {
                $output->write($json);
            }
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $output->writeln('<error>Indexr export failed: ' . $exception::class . '</error>');
            return self::FAILURE;
        }
    }
}
