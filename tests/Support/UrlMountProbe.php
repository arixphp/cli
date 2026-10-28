<?php

declare(strict_types=1);

namespace Tests\Support;

class UrlMountProbe
{
    public static function install(NewApplicationFixture $fixture): void
    {
        $autoload = TestApplication::prepareCore($fixture->path('sub'));
        $program = '<?php declare(strict_types=1); require ' . var_export($autoload, true) . ';' . <<<'PHP'
$handler = new class() implements \Arix\Contracts\RequestHandler {
    public function handle(\Arix\HttpFoundation\Request $request, \Arix\Container\Container $container): \Arix\HttpFoundation\Response {
        if ($request->attributes->get('_arix_path') !== '/about') {
            return new \Arix\HttpFoundation\Response('Not found', 404);
        }
        $context = $request->attributes->get('_arix_url_context');
        $urls = \Arix\Routing\UrlGenerator::fromRequest($request, $context);
        $assets = \Arix\Routing\UrlGenerator::fromRequest($request, $context, assets: true);
        return new \Arix\HttpFoundation\JsonResponse([
            'path' => $urls->currentPath(), 'url' => $urls->to('/about'),
            'asset' => $assets->to('/styles/app.css'),
            'script' => $request->server->get('SCRIPT_NAME'),
            'filename' => $request->server->get('SCRIPT_FILENAME'),
        ]);
    }
};
$kernel = new \Arix\Http\Kernel($handler);
exit((new \Arix\Runtime\HttpRuntime(__DIR__))->run(static fn () => $kernel));
PHP;
        $fixture->write('sub/index.php', $program);
        $fixture->write('sub/styles/app.css', 'body{}');
        $fixture->write('sub/.env', 'APP_SECRET=PRIVATE');
        $fixture->write('sub/.env.bak', 'PRIVATE');
        $fixture->write('sub/core/secret.php', 'PRIVATE');
    }
}
