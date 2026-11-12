<?php

declare(strict_types=1);

namespace KsefFa3\Pipeline;

use KsefFa3\Report\Layer;

interface Stage
{
    public function layer(): Layer;

    public function process(ValidationContext $context): StageOutcome;
}
