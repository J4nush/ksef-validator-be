<?php

declare(strict_types=1);

namespace KsefFa3\Rule;

use KsefFa3\Xml\InvoiceDocument;

final readonly class AttachmentBatchOnlyRule implements Rule
{
    public function check(InvoiceDocument $document, string $bytes): iterable
    {
        $attachment = $document->first('/fa:Faktura/fa:Zalacznik');

        if ($attachment !== null) {
            yield new Violation('TECH-ATTACHMENT-BATCH-ONLY', $document->fieldPath($attachment), $attachment->getLineNo());
        }
    }
}
