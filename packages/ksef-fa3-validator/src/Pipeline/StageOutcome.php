<?php

declare(strict_types=1);

namespace KsefFa3\Pipeline;

enum StageOutcome
{
    case Continue;
    case Stop;
}
