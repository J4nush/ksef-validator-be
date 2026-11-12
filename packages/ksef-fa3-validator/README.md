# ksef-fa3-validator

Offline validator for Polish KSeF structured invoices in the FA(3) format, schema version 1-0E. It checks an XML file against the Ministry of Finance XSD and a set of technical rules that KSeF enforces outside the schema, explains every schema error in plain Polish and warns about amounts that do not add up. It ships as a PHP library and a command line tool.

Validation runs locally on the raw bytes of the file. The package never opens a network connection.

> **Status:** pre-release (`0.3.0`). The package is not on Packagist yet and the API may change before `1.0`.

## Scope

The validator checks:

- conformance with the official FA(3) 1-0E XSD, using a bundled copy of the schemas,
- file level rules: size limits, UTF-8 encoding, byte order mark, encoding declared in the prolog, characters the Ministry of Finance discourages, processing instructions,
- data rules: NIP and internal ID check digits, issue date not in the future, duplicate line numbers, attachments,
- arithmetic consistency of amounts on a plain VAT invoice, reported as warnings (see [Amount consistency](#amount-consistency)).

It does not:

- send anything to KSeF or call any other service,
- check whether an invoice with the same number already exists in KSeF (every report lists this under `skipped`),
- assess the invoice under tax law, for example whether the VAT rate or the exchange rate is the right one,
- store or log the files it receives.

A report without errors does not guarantee that KSeF will accept the invoice. KSeF runs its own checks, and this package covers only part of them.

Documents in the older FA(2) format, and any other file that is not FA(3) 1-0E, get the result `UNSUPPORTED`.

Report messages, fix hints and the CLI output are in Polish, because the intended users are Polish taxpayers and accountants. Rule identifiers and report keys are in English and are stable.

## Requirements

- PHP 8.5 or later
- `ext-bcmath`, `ext-dom`, `ext-libxml`, `ext-mbstring`

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
$report->stages;     // list of KsefFa3\Report\LayerResult, one per layer
```

`validate()` takes the raw bytes of the file, not a parsed document, so the encoding and size checks see exactly what KSeF would receive. It does not throw because of bad file content. Every problem ends up in the report.

The constructor accepts two optional arguments: a `Clock`, used by `TECH-P1-FUTURE` and handy in tests, and a `SchemaRepository` for a different schema source. `$validator->source()` returns details of the schema in use.

A validator instance loads the schema once, in its constructor. Reuse it across files.

### Command line

```bash
vendor/bin/ksef-validate invoice.xml
vendor/bin/ksef-validate invoice.xml --json
vendor/bin/ksef-validate --catalog
vendor/bin/ksef-validate --version
vendor/bin/ksef-validate --help
```

The command takes exactly one file. `--version` prints the engine version, the ruleset, the commit of the schema repository and the SHA-256 of the XSD.

`--catalog` prints the rule catalog as a single line of JSON and exits with code 0. It does not take a file or any other option. The output is a list sorted by `id`, one object per rule with the keys `id`, `severity`, `stage`, `title`, `message`, `fix`, `slug` and `hints`. `message`, `fix` and the hints are templates with `{placeholders}` that the engine fills in from `params`. `hints` is an object, empty when the rule has none.

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
  "schemaVersion": "1.1",
  "engineVersion": "0.3.0",
  "ruleset": "fa3-1-0E-2026-11.2",
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
  "stages": [
    { "id": "security", "status": "passed" },
    { "id": "recognition", "status": "passed" },
    { "id": "schema", "status": "passed" },
    { "id": "rules", "status": "errors" },
    { "id": "amounts", "status": "passed" }
  ],
  "findings": [
    {
      "id": "TECH-NIP-CHECKSUM",
      "severity": "ERROR",
      "stage": "rules",
      "title": "Błędny NIP",
      "field": "Podmiot1/DaneIdentyfikacyjne/NIP",
      "line": 10,
      "message": "NIP ma niepoprawną cyfrę kontrolną. KSeF odrzuca taki plik na środowisku produkcyjnym.",
      "fix": "Sprawdź NIP w danych firmy i wygeneruj plik ponownie.",
      "docsUrl": "/ksef/bledy/nip-cyfra-kontrolna",
      "params": { "label": "NIP sprzedawcy", "value": "1111111112", "expected": "1" }
    }
  ],
  "skipped": [
    {
      "id": "KSEF-DUPLICATE",
      "stage": null,
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
| `stages` | Status of each of the five validation layers, always in the same order. See [Layers](#layers). |
| `stage` | Layer of the pipeline that reported the finding: `security`, `recognition`, `schema`, `rules` or `amounts`. |
| `title` | Short Polish title of the rule, the same for every finding of that rule. |
| `docsUrl` | Relative path to the error description. The last segment is a stable slug of the rule. |
| `params` | Values used to fill in the message and the fix, all as strings, with long values shortened to 60 characters as in the message. Every finding with a `field` also gets `label`, the field name in Polish. `TECH-NIP-CHECKSUM` and `TECH-INTERNAL-ID-CHECKSUM` add `value` and `expected`, the correct check digit, which is missing when the first nine digits cannot give one. An object, `{}` when empty. |
| `document.sha256Base64Url` | SHA-256 of the raw file bytes, Base64URL without padding. |
| `skipped` | Checks the tool did not perform but the user should know about, each with a message that gives the reason. `stage` names the layer that skipped its own checks, or is `null` for a check outside every layer, such as `KSEF-DUPLICATE`. |

`resources/report.schema.json` describes the report as a JSON Schema (draft 2020-12). `toArray()` returns `params` as a PHP array, so an empty one becomes `[]` if you encode it yourself; `toJson()` and `json_encode($report)` keep it an object.

`schemaVersion` and rule identifiers are stable. New rules may be added, and any change to the rule set changes `ruleset`. Version `1.1` of the report only adds `stages`, `findings[].stage`, `findings[].title`, `findings[].params` and `skipped[].stage` to `1.0`.

### Layers

Validation runs in five layers, and `stages` lists them in this order:

| Layer | Checks |
|---|---|
| `security` | Size, encoding, byte order mark, DOCTYPE and well formed XML |
| `recognition` | Whether the document is an FA(3) 1-0E invoice |
| `schema` | The FA(3) XSD |
| `rules` | Technical rules outside the schema |
| `amounts` | Amount consistency |

| Status | Meaning |
|---|---|
| `passed` | The layer ran and reported no error or warning. `INFO` findings do not change the status. |
| `warnings` | The layer reported at least one warning and no error. |
| `errors` | The layer reported at least one error. |
| `unsupported` | Only for `recognition`: the document is not FA(3) 1-0E. |
| `not_run` | An earlier layer stopped validation, for example after an empty file or a DOCTYPE. |
| `not_checked` | The layer skipped its own checks and listed the reason under `skipped`. Today only `amounts` does this, with `AMT-NOT-CHECKED`. |

An error that does not stop validation, such as `TECH-UTF8-BOM` in `security`, gives the layer `errors` while the following layers still run.

## Rules

The catalog lives in `resources/rules/`, one YAML file per rule with the severity, the layer (`stage`, or `null` for a rule outside the layers), the Polish title, message, fix hint and slug. `RULE-TRUNCATED` has `stage: null` too, because both the `rules` and the `amounts` layer can report it. An empty file, a file over the size limit, an encoding other than UTF-8, a DOCTYPE and malformed XML stop validation immediately, because later stages would have nothing to check.

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
| `XSD-MISSING-ELEMENT` | ERROR | A required element is missing |
| `XSD-ELEMENT-ORDER` | ERROR | An element comes before one that should precede it |
| `XSD-ELEMENT-REPEATED` | ERROR | An element appears more times than the schema allows |
| `XSD-UNEXPECTED-ELEMENT` | ERROR | An element is not part of the schema at this position |
| `XSD-WRONG-NAMESPACE` | ERROR | A known element is outside the FA(3) namespace |
| `XSD-DECIMAL-FORMAT` | ERROR | A number is not a valid decimal, for example it uses a comma or a space |
| `XSD-DECIMAL-PRECISION` | ERROR | A number has more decimal places than allowed |
| `XSD-INTEGER-FORMAT` | ERROR | A value is not a whole number |
| `XSD-DATE-FORMAT` | ERROR | A date or date and time is not valid |
| `XSD-DATE-RANGE` | ERROR | A date is outside the range the schema accepts |
| `XSD-MIN-VALUE` | ERROR | A number is below the allowed minimum |
| `XSD-MAX-VALUE` | ERROR | A number is above the allowed maximum |
| `XSD-EMPTY` | ERROR | A field is empty |
| `XSD-MIN-LENGTH` | ERROR | A text is shorter than allowed |
| `XSD-MAX-LENGTH` | ERROR | A text is longer than allowed |
| `XSD-ENUM` | ERROR | A value is not on the list of allowed values |
| `XSD-NIP-FORMAT` | ERROR | A NIP does not have the format of ten digits |
| `XSD-PATTERN` | ERROR | A value does not match the pattern required by the schema |
| `XSD-FIXED-VALUE` | ERROR | A value differs from the fixed value required by the schema |
| `XSD-ATTRIBUTE-MISSING` | ERROR | A required attribute is missing |
| `XSD-ATTRIBUTE-NOT-ALLOWED` | ERROR | An element has an attribute the schema does not define |
| `XSD-CHILD-NOT-ALLOWED` | ERROR | A text field contains XML markup |
| `XSD-TEXT-NOT-ALLOWED` | ERROR | A section contains text outside its fields |
| `XSD-UNMAPPED` | ERROR | Any other schema error, with the original libxml message |
| `TECH-ATTACHMENT-BATCH-ONLY` | WARNING | The invoice has an attachment |
| `TECH-LINE-NUMBER-DUPLICATE` | WARNING | A line number is repeated |
| `AMT-LINE-NET` | WARNING | A line net value does not match quantity, unit price and discount |
| `AMT-LINE-GROSS` | WARNING | A line gross value does not match quantity, gross unit price and discount |
| `AMT-RATE-NET` | WARNING | The net total for a VAT rate does not match the lines with that rate |
| `AMT-RATE-VAT` | WARNING | The tax for a VAT rate does not match its net total |
| `AMT-RATE-MISSING` | WARNING | Lines have a VAT rate without a net total, or a net total has no lines |
| `AMT-TOTAL` | WARNING | The amount due does not match the net totals and the tax |
| `AMT-CURRENCY-VAT` | WARNING | The tax converted to PLN does not match the exchange rate in the file |
| `AMT-PLN-CURRENCY-FIELDS` | WARNING | A PLN invoice fills the tax fields meant for foreign currency invoices |
| `XSD-TRUNCATED` | INFO | Only part of the schema errors is shown |
| `RULE-TRUNCATED` | INFO | Only part of the findings for a rule is shown |
| `DOC-FA2-UNSUPPORTED` | INFO | The invoice uses the older FA(2) format |
| `DOC-UNSUPPORTED` | INFO | The document is not a supported invoice |
| `KSEF-DUPLICATE` | INFO | Duplicate check in KSeF, never performed |
| `AMT-NOT-CHECKED` | INFO | Amount consistency was not checked, with the reason |

`KSEF-DUPLICATE` and `AMT-NOT-CHECKED` are not check results. They go into `skipped`. `KSEF-DUPLICATE` is listed for every recognised FA(3) document as a reminder that duplicates are not detected.

The report shows at most 100 schema errors, followed by `XSD-TRUNCATED`. Each technical and amount rule reports at most 100 findings, followed by `RULE-TRUNCATED` with the total count.

### Schema errors

Every libxml schema error is mapped to its own rule id, with the field path, the line and a message that names the field in words, for example "data wystawienia" for `P_1`. The fix hint depends on the field: a wrong `P_12` gets advice about VAT rates, a lowercase `KodWaluty` about currency codes, and so on. Rules keep these hints in an optional `hints` map in their YAML file. `XSD-UNMAPPED` remains as a fallback for messages that have no mapping, and none of the test fixtures or Ministry of Finance samples produces it.

After a missing, misplaced, repeated or unexpected element (`XSD-MISSING-ELEMENT`, `XSD-ELEMENT-ORDER`, `XSD-ELEMENT-REPEATED`, `XSD-UNEXPECTED-ELEMENT`), libxml stops checking the rest of the parent element. Further errors in that part of the file appear only after the fix, so validate the file again.

## Amount consistency

KSeF does not check invoice arithmetic, so the amount rules are warnings and never change the result to `ERRORS`. They run only on a plain VAT invoice: `RodzajFaktury` is `VAT`, the invoice has at least one line, and it is not a correction, an advance, settlement or simplified invoice, a margin scheme invoice, an OSS invoice or a flat rate taxi invoice. The rules also stay off when the file has schema errors, because the values may not be numbers yet.

When the rules do not run, the report lists `AMT-NOT-CHECKED` under `skipped`, with the reason in the message, for example "faktura korygująca" or "najpierw popraw błędy wzoru FA(3)".

Calculations use `BcMath\Number`, so there are no floating point errors. Values are rounded to the grosz, half away from zero.

- A line value must equal the rounded result of quantity times price minus discount. `P_10` is accepted both as a discount per unit and as a discount for the whole line.
- The tax for a rate may be computed from the net total of that rate or summed from the tax of each line. Both methods are legal and can differ by a grosz, so both are accepted. Tax from gross line values and the `P_11Vat` fields are accepted as well.
- The tax per rate, the amount due `P_15` and the tax in PLN allow a difference of 0.01, because the Ministry of Finance samples themselves contain such differences. Line values and net totals per rate must match exactly.
- The tax in PLN is checked only against the exchange rate given in the lines, and only when every line has the same rate. The tool does not know whether the rate itself is correct.

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
