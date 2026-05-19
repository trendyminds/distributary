<?php

namespace Trendyminds\Distributary\Support;

enum ImportStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Complete = 'complete';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Complete || $this === self::Failed;
    }
}
