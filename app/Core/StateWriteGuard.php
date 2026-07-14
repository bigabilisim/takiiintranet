<?php

declare(strict_types=1);

namespace App\Core;

final class StateWriteGuard
{
    private bool $released = false;

    public function __construct(
        private readonly StateStore $store,
        private readonly string $documentKey,
    ) {
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        $this->store->releaseWrite($this->documentKey);
    }

    public function __destruct()
    {
        $this->release();
    }
}
