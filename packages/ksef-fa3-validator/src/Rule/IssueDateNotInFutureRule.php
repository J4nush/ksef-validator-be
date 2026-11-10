<?php

declare(strict_types=1);

namespace KsefFa3\Rule;

use DateTimeImmutable;
use DateTimeZone;
use KsefFa3\Clock\Clock;
use KsefFa3\Xml\InvoiceDocument;

final readonly class IssueDateNotInFutureRule implements Rule
{
    public function __construct(private Clock $clock) {}

    public function check(InvoiceDocument $document, string $bytes): iterable
    {
        $element = $document->first('/fa:Faktura/fa:Fa/fa:P_1');

        if ($element === null) {
            return;
        }

        $issueDate = trim($element->textContent);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $issueDate);

        if ($parsed === false || $parsed->format('Y-m-d') !== $issueDate) {
            return;
        }

        $today = $this->clock->now()->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('Y-m-d');

        if ($issueDate > $today) {
            yield new Violation('TECH-P1-FUTURE', $document->fieldPath($element), $element->getLineNo());
        }
    }
}
