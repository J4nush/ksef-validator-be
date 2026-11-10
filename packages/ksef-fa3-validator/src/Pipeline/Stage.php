<?php

declare(strict_types=1);

namespace KsefFa3\Pipeline;

interface Stage
{
    public function process(ValidationContext $context): StageOutcome;
}
