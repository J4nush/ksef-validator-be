<?php

declare(strict_types=1);

namespace KsefFa3\Schema;

interface SchemaRepository
{
    public function fa3SchemaSource(): string;

    public function schemaDirectory(): string;

    public function source(): SchemaSource;
}
