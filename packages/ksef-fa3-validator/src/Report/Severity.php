<?php

declare(strict_types=1);

namespace KsefFa3\Report;

enum Severity: string
{
    case Error = 'ERROR';
    case Warning = 'WARNING';
    case Info = 'INFO';
}
