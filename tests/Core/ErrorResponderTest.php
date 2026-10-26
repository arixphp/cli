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
use Tests\Support\StandaloneCore;

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
    public function it_debug_diagnostics_do_not_disclose_the_call_chain(): void
    {
        $response = new ErrorResponder(debug: true, logger: static function (): void {})->respond(new RuntimeException('debug secret'));
        $body = (string) $response->getContent();

        $this->assertStringContainsString('debug secret', $body);
        $this->assertStringNotContainsString('Stack trace', $body);
        $this->assertStringNotContainsString(dirname(__DIR__, 2), $body);
        $this->assertStringNotContainsString('#0 ', $body);
    }

    #[Test]
    #[DataProvider('rendererFailures')]
    public function it_discards_incidental_output_from_custom_renderers(bool $fails): void
    {
        $level = ob_get_level();
        $output = '';
        ob_start(static function (string $buffer) use (&$output): string {
            $output .= $buffer;

            return '';
        });

        try {
            $response = new ErrorResponder()->respond(new HttpException(HttpStatusCode::NOT_FOUND, 'Missing.'), static function () use ($fails): string {
                echo 'renderer secret';
                ob_flush();

                if ($fails) {
                    throw new RuntimeException('render failed');
                }

                return '<main>Custom 404</main>';
            });
            ob_end_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $this->assertSame('', $output);
        $this->assertSame(404, $response->getStatusCode());

        if ($fails) {
            $this->assertStringContainsString('<pre>Missing.</pre>', $response->getContent());
        } else {
            $this->assertSame('<main>Custom 404</main>', $response->getContent());
        }
    }

    public static function rendererFailures(): array
    {

        return [[false], [true]];
    }

    #[Test]
    #[DataProvider('standaloneResources')]
    public function it_handles_errors_without_composer_or_application_services(string $resource, bool $viewFails = false): void
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
$renderView = $argv[2] === 'fail' ? static function (int $status, string $message): string { throw new RuntimeException('template secret'); } : null;
$response = (new Arix\Http\ErrorResponder(logger: static function (): void {}))->respond(new RuntimeException('standalone secret'), $renderView);
echo json_encode([
    'composer' => class_exists('Composer\Autoload\ClassLoader', false),
    'status' => $response->getStatusCode(),
    'type' => $response->headers->get('Content-Type'),
    'body' => $response->getContent(),
    'no_store' => $response->headers->hasCacheControlDirective('no-store'),
    'nosniff' => $response->headers->get('X-Content-Type-Options'),
], JSON_THROW_ON_ERROR);
PHP;
            $process = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, StandaloneCore::autoload($fixture->root), $viewFails ? 'fail' : 'none'], $fixture->root, ['XDEBUG_MODE' => 'off']);
            $process->run();

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame('', $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($result['composer']);
            $this->assertSame(500, $result['status']);
            $this->assertTrue($result['no_store']);
            $this->assertSame('nosniff', $result['nosniff']);
            $this->assertStringNotContainsString('standalone secret', $result['body']);
            $this->assertStringNotContainsString('template secret', $result['body']);

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

        return [['valid'], ['missing'], ['empty'], ['malformed'], ['valid', true], ['missing', true], ['empty', true], ['malformed', true]];
    }
}
