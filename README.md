# PHP Changesets

Prepare multi-package releases for PHP projects and monorepos with changeset Markdown files, PHP and Git. Released as [`wpelevator/php-changesets`](https://github.com/wpelevator/php-changesets).

Contributors describe each change in a small Markdown file. When you release, the CLI bumps the versions of every changed package, writes the changelogs and lists the tags to create. It never commits, tags or publishes: those steps belong to your workflow, like the [GitHub Actions workflow](#release-with-github-actions) below.

## Quickstart

For a WordPress plugin or a Composer package at the repository root:

```sh
composer require --dev wpelevator/php-changesets
vendor/bin/changesets init                  # Infer and write changesets.config.php
vendor/bin/changesets add                   # Describe a change; commit the file with it
vendor/bin/changesets version --dry-run     # Preview the versions, changelog and diff
vendor/bin/changesets version               # Apply them; review and commit
vendor/bin/changesets tags                  # Print the tags to create, like v1.3.0
```

## Requirements

- PHP 7.4 or later.
- Git for tag-based versions, existing-tag checks and dry-run diffs.
- Composer to install the CLI. Node.js is not required at runtime.

## Install

```sh
composer require --dev wpelevator/php-changesets
vendor/bin/changesets --help
```

For development inside this monorepo, run the executable from the package directory with `php packages/php/php-changesets/bin/changesets` or through the root Composer installation as `vendor/bin/changesets`.

## Usage

Run commands from the project root, or pass `--root DIRECTORY` to any command.

```sh
vendor/bin/changesets init
vendor/bin/changesets add --package example/alpha --bump patch --summary "Fix the settings link."
vendor/bin/changesets status --json
vendor/bin/changesets version --dry-run
vendor/bin/changesets version
# Review and commit the updated version and changelog files.
```

| Command | Purpose and options |
| --- | --- |
| `changesets init` | Create the changeset directory and, when no config exists, write a `changesets.config.php` scaffold from the inferred project settings. Inference adds a `packages/*` group only when every matched directory declares a Composer name and version; otherwise the scaffold stays one group and carries a commented template that shows how to add more groups. |
| `changesets add` | Add a changeset. Prompts for the affected packages, their bumps and the summary in a terminal; non-interactive use requires `--package NAME` for each affected package plus `--bump patch|minor|major --summary TEXT`. A package can be named by its identifier or its path under the configured root, like `plugins/alpha`. One `--bump` applies to every selected package; repeat `--bump` after each `--package` to give each its own bump. Use `--empty` for a no-release changeset without a package selection, or `--name NAME` to choose the filename. |
| `changesets status` | Show the pending release plan. Use `--json` for machine-readable output. |
| `changesets version` | Apply the pending release plan. Use `--dry-run` to preview it without writes, `--date YYYY-MM-DD` to set the changelog date and `--prerelease ID` to release pre-releases instead, like `--prerelease rc` or `--prerelease rc.2`. |
| `changesets tags` | Print the release tags that the current versions still need, one per line, like `wp-release changeset-tag --untagged`. Use `--json` for each tag with its package, version, pre-release flag and changelog notes. |
| `changesets recover` | Restore original files after an interrupted release transaction. |

`status`, `version` and `tags` accept `--github-output` to set their result as GitHub Actions step outputs, like `wp-release`: the compact JSON in `result`, and each top-level string, like the dry-run `diff`, as its own output.

The CLI does not commit, tag, push or publish. It only checks that a proposed tag does not exist yet. Review and commit version and changelog changes separately. A second `version` run with no pending changesets makes no release edits.

### Add a changeset

A changeset's frontmatter maps one or more package names to a `patch`, `minor` or `major` bump, and its body is a nonempty Markdown summary:

```md
---
"example/alpha": minor
---

Add a feature.
```

A single changeset may release several packages by listing one `package: bump` mapping per line, so the frontmatter is a `Record<package name, bump>` like the `wp-release` tooling expects. Commit the generated file with the change. With one releaseable package, `add` selects it automatically; with several, it prompts in a terminal or requires one repeatable `--package NAME` per affected package, and the frontmatter carries exactly the selected names. Like `wp-release changeset-add`, `--package a --bump minor --package b --bump patch` writes a different bump per package:

```sh
vendor/bin/changesets add --package example/alpha --bump minor --package plugins/beta --bump patch --summary "Share the settings screen."
```

 Unknown package names, duplicated entries and unsupported YAML are rejected against the discovered package names. Multiple changesets aggregate with the highest bump per package and preserve their notes in filename order.

Empty frontmatter records an explicit no-release decision and applies to every package, so `--empty` needs no package selection and is consumed by the next release of all of them.

### Preview and apply a release

`status --json` and `version --dry-run --json` emit the `wp-release` release-plan shape: `changesets` with `file`, `packages` and `summary` items, and one `releases[]` item per discovered package carrying `package`, `currentVersion`, `bump`, `nextVersion`, `tag`, `changesets`, `notes` and affected `paths` in configured group order. A release without changesets has a null bump and tag. Dry-run JSON also includes one combined `diff` string with a `### {package}` section per release. Human output prints one block per package. `releases[]` is the data contract shared with `wp-release`, so `status --json` compares field for field against `changeset-status --json`.

`version` checks that each proposed tag does not exist, updates the configured version targets of every release, prepends a `## {version} ({date})` changelog section and consumes the changesets. Every package's targets are preflighted before any files are written, and the ordered edits of all releases apply as one transaction, so a failure never leaves one package released and another pending. An interrupted write leaves a `.php-changesets-transaction/` journal and blocks `status` and `version`; run `changesets recover` before retrying.

### Release with GitHub Actions

The CLI prepares releases and other actions publish them. This workflow opens a release pull request whenever changesets are pending on `main`, and after the pull request is merged, creates the tag and GitHub release of every released package with the `gh` CLI. Packagist picks up the new tags of Composer packages; add the [WP Release Zip](https://github.com/marketplace/actions/wp-release-zip) and [WP Release Deploy SVN](https://github.com/marketplace/actions/wp-release-deploy-svn) actions to the `release` job to ship a WordPress plugin.

```yaml
name: Release

on:
  push:
    branches: [ main ]

permissions:
  contents: write
  pull-requests: write

jobs:
  plan:
    runs-on: ubuntu-latest
    outputs:
      status: ${{ steps.status.outputs.result }}
      tags: ${{ steps.tags.outputs.result }}
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0
      - uses: shivammathur/setup-php@v2
      - run: composer install --no-progress
      - id: status
        run: vendor/bin/changesets status --github-output
      - id: tags
        run: vendor/bin/changesets tags --github-output

  release-pr:
    needs: plan
    if: fromJSON(needs.plan.outputs.status).changesets[0] != null
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0
      - uses: shivammathur/setup-php@v2
      - run: composer install --no-progress
      - run: vendor/bin/changesets version
      - uses: peter-evans/create-pull-request@v7
        with:
          branch: changeset-release/main
          title: Release
          commit-message: Release

  release:
    needs: plan
    if: fromJSON(needs.plan.outputs.tags).tags[0] != null
    runs-on: ubuntu-latest
    strategy:
      matrix:
        release: ${{ fromJSON(needs.plan.outputs.tags).tags }}
    steps:
      - env:
          GH_TOKEN: ${{ github.token }}
          TAG: ${{ matrix.release.tag }}
          NOTES: ${{ matrix.release.notes }}
          PRERELEASE: ${{ matrix.release.prerelease }}
        run: gh release create "$TAG" --repo "$GITHUB_REPOSITORY" --target "$GITHUB_SHA" --title "$TAG" --notes "$NOTES" --prerelease="$PRERELEASE"
```

The `plan` job needs the full history (`fetch-depth: 0`) for the tags. The release notes come from each package's changelog section of the version. Pass them through environment variables, as above, rather than into the script itself, since they are contributor text.

## Configuration

The configuration lives in `changesets.config.php` at the project root, the analog of `wp-release.config.mjs`.

With no config, `init` infers the single project from a root Composer manifest or WordPress plugin headers as one group, tagged `v{version}` like the `wp-release` single-project default. The package is named by its Composer `name`, or for a plugin without one, by the slug of its main plugin file, like `demo` for `demo.php`, never by the checkout directory. A `readme.txt` with a `== Changelog ==` section turns on `readmeChangelog`. A uniform `packages/*` layout — where every matched directory declares a Composer name and version — gains a second inferred group. Ambiguous layouts keep the single group and the scaffold carries a commented template that shows how to add groups. Config lives outside the changeset directory on purpose: `.changeset/` stays pure data (Markdown changesets plus `README.md`).

The loader rejects the removed formats `.php-changesets.json`, `.changeset/config.php` and `config/changesets.php` with guidance pointing at `changesets.config.php`. It also rejects the upstream Changesets `.changeset/config.json`: both tools would read and consume the same changeset files, so a repository uses one or the other.

The config file is regular PHP (loaded with `require`) and must return an array, normally the result of `define_config()`, which validates the settings and hooks eagerly at load time and returns their canonical shape. The config helpers are namespaced functions, the PHP analogs of the `wp-release` `defineConfig()` and descriptor builders; import them with `use function`.

### Workspace settings

The canonical configuration is a workspace with package groups; a single-project config is one group covering the repository root:

| Key | Required | Default | Purpose |
| --- | --- | --- | --- |
| `packages` | Yes | — | Non-empty list of package groups, in release order. |
| `changesets` | No | `.changeset` | Directory containing changeset Markdown files, relative to the repository root. |
| `tagFormat` | No | `{path}/{version}` | Proposed tag format, like `plugins/alpha/1.2.0`, or `1.2.0` for a package at the root; must contain `{version}` exactly once and may use `{name}` and `{path}`. The default matches `wp-release`. |
| `root` | No | `null` | Package path base that package globs resolve against; `null` uses the repository root. |
| `changelog` | No | `CHANGELOG.md` | Default changelog path for groups. Set to an empty string to disable changelog generation. |
| `readmeChangelog` | No | `false` | Also add a `= {version} =` section with one `*` bullet per changeset summary after the `== Changelog ==` heading of a package's `readme.txt` or `readme.md`, which WordPress.org shows, like `wp-release`. A readme without the heading is left as it is. |
| `nextVersion` | No | `null` | Callable replacing the stable bump computation; see the hooks below. |
| `changelogEntry` | No | `null` | Callable formatting one changeset's changelog line; see the hooks below. |

Each package group sets:

| Key | Required | Default | Purpose |
| --- | --- | --- | --- |
| `glob` | Yes | — | Relative path pattern for package directories, like `packages/*` or `.` for the root. |
| `exclude` | No | `[]` | Relative path patterns removed from the group matches. |
| `id` | No | `null` | Fixed package identifier for a glob that matches exactly one directory; takes precedence over `idFrom`. |
| `idFrom` | No | `path` | Package name source: `composer.json#name`, `package.json#name`, or `path`. The package at the root (`.`) has no path, so it needs an `id` or a manifest source: unlike `wp-release`, the name of the checkout directory never names a package, so changesets keep working in forks, worktrees and CI. |
| `version` | Yes | — | `{ from, to }` descriptors; every releasable group needs one. |
| `changelog` | No | Inherited | Per-package changelog path; empty string disables, `null` inherits. `wp-release` has no per-group setting and always writes `CHANGELOG.md`. |
| `tagFormat` | No | Inherited | Per-package tag format; `null` inherits. `wp-release` only has the repository setting. |

Discovery walks the directories below `root`, bounded by the deepest configured glob, and never enters `.git`, `node_modules`, `vendor` or `dist`. A glob that matches no directory, and duplicate package identifiers across groups, are configuration errors. Only the literal `.` glob matches the package root itself: free-form patterns like `*` and `packages/**` never cover it, unlike `wp-release`, where a pattern like `*` also matches the root. Group settings and descriptor files must resolve inside the package, like all project paths.

A monorepo config lists one group per package kind; releases stay in configured group order:

```php
<?php

use function WPElevator\PHP_Changesets\composer_json_version;
use function WPElevator\PHP_Changesets\define_config;
use function WPElevator\PHP_Changesets\git_tag_version;
use function WPElevator\PHP_Changesets\wordpress_plugin_version;
use function WPElevator\PHP_Changesets\wordpress_stable_tag;

return define_config( [
	'changesets' => '.changeset',
	'packages' => [
		[
			'glob' => 'packages/*',
			'idFrom' => 'composer.json#name',
			'version' => [
				'from' => git_tag_version(),
				'to' => [],
			],
		],
		[
			'glob' => 'plugins/*',
			'version' => [
				'from' => wordpress_plugin_version( 'plugin.php' ),
				'to' => [
					wordpress_plugin_version( 'plugin.php' ),
					wordpress_stable_tag( 'readme.txt' ),
				],
			],
		],
	],
] );
```

### Hooks

Callbacks are plain PHP closures and live only in the PHP config; nothing else in the schema accepts callables, and callability is validated at config load. A failing hook, or a `nextVersion` result that is not a supported version, stops the run with a message naming the package.

`nextVersion` receives `{ package, bump, currentVersion, changesets }`, like the `wp-release` `NextVersionInput`, where `package` is `{ name, path, currentVersion }` and `changesets` lists the release's changeset records, and it returns the supported stable version of the release. A `--prerelease` run turns that version into a pre-release afterwards, as without a hook:

```php
<?php

return \WPElevator\PHP_Changesets\define_config( [
	'nextVersion' => static function ( array $input ): string {
		return sprintf( '%s.0.0', (int) $input['currentVersion'] + 1 );
	},
	'packages' => [ /* one or more groups */ ],
] );
```

`changelogEntry` receives `{ package, changeset }` where `changeset` is the record with `file`, `packages` and `summary`, and formats one changeset's changelog line, replacing the default `- {summary}` bullet. An empty result falls back to the default entry:

```php
<?php

return \WPElevator\PHP_Changesets\define_config( [
	'changelogEntry' => static function ( array $input ): string {
		return '- [' . $input['changeset']['file'] . '] ' . $input['changeset']['summary'];
	},
	'packages' => [ /* one or more groups */ ],
] );
```

### Version descriptors

| `kind` | Fields | Source or target behavior |
| --- | --- | --- |
| `composer-json` | `file` | Read or replace the top-level Composer `version` while preserving surrounding formatting. A target with `ifPresent: true` is skipped when the version is absent. |
| `wordpress-plugin-header` | `file` | Read or replace the plugin `Version` header. |
| `wordpress-theme-header` | `file` | Read or replace the theme stylesheet `Version` header, over `style.css` by default. |
| `wordpress-stable-tag` | `file` | Read or replace the readme `Stable tag`. A target with `ifPresent: true` is skipped when the file or value is absent. |
| `php-constant` | `file`, `constant` | Read or replace a `const` declaration or `define()` value with the given constant name. |
| `git-tag` | `pattern`, optional `initialVersion` | Read the highest tag by version precedence matching `pattern`, which defaults to the package's tag format and also expands `{name}` and `{path}`; pre-release tags rank below the stable version of the same base. Source only; use an empty `version.to` list. |
| `callback` | `file`, `resolve`, optional `replace` | Resolve and replace a custom version span, available in every config path. |

### Builder helpers

Version descriptors are built with functions in the `WPElevator\PHP_Changesets` namespace, which Composer autoloads: `composer_json_version( ?string $file, bool $if_present )`, `wordpress_plugin_version( ?string $file )`, `wordpress_theme_version( ?string $file )` (`style.css` by default) and `wordpress_stable_tag( ?string $file, bool $if_present )` for the manifest, header and readme kinds, `php_constant_version( string $file, string $constant )` for constants, `git_tag_version( ?string $pattern, ?string $initial_version )` for tag-only sources, and `callback_version( string $file, callable $resolve, ?callable $replace )` for custom spans.

`define_config( array $config )` is the single accumulator of configuration validation: it accepts a workspace configuration, rejects unknown or malformed settings eagerly and returns the canonical shape every other component consumes.

A Git-tag-only library needs a first tag, like `git tag v0.1.0`, or an initial version for its first release:

```php
<?php

return \WPElevator\PHP_Changesets\define_config( [
	'packages' => [
		[
			'glob' => '.',
			'idFrom' => 'composer.json#name',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\git_tag_version( 'v{version}', '0.1.0' ),
				'to' => [],
			],
		],
	],
] );
```

Once a matching stable tag exists, it takes precedence over `initialVersion`. Tag-only releases do not modify `composer.json`: the release is recorded in the changelog, and `tags` lists the changelog version above the latest tag until its tag exists.

### Callback descriptors

Use `callback_version()` when the built-in descriptors cannot locate the version. Its callables must be deterministic and must not write files:

- `resolve( string $contents ): ?array` returns the version `value`, zero-based byte `offset` and byte `length`, or `null` when absent. The span must contain exactly `value`.
- `replace( string $contents, string $old_version, string $new_version ): string` optionally returns the complete proposed contents. Without it, only the resolved span is replaced.
- After either replacement, `resolve` must find the new version. `resolve` runs for status and planning; `replace` runs whenever a bump is planned, including dry runs.

The same descriptor can be the source and a target:

```php
<?php

$resolve = static function ( string $contents ): ?array {
	if ( ! preg_match( '/^Release: (\d+\.\d+\.\d+)$/m', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}

	return [
		'value' => $matches[1][0],
		'offset' => $matches[1][1],
		'length' => strlen( $matches[1][0] ),
	];
};
$version = \WPElevator\PHP_Changesets\callback_version( 'release.txt', $resolve );

return \WPElevator\PHP_Changesets\define_config( [
	'packages' => [
		[
			'glob' => '.',
			'id' => 'example',
			'version' => [
				'from' => $version,
				'to' => [ $version ],
			],
		],
	],
] );
```

### Excluded by design

This package deliberately omits the features of `wp-release` that changesets cannot honor today, and its CLI never commits, tags, pushes or publishes versions. The excluded surface is the same in every config path and is rejected rather than silently ignored:

- Fixed and linked version groups: every package releases from its own changesets and version descriptors.
- Snapshot-style canary versions (`version --snapshot`): pre-releases are supported through the `--prerelease` flag, but time-stamped snapshot builds stay exclusive to `wp-release`.
- The dependents system: bundled or dependent packages release with their own changesets, not automatically after a source release.
- Publishing: no artifact bundling, i18n extraction, git commits, tag creation or package registry or WordPress.org publishing; the CLI only prepares version, changelog and changeset files, and `tags` lists the tags for a workflow to create.
- `version --package` subset selection: a release always plans and applies every discovered package's pending changes; `--package` only selects the packages a new changeset releases.

### Pre-releases

There is no pre mode and no state file. Pre-releases are a per-run transform of the ordinary release plan, mirroring wp-release: `changesets version --prerelease ID` computes the stable plan first, then turns every release's version into a pre-release of that stable version.

- A bare identifier like `rc` numbers one above the highest existing `<stable>-<identifier>.` version among the package's Git tags in its tag format, so the first pre-release of a stable version is `1.2.5-rc.1`. Tag each cut pre-release with the release workflow after the run; the numbering counts those tags.
- An explicit `id.n` like `rc.2` is used as-is, and a run fails when that exact version already exists among the tags.
- Versions have two to four numeric parts, like `X.Y.Z` and the `X.Y` and `X.Y.Z.W` versions common in WordPress plugins, with an optional pre-release like `X.Y.Z-<tag>.<n>`; build metadata is rejected. A bump keeps the number of parts, so a minor bump of `2.1` is `2.2`, like `wp-release`.
- A stable release of a pre-release current version graduates like `semver inc`: a patch of `1.2.4-rc.1` plans `1.2.4`, and a minor of `1.3.0-rc.1` plans `1.3.0`, while a minor of `1.2.4-rc.1` plans `1.3.0`. `wp-release` rejects bumping a pre-release current version instead, since its recommended flow never merges one.
- Pre-release runs are ordinary releases: version files, changelog sections, dry-run previews and the tag-existence preflight operate on the pre-release value, and the `status --json` and `version --dry-run --json` shapes stay unchanged. The readme `Stable tag` is the one exception: WordPress.org offers the stable tag to every site, so it keeps the last stable version until the stable release arrives, and a pre-release current version leaves it unverified.
- With a `nextVersion` hook, the transform applies to the stable version that the hook returns, like `wp-release`.

The recommended flow is the wp-release one: cut a pre-release on a non-merged branch with `changesets version --prerelease rc`, tag it with the release workflow, merge further changesets and repeat on the branch, then run `changesets version` without the flag to release the stable version.

## Development

Classes live in `php/` with Composer classmap autoloading, and tests live in `tests/phpunit/`. The `bin/changesets` entry point loads `php/bootstrap.php` so it also runs directly from a checkout. Run the package tests from the monorepo root:

```sh
docker compose run --rm wordpress vendor/bin/phpunit --testsuite php/php-changesets
```

### Architecture

| Component | Responsibility |
| --- | --- |
| `Project_Files` | Own the canonical project root and resolve safe paths within it, including a package-bound resolver. Shared by all project-bound services. |
| `Config_Schema` and `functions.php` | Validate the workspace configuration and its hooks through `define_config()`, and build the shared version descriptors with the namespaced builder functions, which Composer autoloads as `files`. |
| `Package_Discovery` | Find package directories from the configured globs, with id resolution and duplicate and empty-match detection. |
| `Cli` | Resolve the project root once, construct the project-bound services, parse commands, prompt for package selection and changeset content, create changesets and configuration, display plans and invoke recovery. |
| `Release_Engine` | Coordinate injected configuration, parsing, version planning, the stateless `--prerelease` rework of planned releases, hook-driven changelog and readme changelog formatting, one ordered in-memory edit plan, and the list of tags that released versions still need. |
| `Semver` | Parse, bump and compare the supported stable and pre-release versions, and number the pre-release versions of the stable plan from the package tags. |
| `Tag_Format` | Expand the `{name}`, `{path}` and `{version}` placeholders of tag formats and git-tag patterns, like `wp-release` `formatTag()`. |
| `Changeset_Reader` and `Version_Planner` | Read ordered multi-package changesets and assemble the `releases[]` plan of every package, with the highest bump, next version, tag and notes. |
| `Version_Files` | Resolve version descriptors and propose bounded in-memory edits without writing files. |
| `Release_Transaction` | Preflight expected contents, journal and stage the edits of every package, apply the plan atomically and recover interrupted writes. |

`Project_Config`, `Version_Files`, `Release_Engine` and `Release_Transaction` are instance-based and receive `Project_Files` through their constructors. The engine also accepts its collaborators as optional constructor arguments for focused tests; the CLI uses the default project-bound implementations. Package directories come from `Package_Discovery` in configured group order; the engine plans one release per discovered package and applies their combined edits as one transaction.

`status` and `version --dry-run` only inspect the plan. `version` passes the same edits to the transaction layer. Only the CLI's `init` and `add` commands and the transaction layer write project files.

PHP methods, parameters and local variables use snake_case. External JSON and configuration keys such as `currentVersion`, `nextVersion`, `tagFormat`, `initialVersion` and `ifPresent` remain camelCase because they are data-format contracts.

Releases use the monorepo's `wp-release` workflow and are split to [github.com/wpelevator/php-changesets](https://github.com/wpelevator/php-changesets).
