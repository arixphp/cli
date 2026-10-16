<?php

declare(strict_types=1);

namespace App\Development;

class PortCandidates
{
    /** @return list<int> */
    public static function for(int $port, bool $strict): array
    {
        return range($port, $strict ? $port : min(65535, $port + 19));
    }
}
