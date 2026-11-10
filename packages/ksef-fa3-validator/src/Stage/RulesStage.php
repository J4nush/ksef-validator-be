<?php

declare(strict_types=1);

namespace KsefFa3\Stage;

use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Pipeline\Stage;
use KsefFa3\Pipeline\StageOutcome;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Rule\RuleRegistry;

final readonly class RulesStage implements Stage
{
    private const int SHOWN_PER_RULE = 100;

    public function __construct(private FindingFactory $findings, private RuleRegistry $rules) {}

    public function process(ValidationContext $context): StageOutcome
    {
        $invoice = $context->invoice;

        if ($invoice === null) {
            return StageOutcome::Continue;
        }

        $totals = [];

        foreach ($this->rules->all() as $rule) {
            foreach ($rule->check($invoice, $context->bytes) as $violation) {
                $totals[$violation->id] = ($totals[$violation->id] ?? 0) + 1;

                if ($totals[$violation->id] <= self::SHOWN_PER_RULE) {
                    $context->add($this->findings->create($violation->id, $violation->field, $violation->line, $violation->params));
                }
            }
        }

        foreach ($totals as $id => $total) {
            if ($total > self::SHOWN_PER_RULE) {
                $context->add($this->findings->create('RULE-TRUNCATED', params: ['id' => $id, 'shown' => self::SHOWN_PER_RULE, 'total' => $total]));
            }
        }

        return StageOutcome::Continue;
    }
}
