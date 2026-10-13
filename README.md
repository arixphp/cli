<p align="center">
    <img title="Laravel Zero" height="100" src="https://raw.githubusercontent.com/laravel-zero/docs/master/images/logo/laravel-zero-readme.png" alt="Laravel Zero Logo" />
</p>

<p align="center">
  <a href="https://github.com/laravel-zero/framework/actions"><img src="https://github.com/laravel-zero/laravel-zero/actions/workflows/tests.yml/badge.svg" alt="Build Status" /></a>
  <a href="https://packagist.org/packages/laravel-zero/framework"><img src="https://img.shields.io/packagist/dt/laravel-zero/framework.svg" alt="Total Downloads" /></a>
  <a href="https://packagist.org/packages/laravel-zero/framework"><img src="https://img.shields.io/packagist/v/laravel-zero/framework.svg?label=stable" alt="Latest Stable Version" /></a>
  <a href="https://packagist.org/packages/laravel-zero/framework"><img src="https://img.shields.io/packagist/l/laravel-zero/framework.svg" alt="License" /></a>
  <a href="https://youtube.com/@nunomaduro?sub_confirmation=1"><img alt="YouTube Channel Subscribers" src="https://img.shields.io/youtube/channel/subscribers/UCO_hYZF2gb_CyG5sA7ArlGg?style=flat&label=youtube&color=brightgreen"></a>
</p>

Laravel Zero was created by [Nuno Maduro](https://github.com/nunomaduro) and [Owen Voke](https://github.com/owenvoke), and is a micro-framework that provides an elegant starting point for your console application. It is an **unofficial** and customized version of Laravel, optimized for building command-line applications.

- Built on top of the [Laravel](https://laravel.com) components.
- Optional installation of Laravel [Eloquent](https://laravel-zero.com/docs/database/), Laravel [Logging](https://laravel-zero.com/docs/logging/) and many others.
- Supports interactive [menus](https://laravel-zero.com/docs/build-interactive-menus/) and [desktop notifications](https://laravel-zero.com/docs/send-desktop-notifications/) on Linux, Windows & MacOS.
- Ships with a [Scheduler](https://laravel-zero.com/docs/task-scheduling/) and  a [Standalone Compiler](https://laravel-zero.com/docs/distribute-as-a-single-executable-binary/).
- Integration with [Collision](https://github.com/nunomaduro/collision) - Beautiful error reporting
- Follow the creator Nuno Maduro:
    - YouTube: **[youtube.com/@nunomaduro](https://www.youtube.com/@nunomaduro)** — Videos every weekday
    - Twitch: **[twitch.tv/enunomaduro](https://www.twitch.tv/enunomaduro)** — Streams (almost) every weekday
    - Twitter / X: **[x.com/enunomaduro](https://x.com/enunomaduro)**
    - LinkedIn: **[linkedin.com/in/nunomaduro](https://www.linkedin.com/in/nunomaduro)**
    - Instagram: **[instagram.com/enunomaduro](https://www.instagram.com/enunomaduro)**
    - Tiktok: **[tiktok.com/@enunomaduro](https://www.tiktok.com/@enunomaduro)**

------

## Documentation

### Testing Arix applications

Pest is included as a runtime dependency of the Arix CLI. The target application
does not need Composer or its own `vendor/` directory.

```sh
# With the installed CLI, from an application's root:
arix tests
arix tests --path=/path/to/application

# From this source repository:
php arix tests --path=../arix

# CLI and standalone framework verification remains separate:
./vendor/bin/pest
./vendor/bin/pint --test
```

Application `phpunit.xml` declares the suite and its `tests/autoload.php`
bootstrap. Application tests use Pest syntax and cover `app/` only. Framework
tests remain in this repository's `tests/Core/`.

The command validates the application and starts a fresh PHP worker, streams
Pest output, and preserves its exit status. Relative `--path` values resolve from
the invocation directory. Empty suites and invalid configurations fail. The
worker uses the application's own core, class loading and global helpers, not
Laravel Zero's bootstrap. XML and tests are trusted local executable code.

Source execution uses the CLI's dependencies directly. Production distribution
uses a PHPacker self-contained executable with PHP embedded; the PHAR is an
intermediate build artifact, not the production installation format. The worker
re-executes that same executable without needing PHP in `PATH`.

Packaged dependencies are extracted into a randomly named private temporary
directory for execution and removed on normal shutdown. Writable temporary
storage is required; no network access or application-side installation is
needed. Forced process termination may leave a directory for the host to clean.

### Building and verifying the executable

Laravel Zero produces the intermediate PHAR and PHPacker embeds it in a native
binary. Keep PHPacker in a separate release-tool installation: its current
Symfony requirements differ from this CLI's runtime dependencies.

```sh
# Build in a disposable release copy with production dependencies installed:
php arix app:build arix.phar --build-version=<release-version> --no-interaction

# Use the independently installed PHPacker (example: macOS ARM):
/path/to/phpacker/bin/phpacker build mac arm --src=./builds/arix.phar --dest=./builds/bin --php=8.4

# Run the native executable without a system PHP installation:
./builds/bin/mac/mac-arm tests --path=/path/to/application

# Run native integration tests in the development CLI checkout:
ARIX_TEST_EXECUTABLE=/absolute/path/to/mac-arm ./vendor/bin/pest tests/Unit/Testing/StandaloneExecutableTest.php
```

Builds download the embedded PHP runtime; test execution does not. Verify the
embedded PHP extensions on every target platform, including mbstring and
Pest/PHPUnit's required XML extensions. Native integration tests are skipped
unless `ARIX_TEST_EXECUTABLE` is set. See
[Laravel Zero's binary distribution guide](https://laravel-zero.com/docs/distribute-as-a-single-executable-binary).

CLI installations require the PHP version and extensions declared in
`composer.json` and the locked dependencies; the standalone application core
requires PHP 8.4.1+ and mbstring. Developer-only dependencies are not required to
run application tests with a distributed CLI.

For full documentation, visit [laravel-zero.com](https://laravel-zero.com/).

## Support the development
**Do you like this project? Support it by donating**

- PayPal: [Donate](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=66BYDWAT92N6L)
- Patreon: [Donate](https://www.patreon.com/nunomaduro)

## License

Laravel Zero is an open-source software licensed under the MIT license.
