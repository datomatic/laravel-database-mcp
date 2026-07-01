<?php

declare(strict_types=1);

namespace Datomatic\LaravelDatabaseMcp\Servers;

use Datomatic\LaravelDatabaseMcp\Tools\DescribeDatabaseTool;
use Datomatic\LaravelDatabaseMcp\Tools\QueryDatabaseTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;

#[Version('1.0.0')]
class DatabaseServer extends Server
{
    protected array $tools = [
        DescribeDatabaseTool::class,
        QueryDatabaseTool::class,
    ];

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        $this->name = (string) config('database-mcp.name');
        $this->instructions = (string) config('database-mcp.instructions');
    }
}
