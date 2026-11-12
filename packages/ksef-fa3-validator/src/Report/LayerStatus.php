<?php

declare(strict_types=1);

namespace KsefFa3\Report;

enum LayerStatus: string
{
    case Passed = 'passed';
    case Warnings = 'warnings';
    case Errors = 'errors';
    case Unsupported = 'unsupported';
    case NotRun = 'not_run';
    case NotChecked = 'not_checked';
}
