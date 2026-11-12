<?php

declare(strict_types=1);

use KsefFa3\Pipeline\Pipeline;
use KsefFa3\Pipeline\Stage;
use KsefFa3\Pipeline\StageOutcome;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Report\Layer;

covers(Pipeline::class);

function recordingStage(string $id, StageOutcome $outcome, Layer $layer = Layer::Security): Stage
{
    return new readonly class($id, $outcome, $layer) implements Stage
    {
        public function __construct(private string $id, private StageOutcome $outcome, private Layer $layer) {}

        public function layer(): Layer
        {
            return $this->layer;
        }

        public function process(ValidationContext $context): StageOutcome
        {
            $context->add(findingFactory()->create($this->layer, $this->id));

            return $this->outcome;
        }
    };
}

/**
 * @param  list<Stage>  $stages
 */
function runStages(array $stages): ValidationContext
{
    $context = new ValidationContext('<a/>');
    (new Pipeline($stages))->run($context);

    return $context;
}

/**
 * @param  list<Stage>  $stages
 * @return list<string>
 */
function stagesRun(array $stages): array
{
    return array_map(fn ($finding) => $finding->id, runStages($stages)->findings());
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

it('marks the layer of every stage that ran and only those', function () {
    $context = runStages([
        recordingStage('TECH-UTF8-BOM', StageOutcome::Continue),
        recordingStage('DOC-UNSUPPORTED', StageOutcome::Stop, Layer::Recognition),
        recordingStage('XSD-EMPTY', StageOutcome::Continue, Layer::Schema),
    ]);

    expect(layerStatuses($context))->toBe([
        'security' => 'errors',
        'recognition' => 'passed',
        'schema' => 'not_run',
        'rules' => 'not_run',
        'amounts' => 'not_run',
    ]);
});
