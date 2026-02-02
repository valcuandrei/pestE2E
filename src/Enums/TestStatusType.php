<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Enums;

enum TestStatusType: string
{
    case PASSED = 'passed';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}
