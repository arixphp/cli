<?php

declare(strict_types=1);

namespace Tests\Unit\Installation;

use App\Installation\ApplicationPublisher;
use App\Installation\InstallationTarget;
use App\Installation\RecoveryFailed;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\NewApplicationFixture;

class ApplicationPublisherTest extends TestCase
{
    private NewApplicationFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new NewApplicationFixture();
        $this->fixture->write('workspace/application/index.php', 'prepared');
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanup();
    }

    #[Test]
    public function it_publishes_into_an_absent_destination(): void
    {
        $target = InstallationTarget::resolve('app', '.', $this->fixture->root);
        new ApplicationPublisher()->publish($target, $this->fixture->path('workspace'));
        $this->assertSame('prepared', file_get_contents($this->fixture->path('app/index.php')));
        $this->assertDirectoryDoesNotExist($this->fixture->path('workspace/application'));
    }

    #[Test]
    public function it_restores_the_original_empty_directory_on_publish_failure(): void
    {
        mkdir($this->fixture->path('app'), 0700);
        $target = InstallationTarget::resolve('app', '.', $this->fixture->root);
        $publisher = new class() extends ApplicationPublisher
        {
            protected function move(string $source, string $destination): bool
            {

                return basename($source) !== 'application' && parent::move($source, $destination);
            }
        };

        try {
            $publisher->publish($target, $this->fixture->path('workspace'));
            $this->fail('Publication failure not reported.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('publish', $exception->getMessage());
            $this->assertSame(['.', '..'], scandir($target->path));
            $this->assertSame($target->identity['ino'], lstat($target->path)['ino']);
            $this->assertSame(0700, fileperms($target->path) & 0777);
        }
    }

    #[Test]
    public function it_retains_recovery_evidence_when_a_third_party_occupies_the_target(): void
    {
        mkdir($this->fixture->path('app'), 0700);
        $target = InstallationTarget::resolve('app', '.', $this->fixture->root);
        $publisher = new class() extends ApplicationPublisher
        {
            protected function move(string $source, string $destination): bool
            {
                if (basename($source) === 'application') {
                    mkdir($destination);
                    file_put_contents($destination . DIRECTORY_SEPARATOR . 'keep', 'third party');

                    return false;
                }

                return parent::move($source, $destination);
            }
        };

        try {
            $publisher->publish($target, $this->fixture->path('workspace'));
            $this->fail('Recovery failure not reported.');
        } catch (RecoveryFailed $exception) {
            $this->assertStringContainsString($this->fixture->path('workspace'), $exception->getMessage());
            $this->assertDirectoryExists($this->fixture->path('workspace/previous'));
            $this->assertSame('third party', file_get_contents($this->fixture->path('app/keep')));
        }
    }
}
