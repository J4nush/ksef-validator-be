<?php

declare(strict_types=1);

namespace KsefFa3\Report;

use JsonSerializable;
use KsefFa3\Fa3Validator;
use KsefFa3\Schema\SchemaSource;

final readonly class Report implements JsonSerializable
{
    /**
     * @param  list<Finding>  $findings
     * @param  list<SkippedCheck>  $skipped
     * @param  list<LayerResult>  $stages
     */
    public function __construct(
        public string $engineVersion,
        public string $ruleset,
        public SchemaSource $source,
        public DocumentInfo $document,
        public array $findings,
        public array $skipped,
        public array $stages,
        public bool $unsupported,
    ) {}

    public function result(): Result
    {
        if ($this->unsupported) {
            return Result::Unsupported;
        }

        if ($this->count(Severity::Error) > 0) {
            return Result::Errors;
        }

        if ($this->count(Severity::Warning) > 0) {
            return Result::Warnings;
        }

        return Result::Passed;
    }

    /**
     * @return array{
     *     schemaVersion: string,
     *     engineVersion: string,
     *     ruleset: string,
     *     source: array{ksefApiCommit: string, xsdSha256: string},
     *     result: string,
     *     document: array{type: ?string, variant: ?string, schema: ?string, sizeBytes: int, sha256Base64Url: string},
     *     summary: array{errors: int, warnings: int, info: int, skippedChecks: int},
     *     stages: list<array{id: string, status: string}>,
     *     findings: list<array{id: string, severity: string, stage: string, title: string, field: ?string, line: ?int, message: string, fix: string, docsUrl: string, params: array<string, string>}>,
     *     skipped: list<array{id: string, stage: ?string, message: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'schemaVersion' => Fa3Validator::REPORT_SCHEMA_VERSION,
            'engineVersion' => $this->engineVersion,
            'ruleset' => $this->ruleset,
            'source' => [
                'ksefApiCommit' => $this->source->ksefApiCommit,
                'xsdSha256' => $this->source->xsdSha256,
            ],
            'result' => $this->result()->value,
            'document' => [
                'type' => $this->document->type,
                'variant' => $this->document->variant,
                'schema' => $this->document->schema,
                'sizeBytes' => $this->document->sizeBytes,
                'sha256Base64Url' => $this->document->sha256Base64Url,
            ],
            'summary' => [
                'errors' => $this->count(Severity::Error),
                'warnings' => $this->count(Severity::Warning),
                'info' => $this->count(Severity::Info),
                'skippedChecks' => count($this->skipped),
            ],
            'stages' => array_map(fn (LayerResult $result): array => [
                'id' => $result->layer->value,
                'status' => $result->status->value,
            ], $this->stages),
            'findings' => array_map(fn (Finding $finding): array => [
                'id' => $finding->id,
                'severity' => $finding->severity->value,
                'stage' => $finding->stage->value,
                'title' => $finding->title,
                'field' => $finding->field,
                'line' => $finding->line,
                'message' => $finding->message,
                'fix' => $finding->fix,
                'docsUrl' => $finding->docsUrl,
                'params' => $finding->params,
            ], $this->findings),
            'skipped' => array_map(fn (SkippedCheck $check): array => [
                'id' => $check->id,
                'stage' => $check->stage?->value,
                'message' => $check->message,
            ], $this->skipped),
        ];
    }

    public function toJson(): string
    {
        return json_encode(
            $this,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $report = $this->toArray();

        // An empty PHP array would encode as a JSON list, and params is always an object.
        $report['findings'] = array_map(fn (array $finding): array => [...$finding, 'params' => (object) $finding['params']], $report['findings']);

        return $report;
    }

    private function count(Severity $severity): int
    {
        return count(array_filter($this->findings, fn (Finding $finding): bool => $finding->severity === $severity));
    }
}
