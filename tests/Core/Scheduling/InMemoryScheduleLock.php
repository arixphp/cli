<?php

declare(strict_types=1);

namespace Tests\Core\Scheduling;

use Arix\Scheduling\Contracts\ScheduleLock;

class InMemoryScheduleLock implements ScheduleLock
{
    /** @var array<string, string> */
    private array $completed = [];

    /** @var array<string, bool> */
    private array $held = [];

    public function hold(string $event, string $occurrence): void
    {
        $this->held["{$event}|{$occurrence}"] = true;
    }

    public function acquire(string $event, string $occurrence): string|null
    {
        if (isset($this->held["{$event}|{$occurrence}"])) {
            return null;
        }

        if ($this->isCompleted($event, $occurrence)) {
            return null;
        }

        return 'token';
    }

    public function complete(string $event, string $occurrence, string $token): void
    {
        $this->completed[$event] = $occurrence;
    }

    public function release(string $event, string $occurrence, string $token): void
    {
        // Release the held lock for the given event and occurrence.
    }

    public function isCompleted(string $event, string $occurrence): bool
    {
        return isset($this->completed[$event]) && $this->completed[$event] >= $occurrence;
    }
}
