<?php

declare(strict_types=1);

use App\Exceptions\SafeExceptionReport;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\Response;

covers(SafeExceptionReport::class);

function captureLogs(): TestHandler
{
    $handler = new TestHandler;
    config([
        'logging.default' => 'capture',
        'logging.channels.capture' => ['driver' => 'custom', 'via' => fn (): Logger => new Logger('capture', [$handler])],
    ]);

    return $handler;
}

function capturedLogs(TestHandler $handler): string
{
    return implode("\n", array_map(
        fn (LogRecord $record): string => (is_string($record->formatted) ? $record->formatted : '').serialize($record->context).serialize($record->extra),
        $handler->getRecords(),
    ));
}

it('keeps invoice content out of the logs on every path', function (Closure $send, int $status) {
    $logs = captureLogs();

    /** @var TestResponse<Response> $response */
    $response = $send();

    expect($response->getStatusCode())->toBe($status);
    expect(capturedLogs($logs))->not->toContain(LOG_MARKER);
    expect(capturedLogs($logs))->not->toContain('Przykładowy Sprzedawca');
})->with([
    'validated' => [fn () => postInvoice(markedInvoice()), 200],
    'too large' => [fn () => postInvoice(markedInvoice().str_repeat(' ', 3_000_000)), 413],
    'wrong type' => [fn () => postInvoice(markedInvoice(), 'text/plain'), 415],
    'rate limited' => [function () {
        config(['ksef.rate_limit.per_minute' => 1]);
        postInvoice(markedInvoice());

        return postInvoice(markedInvoice());
    }, 429],
    'engine failure' => [function () {
        failingEngine();

        return postInvoice(markedInvoice());
    }, 500],
]);

it('still reports an engine failure, by class and location only', function () {
    $logs = captureLogs();
    failingEngine();

    postInvoice(markedInvoice())->assertStatus(500);

    $records = $logs->getRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]->level->getName())->toBe('ERROR')
        ->and($records[0]->message)->toBe('Unhandled RuntimeException')
        ->and($records[0]->context['file'])->toBeString()
        ->and($records[0]->context['line'])->toBeInt()
        ->and($records[0]->context['trace'])->toBeArray();
    expect($records[0]->context['trace'])->toContain(base_path('app/Http/Controllers/ValidateInvoiceController.php').':16 KsefFa3\\Fa3Validator->validate');
    expect(capturedLogs($logs))->not->toContain('Engine failed');
});

it('does not log a filename sent in a header', function () {
    $logs = captureLogs();
    $server = ['HTTP_CONTENT_DISPOSITION' => 'attachment; filename="'.LOG_MARKER.'.xml"'];

    postInvoice(markedInvoice(), server: $server)->assertOk();
    failingEngine();
    postInvoice(markedInvoice(), server: $server)->assertStatus(500);

    expect($logs->getRecords())->toHaveCount(1);
    expect(capturedLogs($logs))->not->toContain(LOG_MARKER);
});
