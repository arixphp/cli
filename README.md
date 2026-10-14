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

### Continuous integration and releases

The `CI` workflow runs on pushes to `main` and `develop`, and on pull requests
targeting those branches. It runs the full Pest suite on Linux, macOS and Windows,
Pint separately, and Actionlint for workflow validation. Dependencies come from
`composer.lock`; CI does not update them. Configure the resulting Pest, Pint and
Actionlint checks as required checks in the rulesets for both branches after their
first run. Repository settings are not changed by the workflows.

The core packaging fixture is compiled with Box in a Linux job and shared with
all three test runners. Each runner executes the same PHAR and verifies that
compaction preserves the exact core bytes without a source tree or Composer.
Box 4.7.0 cannot clean up its working directory on Windows, so Windows tests
consume the Linux-built artifact instead of compiling it or skipping validation.
For local testing, export it on Linux/macOS with
`ARIX_EXPORT_CORE_PHAR=/absolute/path/core-packaging.phar ./vendor/bin/pest tests/Unit/Core/Update/CorePackagingTest.php`,
then set `ARIX_TEST_CORE_PHAR` to that file's absolute path on the consuming host.
Missing or invalid artifacts fail the test. Unix runs without that variable
continue to build the fixture locally.

The `Release` workflow starts when a SemVer tag such as `v1.2.3` is pushed. The
tagged commit must belong to the history of `main`. Merge the workflow files and
their release helpers into `main` before creating the first release tag.

```sh
# Run from this CLI repository, after verification and with a clean release commit:
git tag v1.2.3
git push origin v1.2.3
```

The workflow creates or recovers a **draft release**, verifies the exact tagged
commit using the same CI checks, and builds the intermediate PHAR in a disposable
checkout with production dependencies. That PHAR is shared by all native builds:

| Distribution | Native runner |
|---|---|
| Linux x64 | `ubuntu-24.04` |
| Linux ARM64 | `ubuntu-24.04-arm` |
| macOS x64 | `macos-15-intel` |
| macOS ARM64 | `macos-15` |
| Windows x64 | `windows-2022` |

Unix distributions use `.tar.gz` and contain `arix`; Windows uses `.zip` and
contains `arix.exe`. PHPacker does not provide a Windows ARM target in the pinned
tooling version. Ensure these runners are enabled and the account has sufficient
Actions minutes and artifact storage; unavailable or failing targets block the
release rather than being omitted.

PHPacker has its own locked Composer installation under `.github/phpacker/`.
`.github/release/build.php` uses its packager with runtime downloads pinned by
upstream commit and SHA-256 in `.github/release/runtimes.json`, instead of allowing
PHPacker to fetch a changing `latest` runtime. To refresh release tooling, update
its isolated lock or the runtime manifest deliberately and rerun all native
checks. Keep embedded PHP security updates current; a pinned download is not an
automatic update policy.

Each distribution is extracted and tested on its native runner. Release tests
require the correct version, PHP 8.4.1+, the production dependency extensions,
and operation without system PHP in `PATH`. JUnit reports must contain executed
tests with no skips, failures or errors. Ordinary CI may omit the optional native
tests when there is no executable; a release may not.

After every target passes, the workflow uploads the five archives and
`SHA256SUMS`, downloads them again to verify their content, checks that the tag
has not moved and that the release is still a draft, and only then publishes.
Tags with SemVer prerelease identifiers, such as `v1.2.3-rc.1`, publish as
prereleases without changing the latest stable release. PHARs remain internal
workflow artifacts. Releases are not signed or notarized by this workflow.

If any check, build or asset operation fails, the draft remains unpublished.
Re-run the workflow, or choose **Actions → Release → Run workflow** from `main`
and supply the existing tag. Retries may replace only the expected assets of the
draft; they preserve its notes and unrelated assets, and stop whenever a check
observes an already published release. Do not move a release tag to fix code:
create a new version tag. Creating or editing a draft in the GitHub Releases UI alone does not
trigger this workflow.

Do not manually publish, delete or edit the draft while the workflow is running:
workflow concurrency serializes Actions runs, not GitHub UI/API operations.
Draft checks and subsequent writes are separate API calls, so they cannot provide
an atomic lock against an external publisher. For server-enforced protection of
published tags and assets, enable **Settings → General → Releases → Enable
release immutability** before the first production release. This applies to future
releases and is a maintainer-managed setting, not something this workflow changes.

Workflows use read-only permissions except the jobs that manage the draft and
publish it, which require `contents: write`. They use the repository's
`GITHUB_TOKEN`; no personal access token is required. Publication with that token
does not trigger another workflow listening to `release: published`, so all
required verification is inside this workflow. Validate the first full release
in a separate test repository before enabling production distribution.

For full documentation, visit [laravel-zero.com](https://laravel-zero.com/).

## Support the development
**Do you like this project? Support it by donating**

- PayPal: [Donate](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=66BYDWAT92N6L)
- Patreon: [Donate](https://www.patreon.com/nunomaduro)

## License

Laravel Zero is an open-source software licensed under the MIT license.
