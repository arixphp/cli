<?php

declare(strict_types=1);

namespace Tests\Support;

class PublicResourceCorpus
{
    /** @return list<string> */
    public static function deniedPaths(): array
    {
        return [
            '/assets/logo.webp', '/ASSETS/logo.webp', '/core/Assets/public-policy.json',
            '/CoRe/autoload.php', '/.frontend-backup-x/index.js', '/phpunit.xml',
            '/fonts/index.php', '/fonts/probe.phtml', '/media/probe.cgi', '/media/index.php',
            '/media/probe.php.webp', '/media/logo.svg', '/media/logo.webp.map',
            '/media/.secret.webp', '/media/nested/.hidden/logo.webp', '/media/',
            '/media/nested/', '/fonts/', '/media/a%2fb.webp', '/media/a%5cb.webp',
            '/media/%252e%252e/private.webp', '/media/../core/secret.webp',
            '/media/%2e%2e/core/secret.webp', '/media/%00.webp', '/media/%zz.webp',
            '/Media/logo.webp', '/fonts/logo.png', '/media/inter.woff2',
            '/.env', '/.env.local', '/.env.production', '/.env.bak', '/.env.example',
            '/nested/.env', '/%2eenv', '/%2Eenv.production', '/%252eenv', '/nested/%2eenv',
        ];
    }
}
