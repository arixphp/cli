# Arix CLI

Development and distribution tooling for ArixPHP.

The CLI creates, maintains, and verifies Arix applications. It runs
application tests in isolated workers (`arix tests`), imports the approved
upstream packages into the standalone framework core (`./source`), and builds
the distributable executable (intermediate PHAR plus a PHPacker native binary
with PHP embedded).

Laravel Zero and Composer are development infrastructure for this tool only.
They are never part of a deployed Arix application. The deployed core in
`core/` (`Arix\`) runs on traditional PHP hosting without Composer, Node, or
frontend build steps, and requires PHP 8.4.1+ with `ext-mbstring`.

The deployable application skeleton ([arixphp/app](https://github.com/arixphp/app))
and this CLI evolve together, but they are
separate repositories with separate verification. Do not copy this repository's `core/` into
an application's `core/` manually; a future `update` command will own that
migration once its integration is implemented and verified.

## Local development

Run `arix dev` from your application or `arix dev --path=/path/to/application`.
The managed FrankenPHP server defaults to `http://127.0.0.1:8181`, with
port fallback and optional browser live reload. See
[development server](docs/development-server.md) and
[asset caching](docs/asset-caching.md) for configuration and integration.

## Learning Arix

The official documentation is available at
[arix.omarbarbosa.com](https://arix.omarbarbosa.com).

## Documentation

### Application class autoloading

Every application must declare its compatibility contract and namespaces in a
root `.arix.json`. There are no implicit application mappings, Composer runtime
dependencies, generated class maps, or deployment commands:

```json
{
    "version": "1.0.0",
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Arix\\": "core/"
        },
        "files": ["core/functions.php", "core/FastRoute/functions.php"]
    },
    "autoload-dev": {
        "psr-4": {"Tests\\": "tests/"}
    }
}
```

`version` identifies the supported application/core compatibility contract, not
the site's version or an equality check against an installed release. The former
`format` and `core_contract` fields are not accepted. See
[autoloading](docs/autoloading.md) for validation and activation APIs.

With this manifest, classes, interfaces, traits and enums resolve on demand:

```text
App\Utils\Formatter       -> app/Utils/Formatter.php
App\Services\MailService  -> app/Services/MailService.php
App\Domain\Blog\Post      -> app/Domain/Blog/Post.php
```

Directory and filename case must match the namespace exactly, including on
case-insensitive filesystems. This applies uniformly to `App\Controllers\`
in `app/Controllers/` and `App\Middleware\` in `app/Middleware/` too; there
are no lowercase-directory compatibility mappings.

PSR-4 is applied uniformly: there are no resource-directory or entry-name
exclusions. Resources still have their explicit loading mechanisms; their PHP
files must not be requested as classes unless they actually declare those classes.
The loader does not scan PHP files or follow symlinks. Application class files
are trusted code and should contain declarations, not request-time side effects.

`core/autoload.php` registers the single native loader for the manifest at the
application root and returns it. Application composition, HTTP runtime bootstrap
and scheduler execution reuse that loader in a temporary application-root scope,
restored on failure.
No autowiring is added: constructor dependencies still use explicit bindings.
PHP declarations cannot be unloaded or redefined; independent sites with
conflicting class names need separate processes. Fiber/coroutine execution and
persistent worker class reloading remain unsupported.

`arix tests` reuses the validated manifest and registers a development loader
before the test bootstrap. Application tests need no separate `App\` or `Tests\`
loader. Debug mode never enables development autoloading.

### Updating an application's core

```sh
# Default: use the installed CLI's embedded bundle, without internet:
arix core:update --dry-run
arix core:update

# Explicitly download the latest stable core from arixphp/cli on GitHub:
arix core:update --online --dry-run
arix core:update --online
```

Both methods require a compatible, managed application and maintenance during
replacement. Online errors never silently switch sources: omit `--online` for
the offline fallback, or update the CLI through your usual installer and use its
new embedded bundle. Neither command updates the CLI executable itself. See
the command help for `--path`, `--dry-run` and noninteractive `--yes` options.

Online mode trusts the official GitHub repository over HTTPS, pins the latest
stable release to a commit, and validates the bundle's manifest and SHA-256
inventory before replacement. It requires ext-curl, ext-zip, trusted CA
certificates and writable temporary storage. It reads only `resources/core.bundle`
from the source ZIP and does not execute downloaded repository code. Hashes detect
corruption, not malicious changes by someone controlling the official repository.

Stop and drain HTTP traffic before confirming either mode. The command rejects
unmanaged or locally modified cores, retains a verified previous-core backup, and
attempts rollback on installation failure. Keep any journal/stage/backup paths
reported by a recovery error. Refresh application caches and OPcache and verify
HTTP health before restoring traffic; the CLI verifies file integrity, not live
application health. Maintainers must commit a fresh `resources/core.bundle`
before tagging a release; CI checks its freshness before building.

### Installing the distributed CLI

#### With a package manager (recommended)

```sh
# macOS or Linux:
brew install arixphp/tap/arix

# Windows:
winget install ArixPHP.CLI
```

Both installers follow each release automatically: the release workflow opens
a formula PR in [arixphp/homebrew-tap](https://github.com/arixphp/homebrew-tap)
and submits the Windows manifest to winget. No system PHP installation is
required in any case: the binary embeds its own PHP runtime.

#### Direct download

Download the archive for your platform from the
[releases page](https://github.com/arixphp/cli/releases), along with
`SHA256SUMS`. Tags carry no `v` prefix, so version `1.2.3` ships as:

| Platform | Archive |
|---|---|
| Linux x64 | `arix-1.2.3-linux-x64.tar.gz` |
| Linux ARM64 | `arix-1.2.3-linux-arm64.tar.gz` |
| macOS x64 (Intel) | `arix-1.2.3-macos-x64.tar.gz` |
| macOS ARM64 (Apple Silicon) | `arix-1.2.3-macos-arm64.tar.gz` |
| Windows x64 | `arix-1.2.3-windows-x64.zip` (contains `arix.exe`) |

Verify the checksum before extracting:

```sh
grep "<platform>" SHA256SUMS | sha256sum -c -
```

The platform subsections below cover direct downloads; prefer the package
manager above when available.

No system PHP installation is required: the binary embeds its own PHP
runtime.

#### macOS and Linux (direct download)

```sh
tar -xzf arix-1.2.3-macos-arm64.tar.gz
sudo install -m 755 arix /usr/local/bin/arix
hash -r
arix --version
```

Without administrator rights, place `arix` in `~/.local/bin` (or any
directory) and add that directory to `PATH` instead of using
`/usr/local/bin`.

Releases are not signed or notarized. If macOS refuses to open the binary,
allow it once with right-click → Open, or remove the quarantine attribute:

```sh
xattr -d com.apple.quarantine /usr/local/bin/arix
```

#### Windows (direct download)

1. Extract `arix-1.2.3-windows-x64.zip`.
2. Move `arix.exe` to a permanent folder, for example
   `%LocalAppData%\Programs\Arix`.
3. Add that folder to your user `PATH` under System Properties →
   Environment Variables.
4. Open a new terminal and run `arix --version`.

Releases are not signed, so Windows SmartScreen may warn on first run; use
More info → Run anyway after verifying the checksum.

### Generating application files

From an Arix application root, generate conventional application files without
Composer or a deployment build step:

```sh
arix make:controller Admin/Post
arix make:middleware Audit
arix make:view blog.post
arix make:test Posts/Show
arix make:test Posts/Show --unit
```

The commands use the current directory by default; pass `--path=/path/to/app`
to select another compatible Arix application. `--force` replaces an existing
generated file. Controllers, middleware and tests accept nested names with
slashes or backslashes. Views also accept Laravel-style dot notation, so
`blog.post`, `blog/post`, and `Blog\Post` produce
`app/views/blog/post.arix.php`. Generated middleware is not registered
automatically; append it explicitly in `app/bootstrap.php`.

### Formatting application code

The `format` command formats PHP code in an Arix application using Pint as its
internal formatter. It targets only the `app` and `tests` directories, always
excluding `app/views`.

```sh
# From an application root (uses .arix-format.json if present):
arix format

# With an explicit application root:
arix format --path=/path/to/application

# With an explicit user rules file:
arix format --path=/path/to/application --config=/path/to/rules.json

# Check formatting without correcting code (for CI/CD):
arix format --test
arix format --test --path=/path/to/application
```

The `--test` option checks the same directories and respects `--config` without
rewriting PHP code. It exits with `0` when formatting is correct or `1` when
corrections are needed. Validation errors handled by the command return `2`;
other Pint failures retain their original exit code. Without `--test`, the command
continues to apply formatting corrections.

The optional `--config` accepts a path to a user format rules file. If omitted,
the command reads `.arix-format.json` from the application root. The file
supports only `version` and `rules`; directory keys such as `paths` are
rejected. The CLI decides which directories to format (`app` and `tests`,
excluding `app/views`), not the configuration file.

Pint is a runtime dependency of the CLI, not of a deployed Arix application.
Composer installations include it even with `--no-dev`. The published native
executable bundles Pint and PHP; formatting does not require a separate PHP,
Composer, or Pint installation on the target machine.

Formatting runs in an isolated worker so Pint's framework does not conflict with
the CLI's. Packaged workers extract only Pint's self-contained archive into a
private temporary directory and remove it on shutdown. No application `vendor/`
directory is created. The intermediate PHAR still requires PHP to launch it.

The user rules file, `.arix-format.json`, is a hidden file (leading dot) and is
denied by the existing server access rules in `.htaccess`, `Caddyfile` and
`web.config` without needing server-level changes.

Example `.arix-format.json` at the application root:

```json
{
    "version": 1,
    "rules": {
        "new_with_parentheses": true,
        "concat_space": { "spacing": "one" },
        "declare_strict_types": true,
        "nullable_type_declaration": { "syntax": "union" },
        "ordered_imports": {
            "sort_algorithm": "alpha",
            "imports_order": ["class", "function", "const"]
        }
    }
}
```

### Testing Arix applications

Pest is included as a runtime dependency of the Arix CLI. The target application
does not need Composer or its own `vendor/` directory.

```sh
# With the installed CLI, from an application's root:
arix tests
arix tests --path=/path/to/application

# From this source repository:
php arix tests --path=/path/to/application

# CLI and standalone framework verification remains separate:
./vendor/bin/pest
./vendor/bin/pint --test
```

Replace `/path/to/application` with the actual absolute application-root path.
The application and CLI repositories can be located independently; no sibling
directory layout or checkout name is required. Repository-local commands and
paths in this README are relative to the CLI repository's root.

Application `phpunit.xml` declares the suite and its `tests/autoload.php`
bootstrap. Application tests use Pest syntax and cover `app/` only. Framework
tests remain in this repository's `tests/Core/`.

The command validates the application and starts a fresh PHP worker, streams
Pest output, and preserves its exit status. Relative `--path` values resolve from
the invocation directory. Empty suites and invalid configurations fail. The
worker uses the application's own core, class loading and global helpers, not
the CLI development bootstrap. XML and tests are trusted local executable code.

Source execution uses the CLI's dependencies directly. Production distribution
uses a PHPacker self-contained executable with PHP embedded; the PHAR is an
intermediate build artifact, not the production installation format. The worker
re-executes that same executable without needing PHP in `PATH`.

Packaged dependencies are extracted into a randomly named private temporary
directory for execution and removed on normal shutdown. Writable temporary
storage is required; no network access or application-side installation is
needed. Forced process termination may leave a directory for the host to clean.

### Building and verifying the executable

The CLI produces the intermediate PHAR through Laravel Zero's build command,
and PHPacker embeds it in a native binary. Keep PHPacker in a separate
release-tool installation: its current Symfony requirements differ from this
CLI's runtime dependencies.

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
[Laravel Zero's binary distribution guide](https://laravel-zero.com/docs/distribute-as-a-single-executable-binary)
for the underlying build command.

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

The `Release` workflow starts when a SemVer tag such as `1.2.3` is pushed. The
tagged commit must belong to the history of `main`. Merge the workflow files and
their release helpers into `main` before creating the first release tag. Tags
carry no `v` prefix; the GitHub release title uses the `v`-prefixed version
(for example tag `1.2.3`, title `v1.2.3`).

```sh
# Run from this CLI repository, after verification and with a clean release commit:
git tag 1.2.3
git push origin 1.2.3
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
Tags with SemVer prerelease identifiers, such as `1.2.3-rc.1`, publish as
prereleases without changing the latest stable release. PHARs remain internal
workflow artifacts. Releases are not signed or notarized by this workflow.

After publication, two distribution jobs run: `tap` regenerates
`Formula/arix.rb` from the verified archives and opens a PR in
`arixphp/homebrew-tap` (it never pushes to the tap directly), and `winget`
submits the Windows manifest with a pinned `wingetcreate` build whose checksum
is verified before use. Both jobs require their secrets (`HOMEBREW_TAP_TOKEN`,
a PAT with `contents:write` on the tap repository, and `WINGET_TOKEN`); without
them a job fails fast with a clear message and the published release is
unaffected. Create the `arixphp/homebrew-tap` repository and submit the first
winget package manually before relying on automation: `wingetcreate update`
only handles packages that already exist.

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

## Security Vulnerabilities

If you discover a security vulnerability within the Arix CLI, please send an e-mail to Omar Barbosa via [contacto@omarbarbosa.com](mailto:contacto@omarbarbosa.com). All security vulnerabilities will be promptly addressed.

## Credits

The Arix CLI is built and maintained by Omar Barbosa as part of ArixPHP.

This tool stands on the work of the Laravel ecosystem and the upstream packages
imported into the standalone core:

- [Laravel](https://laravel.com) — underlying components used through the CLI
  development runtime.
- [Laravel Zero](https://laravel-zero.com), created by
  [Nuno Maduro](https://github.com/nunomaduro) and
  [Owen Voke](https://github.com/owenvoke) — micro-framework used only as
  development infrastructure for the CLI commands and the intermediate PHAR
  build. It is not the product this repository distributes.
- [symfony/http-foundation](https://github.com/symfony/http-foundation) —
  imported into `core/HttpFoundation/` as `Arix\HttpFoundation`.
- [nikic/fast-route](https://github.com/nikic/FastRoute) — imported into
  `core/FastRoute/` as `Arix\FastRoute`.
- [eftec/bladeone](https://github.com/EFTEC/BladeOne) — imported into
  `core/BladeOne/` as `Arix\BladeOne`.
- [dragonmantank/cron-expression](https://github.com/dragonmantank/cron-expression) —
  imported into `core/Cron/` as `Arix\Cron`.

Imported sources are namespace-relocated, managed copies; each managed
directory keeps its upstream `LICENSE` file. Do not hand-edit them; regenerate
them through `./source`. Test and packaging tooling includes
[Pest](https://pestphp.com), [Box](https://github.com/box-project/box), and
[PHPacker](https://github.com/phpacker/phpacker).

## License

The Arix CLI is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
