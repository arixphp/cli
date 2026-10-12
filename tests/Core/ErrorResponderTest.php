<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Update\CoreFiles;
use Arix\Constants\HttpStatusCode;
use Arix\Http\ErrorResponder;
use Arix\Http\HttpException;
use Arix\HttpFoundation\Exception\BadRequestException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\CoreUpdateFixture;

class ErrorResponderTest extends TestCase
{
    #[Test]
    public function it_http_errors_render_html_and_preserve_headers(): void
    {
        $response = new ErrorResponder()->respond(new HttpException(
            HttpStatusCode::METHOD_NOT_ALLOWED,
            '<script>not allowed</script>',
            ['Allow' => 'GET, HEAD', 'Content-Type' => 'application/json', 'Cache-Control' => 'public'],
        ));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, HEAD', $response->headers->get('Allow'));
        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('&lt;script&gt;not allowed&lt;/script&gt;', $response->getContent());
        $this->assertStringNotContainsString('<script>', $response->getContent());
    }

    #[Test]
    #[DataProvider('debugModes')]
    public function it_request_exceptions_render_a_generic_400(bool $debug): void
    {
        $response = new ErrorResponder(debug: $debug)->respond(new BadRequestException('request secret'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<pre>Bad request.</pre>', $response->getContent());
        $this->assertStringNotContainsString('request secret', $response->getContent());
    }

    public static function debugModes(): array
    {

        return [[false], [true]];
    }

    #[Test]
    public function it_unexpected_production_errors_hide_diagnostics(): void
    {
        $logs = [];
        $responder = new ErrorResponder(logger: static function (string $message) use (&$logs): void {
            $logs[] = $message;
        });
        $response = $responder->respond(new RuntimeException('production secret'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<pre>Internal server error.</pre>', $response->getContent());
        $this->assertStringNotContainsString('production secret', $response->getContent());
        $this->assertStringContainsString('production secret', $logs[0]);
    }

    #[Test]
    public function it_debug_diagnostics_remain_plain_text(): void
    {
        $response = new ErrorResponder(debug: true, logger: static function (): void {})->respond(new RuntimeException('<script>debug</script>'));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<script>debug</script>', $response->getContent());
        $this->assertStringContainsString('RuntimeException', $response->getContent());
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    #[Test]
    #[DataProvider('standaloneResources')]
    public function it_handles_errors_without_composer_or_application_services(string $resource): void
    {
        $fixture = new CoreUpdateFixture();
        $source = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'core';

        try {
            foreach (CoreFiles::inventory($source, true) as $path => $hash) {
                if ($path !== 'stubs/error.html' || $resource === 'valid') {
                    $fixture->write("core/{$path}", file_get_contents(CoreFiles::local($source, $path)));
                }
            }

            if ($resource === 'empty') {
                $fixture->write('core/stubs/error.html', '');
            } elseif ($resource === 'malformed') {
                $fixture->write('core/stubs/error.html', '<html>{{status}}</html>');
            }

            $program = <<<'PHP'
require $argv[1];
$response = (new Arix\Http\ErrorResponder(logger: static function (): void {}))->respond(new RuntimeException('standalone secret'));
echo json_encode([
    'composer' => class_exists('Composer\Autoload\ClassLoader', false),
    'status' => $response->getStatusCode(),
    'type' => $response->headers->get('Content-Type'),
    'body' => $response->getContent(),
    'no_store' => $response->headers->hasCacheControlDirective('no-store'),
    'nosniff' => $response->headers->get('X-Content-Type-Options'),
], JSON_THROW_ON_ERROR);
PHP;
            $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $fixture->path('core/autoload.php')], $fixture->root, ['XDEBUG_MODE' => 'off']);
            $process->run();

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame('', $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($result['composer']);
            $this->assertSame(500, $result['status']);
            $this->assertTrue($result['no_store']);
            $this->assertSame('nosniff', $result['nosniff']);
            $this->assertStringNotContainsString('standalone secret', $result['body']);

            if ($resource === 'valid') {
                $this->assertSame('text/html; charset=UTF-8', $result['type']);
                $this->assertStringContainsString('<pre>Internal server error.</pre>', $result['body']);
            } else {
                $this->assertSame('text/plain; charset=UTF-8', $result['type']);
                $this->assertSame('Internal server error.', $result['body']);
            }
        } finally {
            $fixture->cleanup();
        }
    }

    public static function standaloneResources(): array
    {

        return [['valid'], ['missing'], ['empty'], ['malformed']];
    }
}
