# ksef-fa3-validator

Offline validator for Polish KSeF structured invoices in the FA(3) format, schema version 1-0E. It checks an XML file against the Ministry of Finance XSD and a set of technical rules that KSeF enforces outside the schema. It ships as a PHP library and a command line tool.

Validation runs locally on the raw bytes of the file. The package never opens a network connection.

> **Status:** pre-release (`0.1.0`). The package is not on Packagist yet and the API may change before `1.0`.

## Scope

The validator checks:

- conformance with the official FA(3) 1-0E XSD, using a bundled copy of the schemas,
- file level rules: size limits, UTF-8 encoding, byte order mark, encoding declared in the prolog, characters the Ministry of Finance discourages, processing instructions,
- data rules: NIP and internal ID check digits, issue date not in the future, duplicate line numbers, attachments.

It does not:

- send anything to KSeF or call any other service,
- check whether an invoice with the same number already exists in KSeF (every report lists this under `skipped`),
- assess the business content of the invoice, for example whether amounts are correct under tax law,
- store or log the files it receives.

A report without errors does not guarantee that KSeF will accept the invoice. KSeF runs its own checks, and this package covers only part of them.

Documents in the older FA(2) format, and any other file that is not FA(3) 1-0E, get the result `UNSUPPORTED`.

Report messages, fix hints and the CLI output are in Polish, because the intended users are Polish taxpayers and accountants. Rule identifiers and report keys are in English and are stable.

## Requirements

- PHP 8.5 or later
- `ext-dom`, `ext-libxml`, `ext-mbstring`

The only package dependency is `symfony/yaml`, used to load the rule catalog.

## Installation

Once the package is published:

```bash
composer require michal-ziolek/ksef-fa3-validator
```

Until then, add it as a `path` repository:

```json
{
    "repositories": [
        { "type": "path", "url": "../ksef-fa3-validator" }
    ],
    "require": {
        "michal-ziolek/ksef-fa3-validator": "*"
    }
}
```

## Usage

### PHP

```php
use KsefFa3\Fa3Validator;

$validator = new Fa3Validator();
$report = $validator->validate(file_get_contents('invoice.xml'));

$report->result();   // KsefFa3\Report\Result: PASSED, WARNINGS, ERRORS or UNSUPPORTED
$report->findings;   // list of KsefFa3\Report\Finding
$report->toArray();  // array in the format described below
$report->toJson();   // the same report as JSON
```

`validate()` takes the raw bytes of the file, not a parsed document, so the encoding and size checks see exactly what KSeF would receive. It does not throw because of bad file content. Every problem ends up in the report.

The constructor accepts two optional arguments: a `Clock`, used by `TECH-P1-FUTURE` and handy in tests, and a `SchemaRepository` for a different schema source. `$validator->source()` returns details of the schema in use.

A validator instance loads the schema once, in its constructor. Reuse it across files.

### Command line

```bash
vendor/bin/ksef-validate invoice.xml
vendor/bin/ksef-validate invoice.xml --json
vendor/bin/ksef-validate --version
vendor/bin/ksef-validate --help
```

The command takes exactly one file. `--version` prints the engine version, the ruleset, the commit of the schema repository and the SHA-256 of the XSD.

| Exit code | Meaning |
|---|---|
| 0 | valid, possibly with warnings |
| 1 | the file has errors |
| 2 | the document is not an FA(3) invoice |
| 3 | usage error or the file cannot be read |

Sample text output:

```text
Wynik: BŁĘDY (ERRORS)
Dokument: FA(3), schemat 1-0E
SHA-256 pliku (Base64URL): PvSlgiZwRwsihsk0CWQaOsTT0ZVbclH9QsV8sYCIKo8
Błędy: 1, ostrzeżenia: 0, informacje: 0

Uwagi:
BŁĄD TECH-NIP-CHECKSUM, pole Podmiot1/DaneIdentyfikacyjne/NIP
  linia 10: NIP ma niepoprawną cyfrę kontrolną. KSeF odrzuca taki plik na środowisku produkcyjnym.
  Poprawka: Sprawdź NIP w danych firmy i wygeneruj plik ponownie.

Niesprawdzone:
  KSEF-DUPLICATE: Narzędzie nie sprawdza, czy faktura o tym numerze (NIP sprzedawcy, rodzaj faktury, P_2) jest już w KSeF.
```

## Report format

The same file with `--json`, pretty printed:

```json
{
  "schemaVersion": "1.0",
  "engineVersion": "0.1.0",
  "ruleset": "fa3-1-0E-2026-11",
  "source": {
    "ksefApiCommit": "ce3670ea042de380039de4c5849fd6455811b292",
    "xsdSha256": "b646b6b525f51adf1bb2545f111fc8ca6e7aa6dd2f98948f1667d3695c06d958"
  },
  "result": "ERRORS",
  "document": {
    "type": "FA",
    "variant": "3",
    "schema": "1-0E",
    "sizeBytes": 1785,
    "sha256Base64Url": "PvSlgiZwRwsihsk0CWQaOsTT0ZVbclH9QsV8sYCIKo8"
  },
  "summary": { "errors": 1, "warnings": 0, "info": 0, "skippedChecks": 1 },
  "findings": [
    {
      "id": "TECH-NIP-CHECKSUM",
      "severity": "ERROR",
      "field": "Podmiot1/DaneIdentyfikacyjne/NIP",
      "line": 10,
      "message": "NIP ma niepoprawną cyfrę kontrolną. KSeF odrzuca taki plik na środowisku produkcyjnym.",
      "fix": "Sprawdź NIP w danych firmy i wygeneruj plik ponownie.",
      "docsUrl": "/ksef/bledy/nip-cyfra-kontrolna"
    }
  ],
  "skipped": [
    {
      "id": "KSEF-DUPLICATE",
      "message": "Narzędzie nie sprawdza, czy faktura o tym numerze (NIP sprzedawcy, rodzaj faktury, P_2) jest już w KSeF."
    }
  ]
}
```

| Key | Description |
|---|---|
| `result` | `UNSUPPORTED` when the document is not FA(3) 1-0E. Otherwise `ERRORS` if there is at least one error, `WARNINGS` if there is at least one warning, and `PASSED` in any other case. `INFO` findings do not change the result. |
| `field` | Path from the `Faktura` element without namespace prefixes, with a 1-based index on repeated elements, for example `Podmiot3[2]/DaneIdentyfikacyjne/NIP`. `null` for byte level checks. |
| `line` | Line number in the file, or `null`. |
| `docsUrl` | Relative path to the error description. The last segment is a stable slug of the rule. |
| `document.sha256Base64Url` | SHA-256 of the raw file bytes, Base64URL without padding. |
| `skipped` | Checks the tool does not perform but the user should know about. |

`schemaVersion` and rule identifiers are stable. New rules may be added, and any change to the rule set changes `ruleset`.

## Rules

The catalog lives in `resources/rules/`. An empty file, a file over the size limit, an encoding other than UTF-8, a DOCTYPE and malformed XML stop validation immediately, because later stages would have nothing to check.

| Id | Severity | Check |
|---|---|---|
| `XML-EMPTY` | ERROR | The file is empty |
| `XML-MALFORMED` | ERROR | The file is not well formed XML |
| `SEC-SIZE-LIMIT` | ERROR | The file exceeds 3,000,000 bytes |
| `SEC-DOCTYPE` | ERROR | The file contains a DOCTYPE declaration |
| `TECH-ENCODING-UTF8` | ERROR | The file is not valid UTF-8 |
| `TECH-PROLOG-ENCODING` | ERROR | The prolog declares an encoding other than UTF-8 |
| `TECH-UTF8-BOM` | ERROR | The file starts with a byte order mark |
| `TECH-DISCOURAGED-CHAR` | ERROR | The file contains characters discouraged by the Ministry of Finance |
| `TECH-PROCESSING-INSTRUCTION` | ERROR | The file contains processing instructions |
| `TECH-SIZE-WITHOUT-ATTACHMENT` | ERROR | An invoice without an attachment exceeds 1,000,000 bytes |
| `TECH-NIP-CHECKSUM` | ERROR | A NIP has an invalid check digit |
| `TECH-INTERNAL-ID-CHECKSUM` | ERROR | An internal ID has an invalid check digit |
| `TECH-P1-FUTURE` | ERROR | The issue date is in the future (Europe/Warsaw) |
| `XSD-UNMAPPED` | ERROR | The document does not conform to the FA(3) schema |
| `TECH-ATTACHMENT-BATCH-ONLY` | WARNING | The invoice has an attachment |
| `TECH-LINE-NUMBER-DUPLICATE` | WARNING | A line number is repeated |
| `XSD-TRUNCATED` | INFO | Only part of the schema errors is shown |
| `RULE-TRUNCATED` | INFO | Only part of the findings for a rule is shown |
| `DOC-FA2-UNSUPPORTED` | INFO | The invoice uses the older FA(2) format |
| `DOC-UNSUPPORTED` | INFO | The document is not a supported invoice |
| `KSEF-DUPLICATE` | INFO | Duplicate check in KSeF, never performed |

`KSEF-DUPLICATE` is not a check result. It goes into `skipped` for every recognised FA(3) document as a reminder that duplicates are not detected.

The report shows at most 100 schema errors, followed by `XSD-TRUNCATED`. Each technical rule reports at most 100 findings, followed by `RULE-TRUNCATED` with the total count.

## Security

Invoices may come from untrusted users, so the package treats every file as hostile input:

- a byte level scan runs before the XML parser and rejects DOCTYPE, encodings other than UTF-8 and files over the size limit,
- XML is parsed and validated with `LIBXML_NONET`, without DTDs, XInclude or XML catalogs,
- during validation an entity loader restricted to the bundled schema directory is active, and the previous loader is restored afterwards,
- the `schemaLocation` in the main XSD is rewritten to a local path in memory, and the files on disk stay untouched.

The test suite covers XXE, billion laughs, DOCTYPE variants, UTF-16, UTF-32 and UTF-7 input, deeply nested documents, oversized files, random bytes and inputs crafted to make rules slow. It runs in a container with networking disabled.

## Schema source

The files in `resources/schemas/` come from the Ministry of Finance repository [CIRFMF/ksef-api](https://github.com/CIRFMF/ksef-api) and are stored byte for byte, without edits. `resources/schemas/source.json` records the source commit and the SHA-256 of `schemat_FA(3)_v1-0E.xsd`. Every report repeats these values under `source`.

The schemas are licensed under MIT, copyright 2025 Ministerstwo Finansów. The license text is in `resources/schemas/LICENSE-MF.txt` and must accompany these files when they are redistributed.

## Development

The development environment runs in Docker:

```bash
docker compose run --rm php composer install
docker compose run --rm php composer test
docker compose run --rm php composer analyse
docker compose run --rm php composer lint
docker compose run --rm php composer test:mutate
```

`test` runs Pest, `analyse` runs PHPStan at level max, `lint` runs Pint in check mode and `test:mutate` runs Pest mutation testing.

## Design decisions

Architecture decision records, in Polish:

- [0001: framework-free core](docs/adr/0001-rdzen-bez-frameworka.md)
- [0002: offline schemas](docs/adr/0002-schematy-offline.md)
- [0003: rejecting DOCTYPE before the parser](docs/adr/0003-odrzucenie-doctype-przed-parserem.md)

## License

MIT, copyright 2026 Michał Ziółek. See [LICENSE](LICENSE). The files in `resources/schemas/` are covered by the Ministry of Finance MIT license described above.
