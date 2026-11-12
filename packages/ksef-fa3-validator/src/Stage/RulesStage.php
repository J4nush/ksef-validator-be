<?php

declare(strict_types=1);

namespace KsefFa3\Stage;

use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Pipeline\Stage;
use KsefFa3\Pipeline\StageOutcome;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Report\Layer;
use KsefFa3\Rule\RuleRegistry;
use KsefFa3\Rule\Violation;
use KsefFa3\Xml\InvoiceDocument;

final readonly class RulesStage implements Stage
{
    private LimitedFindings $limited;

    public function __construct(FindingFactory $findings, private RuleRegistry $rules)
    {
        $this->limited = new LimitedFindings($findings, $this->layer());
    }

    public function layer(): Layer
    {
        return Layer::Rules;
    }

    public function process(ValidationContext $context): StageOutcome
    {
        $invoice = $context->invoice;

        if ($invoice !== null) {
            $this->limited->add($context, $this->violations($invoice, $context->bytes));
        }

        return StageOutcome::Continue;
    }

    /**
     * @return iterable<Violation>
     */
    private function violations(InvoiceDocument $invoice, string $bytes): iterable
    {
        foreach ($this->rules->all() as $rule) {
            yield from $rule->check($invoice, $bytes);
        }
    }
}
