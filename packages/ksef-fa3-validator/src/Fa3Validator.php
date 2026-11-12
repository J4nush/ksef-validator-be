<?php

declare(strict_types=1);

namespace KsefFa3;

use KsefFa3\Catalog\FieldLabels;
use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Catalog\RuleCatalog;
use KsefFa3\Clock\Clock;
use KsefFa3\Clock\SystemClock;
use KsefFa3\Hash\Sha256Base64Url;
use KsefFa3\Pipeline\Pipeline;
use KsefFa3\Pipeline\Stage;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Report\DocumentInfo;
use KsefFa3\Report\Report;
use KsefFa3\Rule\RuleRegistry;
use KsefFa3\Schema\LocalSchemaRepository;
use KsefFa3\Schema\SchemaRepository;
use KsefFa3\Schema\SchemaSource;
use KsefFa3\Stage\AmountsStage;
use KsefFa3\Stage\ParseStage;
use KsefFa3\Stage\PreflightStage;
use KsefFa3\Stage\RecognitionStage;
use KsefFa3\Stage\RulesStage;
use KsefFa3\Stage\SchemaStage;
use KsefFa3\Xsd\SchemaModel;
use KsefFa3\Xsd\XsdErrorClassifier;

final readonly class Fa3Validator
{
    public const string ENGINE_VERSION = '0.3.0';

    public const string RULESET = 'fa3-1-0E-2026-11.2';

    public const string REPORT_SCHEMA_VERSION = '1.1';

    private Clock $clock;

    private SchemaSource $source;

    private string $schemaDirectory;

    private string $schemaSource;

    private RuleCatalog $catalog;

    private FindingFactory $findings;

    private XsdErrorClassifier $classifier;

    private FieldLabels $labels;

    public function __construct(?Clock $clock = null, ?SchemaRepository $schemas = null)
    {
        $this->clock = $clock ?? new SystemClock;
        $schemas ??= new LocalSchemaRepository;
        $this->source = $schemas->source();
        $this->schemaDirectory = $schemas->schemaDirectory();
        $this->schemaSource = $schemas->fa3SchemaSource();
        $this->catalog = RuleCatalog::fromDirectory(dirname(__DIR__).'/resources/rules');
        $this->labels = FieldLabels::fromFile(dirname(__DIR__).'/resources/fields/labels.yaml');
        $this->findings = new FindingFactory($this->catalog, $this->labels);
        $this->classifier = new XsdErrorClassifier($this->labels, SchemaModel::fromDirectory($this->schemaDirectory));
    }

    public function source(): SchemaSource
    {
        return $this->source;
    }

    public function catalog(): RuleCatalog
    {
        return $this->catalog;
    }

    public function validate(string $rawXmlBytes): Report
    {
        $context = new ValidationContext($rawXmlBytes);
        (new Pipeline($this->stages()))->run($context);

        return new Report(
            engineVersion: self::ENGINE_VERSION,
            ruleset: self::RULESET,
            source: $this->source,
            document: new DocumentInfo(
                type: $context->type,
                variant: $context->variant,
                schema: $context->schema,
                sizeBytes: strlen($rawXmlBytes),
                sha256Base64Url: Sha256Base64Url::of($rawXmlBytes),
            ),
            findings: $context->findings(),
            skipped: $context->skipped(),
            stages: $context->stages(),
            unsupported: $context->isUnsupported(),
        );
    }

    /**
     * @return list<Stage>
     */
    private function stages(): array
    {
        return [
            new PreflightStage($this->findings),
            new ParseStage($this->findings, $this->schemaDirectory),
            new RecognitionStage($this->findings),
            new SchemaStage($this->findings, $this->schemaSource, $this->schemaDirectory, $this->classifier),
            new RulesStage($this->findings, RuleRegistry::default($this->clock)),
            new AmountsStage($this->findings, $this->labels),
        ];
    }
}
