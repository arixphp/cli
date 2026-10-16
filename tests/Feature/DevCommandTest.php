<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DevCommandTest extends TestCase
{
    #[Test]
    public function it_rejects_missing_applications_without_downloading(): void
    {
        $this->artisan('dev', ['--path' => '/missing-arix-dev-application'])
            ->expectsOutputToContain('Missing readable directory')
            ->assertExitCode(2);
    }
}
