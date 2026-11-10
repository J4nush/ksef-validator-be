<?php

declare(strict_types=1);

namespace KsefFa3\Rule;

use KsefFa3\Clock\Clock;

final readonly class RuleRegistry
{
    /**
     * @param  list<Rule>  $rules
     */
    public function __construct(private array $rules) {}

    public static function default(Clock $clock): self
    {
        return new self([
            new ProcessingInstructionRule,
            new SizeWithoutAttachmentRule,
            new AttachmentBatchOnlyRule,
            new NipChecksumRule,
            new IssueDateNotInFutureRule($clock),
            new LineNumberDuplicateRule,
        ]);
    }

    /**
     * @return list<Rule>
     */
    public function all(): array
    {
        return $this->rules;
    }
}
