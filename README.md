# Developer Assistant OpenTelemetry — Symfony

PHP telemetry integration for Developer Intelligence Assistant. This package provides Symfony request, job, and command instrumentation, backed by the shared core package.

## Installation

```sh
composer require aminmh/developer-assistant-otel-symfony
```

Requires PHP 8.1 or newer. The Symfony adapter supports Symfony 6.3, 6.4 and 7 (Doctrine DBAL 3 and 4). Register `Indexr\Otel\Symfony\IndexerOtelBundle` in `config/bundles.php`; see the installation guide.

See the [installation and configuration guide](docs/installation.md) for optional OpenTelemetry extensions, export configuration, framework setup, and runtime behavior.

## License

Apache License 2.0. See [LICENSE](LICENSE).
