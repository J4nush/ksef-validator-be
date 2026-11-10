<?php

declare(strict_types=1);

namespace KsefFa3\Report;

enum Result: string
{
    case Passed = 'PASSED';
    case Warnings = 'WARNINGS';
    case Errors = 'ERRORS';
    case Unsupported = 'UNSUPPORTED';
}
