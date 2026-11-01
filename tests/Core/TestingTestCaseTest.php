<?php

declare(strict_types=1);

namespace Tests\Core;

use Arix\Constants\HttpMethod;
use Arix\Testing\TestCase as ApplicationTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TempTree;

class TestingTestCaseTest extends TestCase
{
    private TempTree $tree;

    private ApplicationTestCase $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = new TempTree();
        $this->application = new class('fixture') extends ApplicationTestCase
        {
            public string $root;

            protected function applicationRoot(): string
            {
                return $this->root;
            }
        };
        $this->application->root = $this->tree->root;
    }

    protected function tearDown(): void
    {
        $this->tree->cleanup();
        parent::tearDown();
    }

    #[Test]
    public function it_runs_bootstrap_and_dispatch_inside_the_application_environment(): void
    {
        $this->tree->write('.env', "APP_CASE_PROBE=from-file\nAPP_CASE_ABSENT=temporary\n");
        $this->tree->write('app/bootstrap.php', <<<'PHP'
<?php
$bootstrapValue = $_ENV['APP_CASE_PROBE'] ?? 'missing';
return new class($bootstrapValue) extends \Arix\Http\Kernel {
    public function __construct(private string $bootstrapValue) {}
    public function handle(\Arix\HttpFoundation\Request $request): \Arix\HttpFoundation\Response {
        return new \Arix\HttpFoundation\Response($this->bootstrapValue . ':' . ($_ENV['APP_CASE_PROBE'] ?? 'missing') . ':' . $request->getMethod());
    }
};
PHP);
        $previous = $_ENV;
        unset($_ENV['APP_CASE_PROBE'], $_ENV['APP_CASE_ABSENT']);

        try {
            $this->assertSame('from-file:from-file:GET', $this->application->get('/')->response->getContent());
            $this->assertSame('from-file', $_ENV['APP_CASE_PROBE']);
            $this->assertSame('temporary', $_ENV['APP_CASE_ABSENT']);
            $this->assertSame('from-file:from-file:POST', $this->application->post('/')->response->getContent());
            $this->assertSame('from-file', $_ENV['APP_CASE_PROBE']);
        } finally {
            $_ENV = $previous;
        }
    }

    #[Test]
    public function it_keeps_the_loaded_environment_after_a_bootstrap_exception(): void
    {
        $this->tree->write('.env', 'APP_CASE_PROBE=from-file');
        $this->tree->write('app/bootstrap.php', '<?php throw new RuntimeException($_ENV["APP_CASE_PROBE"]);');
        $previous = $_ENV;
        unset($_ENV['APP_CASE_PROBE']);

        try {
            try {
                $this->application->get('/');
                $this->fail('Expected a bootstrap exception.');
            } catch (RuntimeException $exception) {
                $this->assertSame('from-file', $exception->getMessage());
                $this->assertSame('from-file', $_ENV['APP_CASE_PROBE']);
            }
        } finally {
            $_ENV = $previous;
        }
    }

    #[Test]
    public function it_rejects_invalid_bootstrap_results(): void
    {
        $this->tree->write('app/bootstrap.php', '<?php return null;');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Application bootstrap must return');
        $this->application->get('/');
    }

    #[Test]
    public function it_rejects_a_missing_bootstrap(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Application bootstrap');
        $this->application->get('/');
    }

    #[Test]
    public function it_rejects_an_invalid_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->application->call(HttpMethod::GET, 'invalid');
    }

    #[Test]
    public function it_rejects_a_linked_bootstrap(): void
    {
        $source = $this->tree->write('outside.php', '<?php throw new RuntimeException("executed");');
        mkdir($this->tree->path('app'));

        if (! @symlink($source, $this->tree->path('app/bootstrap.php'))) {
            $this->markTestSkipped('Symbolic links are unavailable.');
        }

        $this->expectException(RuntimeException::class);
        $this->application->get('/');
    }

    #[Test]
    public function it_runs_the_middleware_registered_by_the_bootstrap(): void
    {
        $this->tree->write('app/bootstrap.php', <<<'PHP'
<?php
$middleware = new class implements \Arix\Contracts\Middleware {
    public function handle(\Arix\HttpFoundation\Request $request, \Closure $next): \Arix\HttpFoundation\Response {
        $response = $next($request);
        $response->headers->set('X-Bootstrap', 'yes');
        return $response;
    }
};
return new \Arix\Http\Kernel(
    new class implements \Arix\Contracts\RequestHandler {
        public function handle(\Arix\HttpFoundation\Request $request, \Arix\Container\Container $container): \Arix\HttpFoundation\Response {
            return new \Arix\HttpFoundation\Response($request->getMethod());
        }
    },
    configure: static function (\Arix\Container\Container $container) use ($middleware): void {
        $container->instance($middleware::class, $middleware);
    },
    middleware: [$middleware::class],
);
PHP);

        foreach (['get' => 'GET', 'post' => 'POST', 'put' => 'PUT', 'patch' => 'PATCH', 'delete' => 'DELETE'] as $method => $expected) {
            $response = $this->application->{$method}('/')->response;
            $this->assertSame($expected, $response->getContent());
            $this->assertSame('yes', $response->headers->get('X-Bootstrap'));
        }
    }
}
