<?php

declare(strict_types=1);

use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Catalog\RuleCatalog;
use KsefFa3\Pipeline\Pipeline;
use KsefFa3\Pipeline\Stage;
use KsefFa3\Pipeline\StageOutcome;
use KsefFa3\Pipeline\ValidationContext;

covers(Pipeline::class);

function recordingStage(string $id, StageOutcome $outcome): Stage
{
    return new readonly class($id, $outcome) implements Stage
    {
        public function __construct(private string $id, private StageOutcome $outcome) {}

        public function process(ValidationContext $context): StageOutcome
        {
            $context->add((new FindingFactory(RuleCatalog::fromDirectory(rulesPath())))->create($this->id));

            return $this->outcome;
        }
    };
}

/**
 * @param  list<Stage>  $stages
 * @return list<string>
 */
function stagesRun(array $stages): array
{
    $context = new ValidationContext('<a/>');
    (new Pipeline($stages))->run($context);

    return array_map(fn ($finding) => $finding->id, $context->findings());
}

it('runs every stage while they continue', function () {
    expect(stagesRun([
        recordingStage('XML-EMPTY', StageOutcome::Continue),
        recordingStage('SEC-DOCTYPE', StageOutcome::Continue),
    ]))->toBe(['XML-EMPTY', 'SEC-DOCTYPE']);
});

it('stops after the stage that asks to stop', function () {
    expect(stagesRun([
        recordingStage('XML-EMPTY', StageOutcome::Continue),
        recordingStage('SEC-DOCTYPE', StageOutcome::Stop),
        recordingStage('XML-MALFORMED', StageOutcome::Continue),
    ]))->toBe(['XML-EMPTY', 'SEC-DOCTYPE']);
});
