<?php

declare(strict_types=1);

namespace Indexr\Otel\Symfony\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Indexr\Otel\Core\SqlSpanRunner;
use Throwable;

/** DBAL 4 middleware; query values are never observed. */
final class IndexerDbalMiddleware implements Middleware
{
    public function __construct(
        private readonly string $connectionName = 'default',
        private readonly string $projectRoot = '.',
        /** @var list<string> */
        private readonly array $applicationNamespaces = ['App'],
        /** @var list<string> */
        private readonly array $tablePrefixes = [],
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new IndexerDriver($driver, $this->connectionName, $this->projectRoot, $this->applicationNamespaces, $this->tablePrefixes);
    }
}

final class IndexerDriver extends AbstractDriverMiddleware
{
    /** @param list<string> $namespaces @param list<string> $prefixes */
    public function __construct(Driver $driver, private readonly string $name, private readonly string $root, private readonly array $namespaces, private readonly array $prefixes)
    {
        parent::__construct($driver);
    }

    public function connect(#[\SensitiveParameter] array $params): \Doctrine\DBAL\Driver\Connection
    {
        return new IndexerConnection(parent::connect($params), $this->name, $this->root, $this->namespaces, $this->prefixes);
    }
}

final class IndexerConnection extends AbstractConnectionMiddleware
{
    /** @param list<string> $namespaces @param list<string> $prefixes */
    public function __construct(\Doctrine\DBAL\Driver\Connection $connection, private readonly string $name, private readonly string $root, private readonly array $namespaces, private readonly array $prefixes)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        return new IndexerStatement(parent::prepare($sql), $sql, $this->name, $this->root, $this->namespaces, $this->prefixes);
    }

    public function query(string $sql): Result
    {
        $active = (new SqlSpanRunner($this->name, $this->namespaces, $this->root, $this->prefixes))->begin($sql);
        try {
            return parent::query($sql);
        } catch (Throwable $failure) {
            $active?->finish($failure);
            $active = null;
            throw $failure;
        } finally {
            $active?->finish();
        }
    }

    public function exec(string $sql): int
    {
        $active = (new SqlSpanRunner($this->name, $this->namespaces, $this->root, $this->prefixes))->begin($sql);
        try {
            return parent::exec($sql);
        } catch (Throwable $failure) {
            $active?->finish($failure);
            $active = null;
            throw $failure;
        } finally {
            $active?->finish();
        }
    }
}

final class IndexerStatement extends AbstractStatementMiddleware
{
    /** @param list<string> $namespaces @param list<string> $prefixes */
    public function __construct(Statement $statement, private readonly string $sql, private readonly string $name, private readonly string $root, private readonly array $namespaces, private readonly array $prefixes)
    {
        parent::__construct($statement);
    }

    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        parent::bindValue($param, $value, $type);
    }

    public function execute(): Result
    {
        $active = (new SqlSpanRunner($this->name, $this->namespaces, $this->root, $this->prefixes))->begin($this->sql);
        try {
            return parent::execute();
        } catch (Throwable $failure) {
            $active?->finish($failure);
            $active = null;
            throw $failure;
        } finally {
            $active?->finish();
        }
    }
}
