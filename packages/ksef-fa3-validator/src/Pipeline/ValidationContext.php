<?php

declare(strict_types=1);

namespace KsefFa3\Pipeline;

use DOMDocument;
use KsefFa3\Report\Finding;
use KsefFa3\Report\Layer;
use KsefFa3\Report\LayerResult;
use KsefFa3\Report\LayerStatus;
use KsefFa3\Report\Severity;
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

    /** @var list<Layer> */
    private array $entered = [];

    public function __construct(public readonly string $bytes) {}

    public function enter(Layer $layer): void
    {
        $this->entered[] = $layer;
    }

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

    /**
     * @return list<LayerResult>
     */
    public function stages(): array
    {
        return array_map(fn (Layer $layer): LayerResult => new LayerResult($layer, $this->statusOf($layer)), Layer::cases());
    }

    private function statusOf(Layer $layer): LayerStatus
    {
        if (! in_array($layer, $this->entered, true)) {
            return LayerStatus::NotRun;
        }

        if ($layer === Layer::Recognition && $this->unsupported) {
            return LayerStatus::Unsupported;
        }

        $severities = [];

        foreach ($this->findings as $finding) {
            if ($finding->stage === $layer) {
                $severities[] = $finding->severity;
            }
        }

        return match (true) {
            in_array(Severity::Error, $severities, true) => LayerStatus::Errors,
            in_array(Severity::Warning, $severities, true) => LayerStatus::Warnings,
            in_array($layer, array_map(fn (SkippedCheck $check): ?Layer => $check->stage, $this->skipped), true) => LayerStatus::NotChecked,
            default => LayerStatus::Passed,
        };
    }
}
