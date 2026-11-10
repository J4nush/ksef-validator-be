<?php

declare(strict_types=1);

namespace KsefFa3\Rule;

use KsefFa3\Xml\InvoiceDocument;

interface Rule
{
    /**
     * @return iterable<Violation>
     */
    public function check(InvoiceDocument $document, string $bytes): iterable;
}
