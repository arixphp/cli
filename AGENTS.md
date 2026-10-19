# Arix CLI

Development and distribution tooling for Arix. This repository uses Laravel Zero
and Composer during development, and builds a standalone framework core that can
run on traditional PHP hosting without Composer or a frontend build.

The workspace-level rules in `../AGENTS.md` also apply. This file is authoritative
for work inside `cli/`.

## Product boundary

`cli/` is the development tool for the deployable application in `../arix/`. Its
responsibilities include creating and maintaining Arix applications, generating
application code from stubs, testing framework components, importing approved
upstream packages, and eventually updating an application's managed `core/`.

The replacement core is still being integrated. Do not copy `cli/core/` into
`arix/core/`, implement an ad hoc deployment, or claim update compatibility unless
the task explicitly covers the complete migration and its verification. Use
`../arix/` as an architectural reference only; do not modify it from a CLI task
unless the user explicitly requests a cross-project change.

`../clix/` is out of scope. Do not read or modify it.

## Project layout

- `arix` is the Laravel Zero entry point.
- `app/` contains development-tool commands, providers, and the source importer.
- `app/Core/Source.php` owns the supported upstream import process.
- `source` is the executable interface for regenerating managed core sources.
- `core/` is the standalone Arix runtime, using the `Arix\` namespace.
- `tests/Core/` contains the standalone core's PHPUnit-style tests, executed by
  Pest.
- `tests/Feature/` and `tests/Unit/` cover the Laravel Zero application.
- `docs/` documents current architecture and historical implementation work.
- `resources/stubs/AGENTS.md` is the application agent guide that `arix new`
  writes into every created application, replacing the copy shipped by the
  skeleton. The skeleton repository keeps the same base text plus its own
  repository-specific section; keep both copies in sync.
- `vendor/` supplies CLI dependencies, including Pest as a runtime dependency;
  it must never be copied into `../arix/` or standalone core.
- `app/Testing/` launches isolated application tests, including the standalone
  worker entry and Pest adapter. Production uses a PHPacker
  executable with embedded PHP and an intermediate PHAR. Packaged workers extract
  dependencies to a private disposable directory and clean it on shutdown.
- `box.json` configures the compiled CLI build.
- `.github/workflows/ci.yml` verifies pushes and PRs for `main` and `develop`.
  `verify.yml` shares Pest, Pint and Actionlint jobs with `release.yml`.
- `.github/release/` contains development-only release guards, packaging helpers
  and the pinned embedded-runtime manifest. `.github/phpacker/` is a separate
  locked tooling installation; never mix its autoloader with the CLI's.

The primary architecture references are `docs/core-kernel.md`,
`docs/routing.md`, and `docs/views.md`. Plans and ledgers under
`docs/superpowers/` preserve implementation history; when a document marks a
layout or decision as superseded, follow the current architecture documents and
source instead.

## Standalone core

The standalone runtime requires PHP 8.4.1 or newer and `ext-mbstring`.
`core/autoload.php` enforces those requirements and loads the core without
Composer.

### Imported code

The importer relocates selected upstream sources:

| Development source | Managed destination | Runtime namespace |
|---|---|---|
| `vendor/symfony/http-foundation` | `core/HttpFoundation/` | `Arix\HttpFoundation` |
| `vendor/nikic/fast-route/src` | `core/FastRoute/` | `Arix\FastRoute` |
| `vendor/eftec/bladeone/lib` | `core/BladeOne/` | `Arix\BladeOne` |

Only supported PHP sources and package-root license files are imported. The
FastRoute root `src/bootstrap.php` and BladeOne's `bladeonecli` subtree are
excluded. `core/autoload.php` explicitly loads `FastRoute/functions.php`.

Treat `core/HttpFoundation/`, `core/FastRoute/`, and `core/BladeOne/` as generated,
managed code:

- Do not hand-edit or format imported files.
- Change import behavior in `app/Core/Source.php`, update its tests, and regenerate
  through `./source`.
- `core/imports.json` records package metadata, transformations, and hashes. Never
  delete or edit it to bypass integrity validation.
- The importer rejects modified, linked, mixed, or unmanaged destinations and
  uses staging, locking, and rollback. If it reports a validation or rollback
  problem, inspect the retained state instead of deleting evidence.
- Package flags such as `--package=http-foundation` and `--package=routing` are
  compatibility aliases for the complete supported import bundle, not selective
  imports.

### Handwritten code

All other `core/` modules are handwritten integration code. Important boundaries:

- `core/Http/` owns the kernel, request capture, path validation, and safe error
  responses.
- `core/Routing/` owns Arix routing adapters under `Arix\Routing`; it is distinct
  from the imported FastRoute engine.
- `core/Views/` owns view definitions and Blade/native-template integration under
  `Arix\Views`; it is distinct from imported BladeOne.
- `core/Container/` provides explicit bindings and request-scoped resolution.
- `core/Config/`, `core/Localization/`, and `core/Support/` provide shared runtime
  services and helpers.
- `core/Runtime/` owns supported HTTP SAPI execution. CLI and CLI-server are not
  deployment runtimes.

Do not introduce Composer runtime dependencies into handwritten core code. The
standalone loader and Composer loader must resolve the same Arix classes without
compatibility aliases or duplicate declarations.

## Commands

Run every command from the `cli/` directory.

- Full test suite: `./vendor/bin/pest`
- Core suite: `./vendor/bin/pest --testsuite=Core`
- Targeted test file: `./vendor/bin/pest tests/Core/ExampleTest.php`
- Style verification: `./vendor/bin/pint --test`
- Regenerate managed sources: `./source`
- Inspect importer options: `./source --help`
- Rebuild the optimized development autoloader after class or namespace moves:
  `composer dump-autoload --no-scripts`
- Run the development CLI: `php arix` or `./arix`
- Application tests: `php arix tests --path=../arix` from this directory, or
  installed `arix tests` from the application's root.

There are no project Composer scripts or PHPStan/Psalm configurations. GitHub
Actions run the full suite on Linux, macOS and Windows, Pint, and pinned
Actionlint. Do not invoke other tools merely because they appear transitively
in `composer.lock` or `vendor/`.

Release tags trigger a draft-first build of Linux x64/ARM64, macOS x64/ARM64 and
Windows x64 executables. Every target must pass native integration tests before
the draft can be published. Post-publish tap and winget jobs propose the
Homebrew formula PR and submit the winget manifest; they require the
HOMEBREW_TAP_TOKEN and WINGET_TOKEN secrets. Release helpers reject moved tags, releases observed
as published, missing assets and mismatched checksums. Do not manually publish or
edit a draft while its workflow is running; enable release immutability for
server-side protection against concurrent asset changes after publication.
See `README.md` for recovery,
runner requirements and tooling refresh. Do not push tags, change repository
rulesets or publish releases without explicit authorization.

Do not run `composer install` or `composer update` unless the task requires a
dependency operation. Do not run `./source` casually: it rewrites managed source
trees and the manifest, so use it only when import behavior or imported content is
in scope and validate the working tree first.

## Testing

- Application tests live in the target application's `tests/` and use Pest
  syntax only. Read its `phpunit.xml` via PHPUnit and bootstrap
  `tests/autoload.php`; never fall back to CLI tests or bootstrap Laravel Zero
  in the worker. Preserve local `App\\`, `Arix\\`, `Tests\\`, and Arix helpers.
- Pest is a production CLI dependency, not a deployed core dependency. Verify
  source, intermediate PHAR, and native executable modes; do not patch vendor code.
  Set `ARIX_TEST_EXECUTABLE` to run native integration tests without PHP in PATH.
  Micro-SAPI workers re-execute `micro_get_self_filename()` directly, not a system
  PHP command. Keep PHPacker in separate release tooling while its dependency
  constraints conflict with this project's Symfony requirements.
- Tests for `core/` belong under `tests/Core/` and use PHPUnit classes and
  assertions, not Pest closure syntax.
- PHPUnit test methods begin with `it_` and use the `#[Test]` attribute.
- Use fresh-process tests when proving standalone loading, namespace isolation,
  absence of Composer, or unavailable legacy classes.
- Cover both Composer autoloading and `core/autoload.php` when changing namespaces,
  file locations, imported functions, or standalone boot behavior.
- Importer changes require failure-path coverage for validation, staging,
  installation, rollback, symlink protection, unmanaged files, and repeat runs.
- Kernel, routing, and view changes should preserve request isolation and must not
  leak mutable state between consecutive requests.
- Test counts and assertion counts are not contracts. Report the exit result and
  failures rather than freezing counts in documentation.
- Run the full Pest suite and Pint before declaring a CLI change complete. If the
  task changes only documentation, syntax and content checks are sufficient unless
  the documentation describes behavior that needs verification.

## Coding conventions

- Use `declare(strict_types=1)` in PHP files.
- Do not declare classes `final`, including application classes, framework
  classes, tests, and fixtures.
- Use `type|null` rather than `?type` for nullable declarations.
- Use string interpolation such as `"Message {$value}"` rather than
  concatenation.
- Use `DIRECTORY_SEPARATOR` for filesystem paths.
- Add a blank line before each `return` statement.
- Separate conditional structures with a blank line.
- Explicitly import the special functions listed in `../AGENTS.md` when used.
- Follow `pint.json` for handwritten PHP. It excludes imported HttpFoundation and
  FastRoute sources; do not expand formatting into managed upstream code.
- Preserve public method signatures and documented behavior unless the requested
  change explicitly introduces a breaking migration.

## Security and runtime invariants

- Never derive class files, service identifiers, or filesystem paths directly
  from unvalidated visitor input.
- Preserve exact path validation, one-time decoding, base-path handling, and
  controller filename/class casing checks.
- Controllers use explicit container bindings; do not add autowiring implicitly.
- Keep a fresh container and mutable routing/view services per request.
- Preserve bounded request-body capture and safe error responses. PHP and the web
  server may impose additional limits before the application runs.
- HEAD and status-specific body suppression happens during response preparation.
  Explicit HEAD and OPTIONS routes take precedence over automatic behavior.
- Imported optional package components are not automatically supported merely
  because their source files exist; do not wire sessions or optional integrations
  without an explicit product decision and dependency review.
- The deployed core must not require Composer, Node, network access, or a build
  step.

## Working-tree safety

This repository may already contain tracked, staged, and untracked work. Before
editing, read the current file and inspect `git status --short`. Preserve all
unrelated changes.

Do not use `git checkout`, `git restore`, `git reset`, `git stash`, commits, or
worktrees unless explicitly requested. Do not remove import locks, manifests,
staging directories, or rollback backups without first establishing why they
exist and whether recovery still depends on them.

After implementation, review the scoped diff and confirm that neither `../arix/`
nor `../clix/` was modified unless the task explicitly authorized it.
