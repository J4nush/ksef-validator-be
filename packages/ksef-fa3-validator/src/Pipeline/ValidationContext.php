<?php

declare(strict_types=1);

namespace KsefFa3\Pipeline;

use DOMDocument;
use KsefFa3\Report\Finding;
use KsefFa3\Report\SkippedCheck;
use KsefFa3\Xml\InvoiceDocument;

final class ValidationContext
{
    public ?DOMDocument $document = null;

    public ?InvoiceDocument $invoice = null;

    public ?string $type = null;

    public ?string $variant = null;

    public ?string $schema = null;

    /** @var list<Finding> */
    private array $findings = [];

    /** @var list<SkippedCheck> */
    private array $skipped = [];

    private bool $unsupported = false;

    public function __construct(public readonly string $bytes) {}

    public function add(Finding $finding): void
    {
        $this->findings[] = $finding;
    }

    public function skip(SkippedCheck $check): void
    {
        $this->skipped[] = $check;
    }

    public function markUnsupported(): void
    {
        $this->unsupported = true;
    }

    /**
     * @return list<Finding>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /**
     * @return list<SkippedCheck>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }

    public function isUnsupported(): bool
    {
        return $this->unsupported;
    }
}
