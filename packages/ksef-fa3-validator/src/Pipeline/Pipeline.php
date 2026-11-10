<?php

declare(strict_types=1);

namespace KsefFa3\Pipeline;

final readonly class Pipeline
{
    /**
     * @param  list<Stage>  $stages
     */
    public function __construct(private array $stages) {}

    public function run(ValidationContext $context): void
    {
        foreach ($this->stages as $stage) {
            if ($stage->process($context) === StageOutcome::Stop) {
                return;
            }
        }
    }
}
