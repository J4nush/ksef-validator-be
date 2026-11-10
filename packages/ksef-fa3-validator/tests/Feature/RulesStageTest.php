<?php

declare(strict_types=1);

use KsefFa3\Catalog\FindingFactory;
use KsefFa3\Catalog\RuleCatalog;
use KsefFa3\Clock\FixedClock;
use KsefFa3\Fa3Validator;
use KsefFa3\Pipeline\StageOutcome;
use KsefFa3\Pipeline\ValidationContext;
use KsefFa3\Report\Result;
use KsefFa3\Report\Severity;
use KsefFa3\Rule\AttachmentBatchOnlyRule;
use KsefFa3\Rule\IssueDateNotInFutureRule;
use KsefFa3\Rule\LineNumberDuplicateRule;
use KsefFa3\Rule\NipChecksumRule;
use KsefFa3\Rule\ProcessingInstructionRule;
use KsefFa3\Rule\Rule;
use KsefFa3\Rule\RuleRegistry;
use KsefFa3\Rule\SizeWithoutAttachmentRule;
use KsefFa3\Rule\Violation;
use KsefFa3\Stage\RulesStage;
use KsefFa3\Xml\InvoiceDocument;

covers(RulesStage::class, RuleRegistry::class, Violation::class, Fa3Validator::class);

function rulesStage(Rule ...$rules): RulesStage
{
    return new RulesStage(new FindingFactory(RuleCatalog::fromDirectory(rulesPath())), new RuleRegistry(array_values($rules)));
}

final class RecordingRule implements Rule
{
    public ?string $bytes = null;

    /** @var list<Violation> */
    private array $violations;

    public function __construct(Violation ...$violations)
    {
        $this->violations = array_values($violations);
    }

    public function check(InvoiceDocument $document, string $bytes): iterable
    {
        $this->bytes = $bytes;

        yield from $this->violations;
    }
}

it('turns violations from every rule into findings', function () {
    $first = new RecordingRule(new Violation('TECH-DISCOURAGED-CHAR', 'Fa/P_1', 7, ['count' => 3]));
    $second = new RecordingRule(new Violation('TECH-NIP-CHECKSUM'), new Violation('TECH-LINE-NUMBER-DUPLICATE', line: 9));
    $context = new ValidationContext('<bytes/>');
    $context->invoice = ruleDocument(fa3Xml('<Naglowek/>'));

    expect(rulesStage($first, $second)->process($context))->toBe(StageOutcome::Continue);

    $findings = $context->findings();

    expect($first->bytes)->toBe('<bytes/>')
        ->and($second->bytes)->toBe('<bytes/>')
        ->and(array_map(fn ($finding): array => [$finding->id, $finding->severity, $finding->field, $finding->line], $findings))->toBe([
            ['TECH-DISCOURAGED-CHAR', Severity::Error, 'Fa/P_1', 7],
            ['TECH-NIP-CHECKSUM', Severity::Error, null, null],
            ['TECH-LINE-NUMBER-DUPLICATE', Severity::Warning, null, 9],
        ])
        ->and($findings[0]->message)->toContain('liczba wystąpień: 3');
});

it('skips a context without a recognized invoice', function () {
    $rule = new RecordingRule(new Violation('TECH-NIP-CHECKSUM'));
    $context = new ValidationContext('<a/>');
    $context->document = new DOMDocument;
    $context->document->loadXML('<a/>');

    expect(rulesStage($rule)->process($context))->toBe(StageOutcome::Continue)
        ->and($context->findings())->toBe([])
        ->and($rule->bytes)->toBeNull();
});

it('registers the six technical rules', function () {
    $rules = RuleRegistry::default(FixedClock::at('2026-11-10T12:00:00Z'))->all();

    expect(array_map(fn (Rule $rule): string => $rule::class, $rules))->toBe([
        ProcessingInstructionRule::class,
        SizeWithoutAttachmentRule::class,
        AttachmentBatchOnlyRule::class,
        NipChecksumRule::class,
        IssueDateNotInFutureRule::class,
        LineNumberDuplicateRule::class,
    ]);
});

it('emits only ids from the catalog', function () {
    $catalog = RuleCatalog::fromDirectory(rulesPath());
    $rules = RuleRegistry::default(FixedClock::at('2026-11-10T12:00:00Z'))->all();
    $everything = fa3Xml(
        '<?foo bar?><Podmiot1><DaneIdentyfikacyjne><NIP>1111111112</NIP></DaneIdentyfikacyjne></Podmiot1>'
        .'<Podmiot3><DaneIdentyfikacyjne><IDWew>1111111112-00001</IDWew></DaneIdentyfikacyjne></Podmiot3>'
        .'<Fa><P_1>2027-01-01</P_1><FaWiersz><NrWierszaFa>1</NrWierszaFa></FaWiersz><FaWiersz><NrWierszaFa>1</NrWierszaFa></FaWiersz></Fa>'
    );
    $ids = [];

    foreach ($rules as $rule) {
        foreach ([[$everything, str_pad($everything, 1_000_001)], [fa3Xml('<Zalacznik/>'), fa3Xml('<Zalacznik/>')]] as [$xml, $bytes]) {
            foreach (violationsOf($rule, $xml, $bytes) as $violation) {
                $ids[] = $violation->id;
            }
        }
    }

    $ids = array_values(array_unique($ids));
    sort($ids);

    expect($ids)->toBe([
        'TECH-ATTACHMENT-BATCH-ONLY',
        'TECH-INTERNAL-ID-CHECKSUM',
        'TECH-LINE-NUMBER-DUPLICATE',
        'TECH-NIP-CHECKSUM',
        'TECH-P1-FUTURE',
        'TECH-PROCESSING-INSTRUCTION',
        'TECH-SIZE-WITHOUT-ATTACHMENT',
    ])->and(array_diff($ids, $catalog->ids()))->toBe([]);
});

it('passes the minimal valid invoice through the rules', function () {
    $report = (new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z')))->validate(fixtureBytes('valid-minimal.xml'));

    expect(findingIds($report))->toBe([])
        ->and($report->result())->toBe(Result::Passed);
});

it('reports a seller NIP with a wrong check digit end to end', function () {
    $minimal = fixtureBytes('valid-minimal.xml');
    $bytes = str_replace('<NIP>1111111111</NIP>', '<NIP>1111111112</NIP>', $minimal);
    $report = (new Fa3Validator(FixedClock::at('2026-11-10T12:00:00Z')))->validate($bytes);

    expect($bytes)->not->toBe($minimal)
        ->and(findingIds($report))->toBe(['TECH-NIP-CHECKSUM'])
        ->and($report->findings[0]->field)->toBe('Podmiot1/DaneIdentyfikacyjne/NIP')
        ->and($report->findings[0]->line)->toBe(10)
        ->and($report->result())->toBe(Result::Errors);
});

it('uses the clock given to the validator', function () {
    $bytes = str_replace('<P_1>2026-02-15</P_1>', '<P_1>2026-11-11</P_1>', fixtureBytes('valid-minimal.xml'));

    expect(findingIds((new Fa3Validator(FixedClock::at('2026-11-10T22:59:59Z')))->validate($bytes)))->toBe(['TECH-P1-FUTURE'])
        ->and(findingIds((new Fa3Validator(FixedClock::at('2026-11-10T23:00:00Z')))->validate($bytes)))->toBe([]);
});

it('shows at most 100 findings per rule id and says how many there were', function (int $total, ?string $summary) {
    $violations = array_map(fn (int $line): Violation => new Violation('TECH-NIP-CHECKSUM', line: $line), range(1, $total));
    $rule = new RecordingRule(new Violation('TECH-P1-FUTURE'), ...$violations);
    $context = new ValidationContext('<bytes/>');
    $context->invoice = ruleDocument(fa3Xml('<Naglowek/>'));

    rulesStage($rule, new RecordingRule(new Violation('TECH-LINE-NUMBER-DUPLICATE')))->process($context);

    $findings = $context->findings();
    $ids = array_map(fn ($finding): string => $finding->id, $findings);
    $truncated = array_values(array_filter($findings, fn ($finding): bool => $finding->id === 'RULE-TRUNCATED'));

    expect(array_count_values($ids))->toMatchArray(['TECH-P1-FUTURE' => 1, 'TECH-NIP-CHECKSUM' => min($total, 100), 'TECH-LINE-NUMBER-DUPLICATE' => 1])
        ->and($findings[100]->line ?? null)->toBe($total >= 100 ? 100 : null)
        ->and(array_map(fn ($finding): string => $finding->message, $truncated))->toBe($summary === null ? [] : [$summary]);

    if ($summary !== null) {
        expect($ids[102])->toBe('RULE-TRUNCATED')
            ->and($truncated[0]->severity)->toBe(Severity::Info)
            ->and($truncated[0]->line)->toBeNull()
            ->and($truncated[0]->field)->toBeNull();
    }
})->with([
    '99' => [99, null],
    '100' => [100, null],
    '101' => [101, 'Pokazano 100 z 101 uwag TECH-NIP-CHECKSUM.'],
    '250' => [250, 'Pokazano 100 z 250 uwag TECH-NIP-CHECKSUM.'],
]);

it('counts the cap separately for every rule id', function () {
    $nips = array_map(fn (): Violation => new Violation('TECH-NIP-CHECKSUM'), range(1, 101));
    $ids = array_map(fn (): Violation => new Violation('TECH-INTERNAL-ID-CHECKSUM'), range(1, 102));
    $context = new ValidationContext('<bytes/>');
    $context->invoice = ruleDocument(fa3Xml('<Naglowek/>'));

    rulesStage(new RecordingRule(...$nips, ...$ids))->process($context);

    $truncated = array_values(array_filter($context->findings(), fn ($finding): bool => $finding->id === 'RULE-TRUNCATED'));

    expect(array_count_values(array_map(fn ($finding): string => $finding->id, $context->findings())))
        ->toBe(['TECH-NIP-CHECKSUM' => 100, 'TECH-INTERNAL-ID-CHECKSUM' => 100, 'RULE-TRUNCATED' => 2])
        ->and(array_map(fn ($finding): string => $finding->message, $truncated))->toBe([
            'Pokazano 100 z 101 uwag TECH-NIP-CHECKSUM.',
            'Pokazano 100 z 102 uwag TECH-INTERNAL-ID-CHECKSUM.',
        ]);
});
