<?php

declare(strict_types=1);

namespace KsefFa3\Stage;

use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Rule\Violation;

final readonly class LimitedFindings
{
    private const int SHOWN_PER_RULE = 100;

    public function __construct(private FindingFactory $findings) {}

    /**
     * @param  iterable<Violation>  $violations
     */
    public function add(ValidationContext $context, iterable $violations): void
    {
        $totals = [];

        foreach ($violations as $violation) {
            $totals[$violation->id] = ($totals[$violation->id] ?? 0) + 1;

            if ($totals[$violation->id] <= self::SHOWN_PER_RULE) {
                $context->add($this->findings->create($violation->id, $violation->field, $violation->line, $violation->params, $violation->hints));
            }
        }

        foreach ($totals as $id => $total) {
            if ($total > self::SHOWN_PER_RULE) {
                $context->add($this->findings->create('RULE-TRUNCATED', params: ['id' => $id, 'shown' => self::SHOWN_PER_RULE, 'total' => $total]));
            }
        }
    }
}
