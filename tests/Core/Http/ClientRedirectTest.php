<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\TestApplication;

class ClientRedirectTest extends TestCase
{
    #[Test]
    #[DataProvider('sameOrigins')]
    public function it_recognizes_equivalent_origins_without_dropping_credentials(string $source, string $target): void
    {
        $result = $this->runScenario(['url' => $source, 'responses' => [['status' => 302, 'location' => $target], []]]);

        $this->assertNull($result['error']);
        $this->assertCount(2, $result['sent']);
        $this->assertSame('Bearer secret', $result['sent'][1]['headers']['Authorization']);
        $this->assertSame('session=secret', $result['sent'][1]['headers']['Cookie']);
        $this->assertSame('value', $result['sent'][1]['headers']['X-Secret']);
    }

    public static function sameOrigins(): array
    {

        return [
            ['HTTPS://Service.Test/start', 'https://service.test:443/final'],
            ['http://service.test:80/start', 'HTTP://SERVICE.TEST/final'],
            ['https://[2001:4860::1]/start', 'https://[2001:4860::1]:443/final'],
        ];
    }

    #[Test]
    #[DataProvider('differentOrigins')]
    public function it_strips_sensitive_headers_when_the_origin_changes(string $source, string $target): void
    {
        $result = $this->runScenario(['url' => $source, 'responses' => [['status' => 307, 'location' => $target], []]]);

        $this->assertNull($result['error']);
        $this->assertCount(2, $result['sent']);
        $this->assertSame(['User-Agent' => 'Arix', 'Content-Type' => 'application/json'], $result['sent'][1]['headers']);
        $this->assertSame('POST', $result['sent'][1]['method']);
        $this->assertSame('{"payload":"fixture"}', $result['sent'][1]['body']);
    }

    public static function differentOrigins(): array
    {

        return [
            ['https://service.test/start', 'https://service.test:8443/final'],
            ['https://service.test/start', 'https://other.test/final'],
            ['http://service.test/start', 'https://service.test/final'],
        ];
    }

    #[Test]
    public function it_never_connects_to_an_https_downgrade_destination(): void
    {
        $result = $this->runScenario(['url' => 'https://service.test/start', 'responses' => [['status' => 302, 'location' => 'http://service.test/final']]]);

        $this->assertSame('HTTPS redirects cannot downgrade to HTTP.', $result['error']);
        $this->assertCount(1, $result['sent']);
        $this->assertSame('https://service.test/start', $result['sent'][0]['url']);
    }

    #[Test]
    public function it_does_not_restore_credentials_when_a_chain_returns_to_the_first_origin(): void
    {
        $result = $this->runScenario([
            'url' => 'https://service.test/start',
            'responses' => [['status' => 302, 'location' => 'https://service.test:8443/next'], ['status' => 302, 'location' => 'https://service.test/final'], []],
        ]);

        $this->assertNull($result['error']);
        $this->assertCount(3, $result['sent']);
        $this->assertArrayNotHasKey('Authorization', $result['sent'][1]['headers']);
        $this->assertArrayNotHasKey('Authorization', $result['sent'][2]['headers']);
        $this->assertArrayNotHasKey('Cookie', $result['sent'][2]['headers']);
        $this->assertArrayNotHasKey('X-Secret', $result['sent'][2]['headers']);
    }

    #[Test]
    #[DataProvider('nonRedirectStatuses')]
    public function it_does_not_follow_location_on_non_redirect_responses(int $status): void
    {
        $result = $this->runScenario(['url' => 'https://service.test/start', 'responses' => [['status' => $status, 'location' => '/created'], []]]);

        $this->assertNull($result['error']);
        $this->assertSame($status, $result['status']);
        $this->assertCount(1, $result['sent']);
    }

    public static function nonRedirectStatuses(): array
    {
        return [[200], [201], [300], [304], [400]];
    }

    #[Test]
    #[DataProvider('redirectStatuses')]
    public function it_follows_supported_redirect_statuses(int $status): void
    {
        $result = $this->runScenario(['url' => 'https://service.test/start', 'responses' => [['status' => $status, 'location' => '/next'], []]]);

        $this->assertNull($result['error']);
        $this->assertSame('https://service.test/next', $result['sent'][1]['url']);
        $this->assertCount(2, $result['sent']);
    }

    public static function redirectStatuses(): array
    {
        return [[301], [302], [303], [307], [308]];
    }

    #[Test]
    #[DataProvider('unsupportedLocations')]
    public function it_rejects_unsupported_redirect_references_without_a_second_request(string $location): void
    {
        $result = $this->runScenario(['url' => 'https://service.test/path/start', 'responses' => [['status' => 302, 'location' => $location], []]]);

        $this->assertSame('The redirect location is not supported.', $result['error']);
        $this->assertCount(1, $result['sent']);
    }

    public static function unsupportedLocations(): array
    {
        return [['next'], ['../next'], ['?page=2'], ['#fragment'], ['//other.test/path']];
    }

    /** @return array<string, mixed> */
    private function runScenario(array $scenario): array
    {
        $root = dirname(__DIR__, 3);
        $fixture = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'HttpClient' . DIRECTORY_SEPARATOR . 'redirects.php';
        $autoload = TestApplication::shared()->path('core/autoload.php');
        $process = new Process([PHP_BINARY, $fixture, $autoload, json_encode($scenario, JSON_THROW_ON_ERROR)], env: ['XDEBUG_MODE' => 'off']);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('', $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
