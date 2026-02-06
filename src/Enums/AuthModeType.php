<?php

declare(strict_types=1);

namespace ValcuAndrei\PestE2E\Enums;

enum AuthModeType: string
{
    case SESSION = 'session';
    case SANCTUM = 'sanctum';
}
