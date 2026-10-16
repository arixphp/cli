<?php

declare(strict_types=1);

namespace Arix\Views;

use Arix\Container\Container;
use Arix\HttpFoundation\Request;
use Arix\Localization\Translator;
use Arix\Routing\UrlGenerator;
use Arix\Support\AssetVersioner;

class ViewServices
{
    public static function register(Container $container, string $root, Request $request, array $configuration): void
    {
        $config = ViewServicesConfig::resolve($root, $request, $configuration);

        $container->instance(Translator::class, new Translator($config->lang, $config->locales, $config->defaultLocale, $config->defaultLocale));
        $container->instance(UrlGenerator::class, new UrlGenerator($config->basePath, $config->requestPath));
        $container->instance(AssetVersioner::class, new AssetVersioner($config->base, $container->get(UrlGenerator::class)));
        $container->instance(ViewFactory::class, new ViewFactory());

        $engine = new TemplateEngine($config->views, $config->cachePath, TemplateEngine::VERSION . "\0" . $config->cacheVersion);

        $container->instance(TemplateEngine::class, $engine);
        $container->instance(ErrorViewRenderer::class, new ErrorViewRenderer($engine, $config->appName));
    }
}
