<?php

declare(strict_types=1);

use KsefFa3\Fa3Validator;
use KsefFa3\Hash\Sha256Base64Url;
use KsefFa3\Pipeline\Pipeline;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Report\Result;
use KsefFa3\Schema\LocalSchemaRepository;
use KsefFa3\Schema\SchemaRepository;
use KsefFa3\Schema\SchemaSource;
use KsefFa3\Stage\PreflightStage;
use KsefFa3\Xml\LineLocator;

covers(Fa3Validator::class, Pipeline::class, ValidationContext::class, PreflightStage::class, LineLocator::class);

function paddedTo(int $size, string $prefix = ''): string
{
    $open = $prefix.'<a>';
    $close = '</a>';

    return $open.str_repeat(' ', $size - strlen($open) - strlen($close)).$close;
}

it('rejects an empty file', function (string $bytes) {
    $report = validateBytes($bytes);

    expect($report->result())->toBe(Result::Errors)
        ->and(findingIds($report))->toBe(['XML-EMPTY']);
})->with([
    'no bytes' => '',
    'whitespace only' => "  \n",
    'every XML whitespace' => " \t\r\n",
    'UTF-8 BOM only' => "\xEF\xBB\xBF",
    'UTF-8 BOM and a newline' => "\xEF\xBB\xBF\n",
    'UTF-8 BOM and every XML whitespace' => "\xEF\xBB\xBF \t\r\n",
]);

it('does not treat a single visible character as empty', function () {
    expect(findingIds(validateBytes(" x\n")))->not->toContain('XML-EMPTY')
        ->and(findingIds(validateBytes("\xEF\xBB\xBFx")))->not->toContain('XML-EMPTY');
});

it('treats a UTF-8 BOM in the middle as content', function () {
    expect(findingIds(validateBytes(" \xEF\xBB\xBF")))->not->toContain('XML-EMPTY');
});

it('lets a minimal document through to recognition', function () {
    $report = validateBytes('<a/>');

    expect($report->result())->toBe(Result::Unsupported)
        ->and(findingIds($report))->toBe(['DOC-UNSUPPORTED']);
});

it('accepts a file of exactly 3 000 000 bytes', function () {
    $bytes = paddedTo(3_000_000);

    expect(strlen($bytes))->toBe(3_000_000)
        ->and(findingIds(validateBytes($bytes)))->toBe(['DOC-UNSUPPORTED']);
});

it('stops at the size limit before any other check', function () {
    $bytes = paddedTo(3_000_001, '<!DOCTYPE a>');
    $report = validateBytes($bytes);

    expect(strlen($bytes))->toBe(3_000_001)
        ->and($report->result())->toBe(Result::Errors)
        ->and(findingIds($report))->toBe(['SEC-SIZE-LIMIT']);
});

it('reports a UTF-8 BOM and keeps checking', function () {
    expect(findingIds(validateBytes("\xEF\xBB\xBF<a/>")))->toBe(['TECH-UTF8-BOM', 'DOC-UNSUPPORTED'])
        ->and(findingIds(validateBytes("\xEF\xBB\xBF<?xml version=\"1.0\" encoding=\"latin2\"?><a/>")))
        ->toBe(['TECH-UTF8-BOM', 'TECH-PROLOG-ENCODING']);
});

it('rejects bytes that are not UTF-8', function (string $bytes) {
    $report = validateBytes($bytes);

    expect($report->result())->toBe(Result::Errors)
        ->and(findingIds($report))->toBe(['TECH-ENCODING-UTF8']);
})->with([
    'broken sequence' => "<a>\xC3\x28</a>",
    'latin2 text' => "<a>Zak\xB3ad</a>",
    'UTF-8 BOM then broken sequence' => "\xEF\xBB\xBF<a>\xC3\x28</a>",
    'NUL byte' => "<a>x\x00y</a>",
]);

it('rejects a prolog that declares another encoding', function (string $bytes) {
    $report = validateBytes($bytes);

    expect($report->result())->toBe(Result::Errors)
        ->and(findingIds($report))->toBe(['TECH-PROLOG-ENCODING']);
})->with([
    'windows-1250' => '<?xml version="1.0" encoding="windows-1250"?><a/>',
    'ISO-8859-2 in single quotes' => "<?xml version='1.0' encoding='ISO-8859-2'?><a/>",
    'spaces around equals sign' => '<?xml version="1.0" encoding = "UTF-16"?><a/>',
    'upper case XML' => '<?XML version="1.0" encoding="UTF-16"?><a/>',
]);

it('accepts a UTF-8 prolog or no prolog', function (string $bytes) {
    expect(findingIds(validateBytes($bytes)))->toBe(['DOC-UNSUPPORTED']);
})->with([
    'upper case' => '<?xml version="1.0" encoding="UTF-8"?><a/>',
    'lower case' => '<?xml version="1.0" encoding="utf-8"?><a/>',
    'without encoding' => '<?xml version="1.0"?><a/>',
    'no prolog' => '<a/>',
    'encoding text outside the prolog' => "<?xml version=\"1.0\"?>\n<a encoding=\"latin2\"/>",
]);

it('reports discouraged characters once with the first line', function (string $bytes, int $line, int $count) {
    $report = validateBytes($bytes);

    expect($report->result())->toBe(Result::Unsupported)
        ->and(findingIds($report))->toBe(['TECH-DISCOURAGED-CHAR', 'DOC-UNSUPPORTED'])
        ->and($report->findings[0]->line)->toBe($line)
        ->and($report->findings[0]->field)->toBeNull()
        ->and($report->findings[0]->message)->toContain("liczba wystąpień: {$count}");
})->with([
    'U+0080' => ["<a>x\u{0080}y</a>", 1, 1],
    'U+007F' => ["<a>\u{007F}</a>", 1, 1],
    'U+0084' => ["<a>\u{0084}</a>", 1, 1],
    'U+0086' => ["<a>\u{0086}</a>", 1, 1],
    'U+009F' => ["<a>\u{009F}</a>", 1, 1],
    'U+FDD0' => ["<a>\u{FDD0}</a>", 1, 1],
    'U+FDEF' => ["<a>\u{FDEF}</a>", 1, 1],
    'U+1FFFE' => ["<a>\u{1FFFE}</a>", 1, 1],
    'U+1FFFF' => ["<a>\u{1FFFF}</a>", 1, 1],
    'U+8FFFE' => ["<a>\u{8FFFE}</a>", 1, 1],
    'U+10FFFF' => ["<a>\u{10FFFF}</a>", 1, 1],
    'first on the third line' => ["<a>\n<b/>\n<c>\u{FDD0}</c>\n<d>\u{0080}\u{0081}</d></a>", 3, 3],
]);

it('allows characters next to the discouraged ranges', function (string $bytes) {
    expect(findingIds(validateBytes($bytes)))->toBe(['DOC-UNSUPPORTED']);
})->with([
    'U+007E' => "<a>\u{007E}</a>",
    'U+0085 NEL' => "<a>\u{0085}</a>",
    'U+00A0' => "<a>\u{00A0}</a>",
    'U+FDCF' => "<a>\u{FDCF}</a>",
    'U+FDF0' => "<a>\u{FDF0}</a>",
    'U+1FFFD' => "<a>\u{1FFFD}</a>",
    'U+20000' => "<a>\u{20000}</a>",
    'Polish letters' => '<a>Zażółć gęślą jaźń</a>',
]);

it('describes the document size and hash', function () {
    $bytes = "<a>\n</a>";
    $document = validateBytes($bytes)->document;

    expect($document->sizeBytes)->toBe(8)
        ->and($document->sha256Base64Url)->toBe(Sha256Base64Url::of($bytes))
        ->and($document->type)->toBeNull()
        ->and($document->variant)->toBeNull()
        ->and($document->schema)->toBeNull();
});

it('describes the document even when preflight stops', function () {
    $document = validateBytes('')->document;

    expect($document->sizeBytes)->toBe(0)
        ->and($document->sha256Base64Url)->toBe(Sha256Base64Url::of(''));
});

it('fills in the engine header and the schema source', function () {
    $schemas = new class implements SchemaRepository
    {
        public function fa3SchemaSource(): string
        {
            return '';
        }

        public function schemaDirectory(): string
        {
            return '/nonexistent';
        }

        public function source(): SchemaSource
        {
            return new SchemaSource('commit123', 'sha456', []);
        }
    };

    $validator = new Fa3Validator(schemas: $schemas);
    $report = $validator->validate('<a/>');

    expect($report->engineVersion)->toBe(Fa3Validator::ENGINE_VERSION)
        ->and($report->ruleset)->toBe(Fa3Validator::RULESET)
        ->and($report->source->ksefApiCommit)->toBe('commit123')
        ->and($validator->source())->toBe($report->source)
        ->and($report->skipped)->toBe([])
        ->and($report->unsupported)->toBeTrue();
});

it('stamps the report with the current engine version and ruleset', function () {
    $report = validateBytes('<a/>');

    expect($report->engineVersion)->toBe('0.2.0')
        ->and($report->ruleset)->toBe('fa3-1-0E-2026-11.2')
        ->and($report->toArray()['schemaVersion'])->toBe('1.0');
});

it('uses the local schema source by default', function () {
    expect(validateBytes('<a/>')->source)->toEqual((new LocalSchemaRepository)->source());
});

it('flags the last two code points of every supplementary plane', function () {
    $characters = '';

    for ($plane = 1; $plane <= 16; $plane++) {
        $characters .= mb_chr($plane * 0x10000 + 0xFFFE).mb_chr($plane * 0x10000 + 0xFFFF);
    }

    $report = validateBytes("<a>{$characters}</a>");

    expect(findingIds($report))->toBe(['TECH-DISCOURAGED-CHAR', 'DOC-UNSUPPORTED'])
        ->and($report->findings[0]->message)->toContain('liczba wystąpień: 32');
});
