<?php

declare(strict_types=1);

namespace KsefFa3\Rule;

use KsefFa3\Xml\InvoiceDocument;

final readonly class SizeWithoutAttachmentRule implements Rule
{
    public function check(InvoiceDocument $document, string $bytes): iterable
    {
        if (strlen($bytes) > 1_000_000 && ! $document->hasAttachment()) {
            yield new Violation('TECH-SIZE-WITHOUT-ATTACHMENT');
        }
    }
}
