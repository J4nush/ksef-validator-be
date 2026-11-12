<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Support\Facades\Log;
use Throwable;

final class SafeExceptionReport
{
    public static function log(Throwable $exception): false
    {
        // Messages and call arguments can quote the invoice, so only the location is logged.
        Log::error('Unhandled '.$exception::class, [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => array_map(
                fn (array $frame): string => sprintf(
                    '%s:%s %s%s%s',
                    $frame['file'] ?? '[internal]',
                    $frame['line'] ?? '-',
                    $frame['class'] ?? '',
                    $frame['type'] ?? '',
                    $frame['function'],
                ),
                $exception->getTrace(),
            ),
        ]);

        return false;
    }
}
