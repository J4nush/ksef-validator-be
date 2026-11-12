<?php

declare(strict_types=1);

namespace KsefFa3\Cli;

use KsefFa3\Catalog\CatalogEntry;
use KsefFa3\Fa3Validator;
use KsefFa3\Report\Result;
use KsefFa3\Stage\PreflightStage;

final readonly class ValidateCommand
{
    private const string USAGE = <<<'TXT'
        Użycie:
          ksef-validate <plik.xml> [--json]
          ksef-validate --catalog
          ksef-validate --version
          ksef-validate --help

        Sprawdza fakturę FA(3) bez połączenia z siecią i bez wysyłania jej do KSeF.

        Opcje:
          --json     raport w formacie JSON
          --catalog  katalog reguł w formacie JSON, bez sprawdzania pliku
          --version  wersja silnika, zestawu reguł i schematu
          --help     ta pomoc

        Kody wyjścia:
          0  plik poprawny albo poprawny z ostrzeżeniami
          1  plik zawiera błędy
          2  dokument nie jest fakturą FA(3)
          3  błąd użycia albo odczytu pliku

        TXT;

    public function __construct(private Fa3Validator $validator) {}

    /**
     * @param  list<string>  $argv
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    public function run(array $argv, $stdout, $stderr): int
    {
        $arguments = array_slice($argv, 1);

        if (array_intersect($arguments, ['--help', '-h']) !== []) {
            fwrite($stdout, self::USAGE);

            return 0;
        }

        if (in_array('--version', $arguments, true)) {
            fwrite($stdout, $this->version());

            return 0;
        }

        if (in_array('--catalog', $arguments, true)) {
            if ($arguments !== ['--catalog']) {
                return self::usageError($stderr, 'opcja --catalog nie przyjmuje pliku ani innych opcji.');
            }

            fwrite($stdout, $this->catalog()."\n");

            return 0;
        }

        $json = false;
        $files = [];

        foreach ($arguments as $argument) {
            if ($argument === '--json') {
                $json = true;
            } elseif (str_starts_with($argument, '-')) {
                return self::usageError($stderr, "nieznana opcja {$argument}.");
            } else {
                $files[] = $argument;
            }
        }

        if ($files === []) {
            return self::usageError($stderr, 'nie podano pliku do sprawdzenia.');
        }

        if (count($files) > 1) {
            return self::usageError($stderr, 'podaj dokładnie jeden plik.');
        }

        return $this->validateFile($files[0], $json, $stdout, $stderr);
    }

    /**
     * @param  resource  $stdout
     * @param  resource  $stderr
     */
    private function validateFile(string $path, bool $json, $stdout, $stderr): int
    {
        if (is_dir($path)) {
            return self::readError($stderr, "{$path} to katalog, a nie plik.");
        }

        // One byte past the limit is enough for the size check, so a huge file never lands in memory.
        $bytes = is_file($path) && is_readable($path) ? file_get_contents($path, length: PreflightStage::MAX_BYTES + 1) : false;

        if ($bytes === false) {
            return self::readError($stderr, "nie można odczytać pliku {$path}.");
        }

        $report = $this->validator->validate($bytes);

        fwrite($stdout, $json ? $report->toJson()."\n" : (new TextRenderer)->render($report));

        return match ($report->result()) {
            Result::Passed, Result::Warnings => 0,
            Result::Errors => 1,
            Result::Unsupported => 2,
        };
    }

    private function version(): string
    {
        $source = $this->validator->source();

        return 'ksef-validate '.Fa3Validator::ENGINE_VERSION."\n"
            .'Zestaw reguł: '.Fa3Validator::RULESET."\n"
            .'Commit ksef-api: '.$source->ksefApiCommit."\n"
            .'SHA-256 XSD: '.$source->xsdSha256."\n";
    }

    private function catalog(): string
    {
        return json_encode(
            array_map(fn (CatalogEntry $entry): array => [
                'id' => $entry->id,
                'severity' => $entry->severity->value,
                'stage' => $entry->stage?->value,
                'title' => $entry->title,
                'message' => $entry->message,
                'fix' => $entry->fix,
                'slug' => $entry->slug,
                'hints' => (object) $entry->hints,
            ], $this->validator->catalog()->entries()),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  resource  $stderr
     */
    private static function usageError($stderr, string $message): int
    {
        fwrite($stderr, "Błąd: {$message}\nUżyj ksef-validate --help, aby zobaczyć sposób użycia.\n");

        return 3;
    }

    /**
     * @param  resource  $stderr
     */
    private static function readError($stderr, string $message): int
    {
        fwrite($stderr, "Błąd: {$message}\n");

        return 3;
    }
}
