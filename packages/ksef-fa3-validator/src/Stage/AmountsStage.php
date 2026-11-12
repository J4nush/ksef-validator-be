<?php

declare(strict_types=1);

namespace KsefFa3\Stage;

use KsefFa3\Amount\AmountRule;
use KsefFa3\Amount\AmountsScope;
use KsefFa3\Amount\CurrencyVatRule;
use KsefFa3\Amount\InvoiceAmounts;
use KsefFa3\Amount\LineValueRule;
use KsefFa3\Amount\PlnCurrencyFieldsRule;
use KsefFa3\Amount\RateMissingRule;
use KsefFa3\Amount\RateNetRule;
use KsefFa3\Amount\RateVatRule;
use KsefFa3\Amount\TotalRule;
use KsefFa3\Catalog\FieldLabels;
use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Pipeline\Stage;
use KsefFa3\Pipeline\StageOutcome;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Report\Layer;
use KsefFa3\Rule\Violation;

final readonly class AmountsStage implements Stage
{
    private const string SCHEMA_ERRORS = 'najpierw popraw błędy wzoru FA(3)';

    private LimitedFindings $limited;

    /** @var list<AmountRule> */
    private array $rules;

    public function __construct(private FindingFactory $findings, private FieldLabels $labels)
    {
        $this->limited = new LimitedFindings($findings, $this->layer());
        $this->rules = [
            LineValueRule::net(),
            LineValueRule::gross(),
            new RateMissingRule,
            new RateNetRule,
            new RateVatRule,
            new TotalRule,
            new CurrencyVatRule,
            new PlnCurrencyFieldsRule,
        ];
    }

    public function layer(): Layer
    {
        return Layer::Amounts;
    }

    public function process(ValidationContext $context): StageOutcome
    {
        $invoice = $context->invoice;

        if ($invoice === null) {
            return StageOutcome::Continue;
        }

        if (self::hasSchemaErrors($context)) {
            return $this->skip($context, self::SCHEMA_ERRORS);
        }

        $amounts = InvoiceAmounts::read($invoice, $this->labels);
        $reason = AmountsScope::reasonToSkip($amounts);

        if ($reason !== null) {
            return $this->skip($context, $reason);
        }

        $this->limited->add($context, $this->violations($amounts));

        return StageOutcome::Continue;
    }

    /**
     * @return iterable<Violation>
     */
    private function violations(InvoiceAmounts $amounts): iterable
    {
        foreach ($this->rules as $rule) {
            yield from $rule->check($amounts);
        }
    }

    private function skip(ValidationContext $context, string $reason): StageOutcome
    {
        $context->skip($this->findings->skipped($this->layer(), 'AMT-NOT-CHECKED', ['reason' => $reason]));

        return StageOutcome::Continue;
    }

    private static function hasSchemaErrors(ValidationContext $context): bool
    {
        foreach ($context->findings() as $finding) {
            if (str_starts_with($finding->id, 'XSD-')) {
                return true;
            }
        }

        return false;
    }
}
