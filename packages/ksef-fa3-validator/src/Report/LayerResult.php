<?php

declare(strict_types=1);

namespace KsefFa3\Report;

final readonly class LayerResult
{
    public function __construct(
        public Layer $layer,
        public LayerStatus $status,
    ) {}
}
