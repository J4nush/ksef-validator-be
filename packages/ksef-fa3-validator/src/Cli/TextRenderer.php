<?php

declare(strict_types=1);

namespace KsefFa3\Cli;

use KsefFa3\Report\DocumentInfo;
use KsefFa3\Report\Finding;
use KsefFa3\Report\Report;
use KsefFa3\Report\Result;
use KsefFa3\Report\Severity;

final readonly class TextRenderer
{
    public function render(Report $report): string
    {
        $summary = $report->toArray()['summary'];
        $result = $report->result();

        $lines = [
            'Wynik: '.self::resultLabel($result).' ('.$result->value.')',
            'Dokument: '.self::documentLabel($report->document),
            'SHA-256 pliku (Base64URL): '.$report->document->sha256Base64Url,
            "Błędy: {$summary['errors']}, ostrzeżenia: {$summary['warnings']}, informacje: {$summary['info']}",
            '',
        ];

        if ($report->findings === []) {
            $lines[] = 'Brak uwag.';
        } else {
            $lines[] = 'Uwagi:';

            foreach ($report->findings as $finding) {
                array_push($lines, ...self::findingLines($finding));
            }
        }

        if ($report->skipped !== []) {
            $lines[] = '';
            $lines[] = 'Niesprawdzone:';

            foreach ($report->skipped as $check) {
                $lines[] = '  '.$check->id.': '.self::clean($check->message);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return list<string>
     */
    private static function findingLines(Finding $finding): array
    {
        $heading = self::severityLabel($finding->severity).' '.$finding->id;

        if ($finding->field !== null) {
            $heading .= ', pole '.self::clean($finding->field);
        }

        $location = $finding->line === null ? '' : "linia {$finding->line}: ";

        return [
            $heading,
            '  '.$location.self::clean($finding->message),
            '  Poprawka: '.self::clean($finding->fix),
        ];
    }

    private static function resultLabel(Result $result): string
    {
        return match ($result) {
            Result::Passed => 'POPRAWNY',
            Result::Warnings => 'POPRAWNY Z OSTRZEŻENIAMI',
            Result::Errors => 'BŁĘDY',
            Result::Unsupported => 'NIEOBSŁUGIWANY DOKUMENT',
        };
    }

    private static function severityLabel(Severity $severity): string
    {
        return match ($severity) {
            Severity::Error => 'BŁĄD',
            Severity::Warning => 'OSTRZEŻENIE',
            Severity::Info => 'INFORMACJA',
        };
    }

    private static function documentLabel(DocumentInfo $document): string
    {
        if ($document->type === null) {
            return 'nierozpoznany';
        }

        $label = self::clean("{$document->type}({$document->variant})");

        return $document->schema === null ? $label : $label.', schemat '.self::clean($document->schema);
    }

    private static function clean(string $text): string
    {
        return (string) preg_replace('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]|\xE2\x80[\x8E\x8F\xA8-\xAE]|\xE2\x81[\xA6-\xA9]/', '?', $text);
    }
}
