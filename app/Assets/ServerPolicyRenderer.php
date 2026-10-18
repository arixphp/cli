<?php

declare(strict_types=1);

namespace App\Assets;

use Arix\Assets\AssetPolicy;
use InvalidArgumentException;

class ServerPolicyRenderer
{
    public function __construct(private readonly AssetPolicy $policy) {}

    public function render(string $server): string
    {
        $body = match ($server) {
            'apache' => $this->apache(),
            'caddy' => $this->caddy(),
            'iis' => $this->iis(),
            default => throw new InvalidArgumentException('Unsupported server policy format.'),
        };

        [$start, $end] = $this->markers($server);

        return "{$start}\n{$body}\n{$end}";
    }

    public function matches(string $server, string $configuration): bool
    {
        [$start, $end] = $this->markers($server);

        if (substr_count($configuration, $start) !== 1 || substr_count($configuration, $end) !== 1) {

            return false;
        }

        $configuration = str_replace("\r\n", "\n", $configuration);
        $offset = strpos($configuration, $start);
        $finish = strpos($configuration, $end);

        $normalize = static fn (string $value): array => array_map('trim', explode("\n", $value));

        return $finish >= $offset && $normalize(substr($configuration, $offset, $finish - $offset + strlen($end))) === $normalize($this->render($server));
    }

    private function markers(string $server): array
    {

        return $server === 'iis'
            ? ['<!-- ARIX ASSET POLICY BEGIN -->', '<!-- ARIX ASSET POLICY END -->']
            : ['# ARIX ASSET POLICY BEGIN', '# ARIX ASSET POLICY END'];
    }

    private function caddy(): string
    {
        $public = implode('|', $this->policy->patterns());
        $directories = implode('|', array_keys($this->policy->directories()));

        return <<<CADDY
@private path_regexp arix_private `(?i)(?:^/(?:app|core|cli|tests|docs|storage|assets)(?:/|$)|^/phpunit\\.xml(?:\\.dist)?$|(?:^|/)\\.)`
respond @private 403
@resource_method {
    path_regexp arix_resource_method `(?i)^/(?:{$directories})(?:/|$)`
    not method GET HEAD
}
header @resource_method Allow "GET, HEAD"
respond @resource_method 405
header X-Content-Type-Options nosniff
@public_files path_regexp arix_public `^/(?:{$public})$`
file_server @public_files {
    pass_thru
}
@public_directories path_regexp arix_public_directories `(?i)^/(?:{$directories})(?:/|$)`
respond @public_directories 403
@robots {
    path /robots.txt
    file
}
file_server @robots
CADDY;
    }

    private function apache(): string
    {
        $public = $this->policy->relativePattern();
        $directories = implode('|', array_keys($this->policy->directories()));

        return <<<APACHE
RewriteEngine On
RewriteRule ^(?:app|core|cli|tests|docs|storage|assets)(?:/|$) - [F,L,NC]
RewriteRule (^|/)\\. - [F,L]
RewriteRule ^phpunit\\.xml(?:\\.dist)?$ - [F,L,NC]
RewriteRule ^(?:{$directories})(?:/|$) - [E=ARIX_ASSET:1,NC]
RewriteCond %{REQUEST_METHOD} !^(?:GET|HEAD)$
RewriteRule ^(?:{$directories})(?:/|$) - [R=405,L,NC]
Header onsuccess unset Allow env=ARIX_ASSET
Header always set Allow "GET, HEAD" env=ARIX_ASSET
Header always set X-Content-Type-Options nosniff
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^(?:index\\.php|robots\\.txt|{$public})$ - [L]
RewriteCond %{ENV:ARIX_ASSET} =1 [OR]
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^ - [F,L]
APACHE;
    }

    private function iis(): string
    {
        $public = htmlspecialchars($this->policy->relativePattern(), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $directories = implode('|', array_keys($this->policy->directories()));

        return <<<XML
<rule name="Arix deny private files" stopProcessing="true">
  <match url="(?:^(?:app|core|cli|tests|docs|storage|assets)(?:/|$)|^phpunit\\.xml(?:\\.dist)?$|(?:^|/)\\.)" ignoreCase="true" />
  <action type="CustomResponse" statusCode="403" statusReason="Forbidden" statusDescription="Forbidden" />
</rule>
<rule name="Arix deny invalid public assets" stopProcessing="true">
  <match url="^((?:{$directories})(?:/.*)?)$" ignoreCase="true" />
  <conditions><add input="{R:1}" pattern="^{$public}$" negate="true" ignoreCase="false" /></conditions>
  <action type="CustomResponse" statusCode="403" statusReason="Forbidden" statusDescription="Forbidden" />
</rule>
<rule name="Arix deny unknown existing files" stopProcessing="true">
  <match url="^(?!(?:index\\.php|robots\\.txt|{$public})$).*" ignoreCase="false" />
  <conditions><add input="{REQUEST_FILENAME}" matchType="IsFile" /></conditions>
  <action type="CustomResponse" statusCode="403" statusReason="Forbidden" statusDescription="Forbidden" />
</rule>
<rule name="Arix public resource methods" stopProcessing="true">
  <match url="^(?:{$directories})(?:/|$)" ignoreCase="true" />
  <conditions><add input="{REQUEST_METHOD}" pattern="^(GET|HEAD)$" negate="true" /></conditions>
  <action type="CustomResponse" statusCode="405" statusReason="Method Not Allowed" statusDescription="GET, HEAD only" />
</rule>
XML;
    }

    public function renderDirectory(string $directory): string
    {
        $extensions = $this->policy->directories()[$directory] ?? throw new InvalidArgumentException('Unknown public resource directory.');
        $pattern = implode('|', $extensions);
        $types = [
            'css' => 'text/css', 'js' => 'text/javascript',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
            'avif' => 'image/avif', 'gif' => 'image/gif', 'ico' => 'image/x-icon', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'webp' => 'image/webp', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg',
        ];
        $mime = '';

        foreach ($extensions as $extension) {
            $mime .= "AddType {$types[$extension]} .{$extension}\n";
        }

        return <<<APACHE
Options -Indexes -MultiViews -ExecCGI -FollowSymLinks +SymLinksIfOwnerMatch
DirectoryIndex disabled
SetHandler default-handler
<FilesMatch "(?i)^(?![A-Za-z0-9_-]+\\.(?:{$pattern})$)">
    Require all denied
</FilesMatch>
{$mime}
APACHE;
    }
}
