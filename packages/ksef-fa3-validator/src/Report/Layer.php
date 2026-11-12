<?php

declare(strict_types=1);

namespace KsefFa3\Report;

enum Layer: string
{
    case Security = 'security';
    case Recognition = 'recognition';
    case Schema = 'schema';
    case Rules = 'rules';
    case Amounts = 'amounts';
}
