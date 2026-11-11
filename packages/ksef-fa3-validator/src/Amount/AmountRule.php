<?php

declare(strict_types=1);

namespace KsefFa3\Amount;

use KsefFa3\Rule\Violation;

interface AmountRule
{
    /**
     * @return iterable<Violation>
     */
    public function check(InvoiceAmounts $amounts): iterable;
}
