# PHP Changesets

Prepare releases for one PHP project with changeset Markdown files, PHP and Git. Released as [`wpelevator/php-changesets`](https://github.com/wpelevator/php-changesets).

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
vendor/bin/changesets add --bump patch --summary "Fix the settings link."
vendor/bin/changesets status --json
vendor/bin/changesets version --dry-run
vendor/bin/changesets version
# Review and commit the updated version and changelog files.
```

| Command | Purpose and options |
| --- | --- |
| `changesets init` | Create the changeset directory and, when no config exists, write `.php-changesets.json` from the inferred project settings. |
| `changesets add` | Add a changeset. Prompts for a missing bump and summary in a terminal; non-interactive use requires `--bump patch|minor|major --summary TEXT`. Use `--empty` for no release or `--name NAME` to choose the filename. |
| `changesets status` | Show the pending release plan. Use `--json` for machine-readable output. |
| `changesets version` | Apply the pending release plan. Use `--dry-run` to preview it without writes and `--date YYYY-MM-DD` to set the changelog date. |
| `changesets recover` | Restore original files after an interrupted release transaction. |

The CLI does not commit, tag, push or publish. Review and commit version and changelog changes separately. A second `version` run with no pending changesets makes no release edits.

### Add a changeset

A changeset contains one quoted or unquoted project name, a `patch`, `minor` or `major` bump, and a nonempty Markdown summary:

```md
---
"example/plugin": minor
---

Add a feature.
```

Commit the generated file with the change. Empty frontmatter records an explicit no-release decision. Unknown or multiple projects and unsupported YAML are rejected. Multiple changesets use the highest bump and preserve their notes in filename order.

### Preview and apply a release

`status --json` and `version --dry-run --json` report `project`, `currentVersion`, `bump`, `nextVersion`, `tag`, ordered `changesets`, `notes` and affected `paths`. Dry-run JSON also includes `diff`. A plan without a release has a null bump and tag.

`version` checks that the proposed tag does not exist, updates the configured version targets, prepends a `## {version} ({date})` changelog section and consumes the changesets. All targets are validated before any files are written. An interrupted write leaves a `.php-changesets-transaction/` journal and blocks `status` and `version`; run `changesets recover` before retrying.

## Configuration

With no config, `init` infers the project name and version files from a root Composer manifest or WordPress plugin header. Add an explicit config when inference is ambiguous or the project needs different targets.

Use exactly one of these files:

| File | Format and intended use |
| --- | --- |
| `.php-changesets.json` | JSON configuration written by `init` when no config exists. |
| `.changeset/config.php` | PHP configuration beside the changesets; required for callback descriptors and preferred for PHP projects. |
| `config/changesets.php` | PHP configuration for projects with a shared config directory. |

A PHP config must be a regular file within the project root and return an array. The CLI does not read the upstream `.changeset/config.json` format.

### Project settings

| Key | Required | Default | Purpose |
| --- | --- | --- | --- |
| `name` | Yes | Inferred | Project identifier used in changeset frontmatter. |
| `version.from` | Yes | Inferred | One descriptor that supplies the current stable `X.Y.Z` version. |
| `version.to` | Yes | Inferred | List of descriptors to update. Use an empty list for a tag-only project. |
| `changesets` | No | `.changeset` | Directory containing changeset Markdown files. |
| `tagFormat` | No | `v{version}` | Proposed tag format; must contain `{version}` exactly once. |
| `changelog` | No | `CHANGELOG.md` | Changelog path. Set to an empty string to disable changelog generation. |

This JSON config updates a WordPress plugin header and its readme Stable tag:

```json
{
  "name": "example/plugin",
  "changesets": ".changeset",
  "tagFormat": "v{version}",
  "changelog": "CHANGELOG.md",
  "version": {
    "from": { "kind": "wordpress-plugin-header", "file": "plugin.php" },
    "to": [
      { "kind": "wordpress-plugin-header", "file": "plugin.php" },
      { "kind": "wordpress-stable-tag", "file": "readme.txt" }
    ]
  }
}
```

Paths must remain inside the project. Every target must contain the source version and resolve to exactly one location before a release is written.

### Version descriptors

| `kind` | Fields | Source or target behavior |
| --- | --- | --- |
| `composer-json` | `file` | Read or replace the top-level Composer `version` while preserving surrounding formatting. A target with `ifPresent: true` is skipped when the version is absent. |
| `wordpress-plugin-header` | `file` | Read or replace the plugin `Version` header. |
| `wordpress-stable-tag` | `file` | Read or replace the readme `Stable tag`. A target with `ifPresent: true` is skipped when the file or value is absent. |
| `php-constant` | `file`, `constant` | Read or replace a `const` declaration or `define()` value with the given constant name. |
| `git-tag` | `pattern`, optional `initialVersion` | Read the highest stable tag matching `pattern`, which defaults to `v{version}`. Source only; use an empty `version.to` list. |
| `callback` | `file`, `resolve`, optional `replace` | Resolve and replace a custom version span. Available only in PHP config files. |

For a Git-tag-only library without a stable tag yet, set an initial version explicitly:

```json
{
  "name": "example/library",
  "version": {
    "from": {
      "kind": "git-tag",
      "pattern": "v{version}",
      "initialVersion": "0.1.0"
    },
    "to": []
  }
}
```

Once a matching stable tag exists, it takes precedence over `initialVersion`. Tag-only releases do not modify `composer.json`; the CLI checks and proposes tags but does not create them.

### Callback descriptors

Use `callback` when the built-in descriptors cannot locate the version. Its callables must be deterministic and must not write files:

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
$version = [
	'kind' => 'callback',
	'file' => 'release.txt',
	'resolve' => $resolve,
];
return [
	'name' => 'example/library',
	'version' => [
		'from' => $version,
		'to' => [ $version ],
	],
];
```

Only stable `X.Y.Z` versions are supported. Pre-releases, snapshots, multi-project changesets and PECL versions are deferred.

## Development

Classes live in `php/` with Composer classmap autoloading, and tests live in `tests/phpunit/`. The `bin/changesets` entry point loads `php/bootstrap.php` so it also runs directly from a checkout. Run the package tests from the monorepo root:

```sh
docker compose run --rm wordpress vendor/bin/phpunit --testsuite php/php-changesets
```

### Architecture

| Component | Responsibility |
| --- | --- |
| `Project_Files` | Own the canonical project root and resolve safe paths within it. Shared by all project-bound services. |
| `Cli` | Resolve the project root once, construct the project-bound services, parse commands, create changesets and configuration, display plans and invoke recovery. |
| `Release_Engine` | Coordinate injected configuration, parsing, version planning, changelog generation and one ordered in-memory edit plan. |
| `Changeset_Reader` and `Version_Planner` | Read ordered changesets and select the highest bump and next stable version. |
| `Version_Files` | Resolve version descriptors and propose bounded in-memory edits without writing files. |
| `Release_Transaction` | Preflight expected contents, journal and stage edits, apply the plan and recover interrupted writes. |

`Project_Config`, `Version_Files`, `Release_Engine` and `Release_Transaction` are instance-based and receive `Project_Files` through their constructors. The engine also accepts its collaborators as optional constructor arguments for focused tests; the CLI uses the default project-bound implementations.

`status` and `version --dry-run` only inspect the plan. `version` passes the same edits to the transaction layer. Only the CLI's `init` and `add` commands and the transaction layer write project files.

PHP methods, parameters and local variables use snake_case. External JSON and configuration keys such as `currentVersion`, `nextVersion`, `tagFormat`, `initialVersion` and `ifPresent` remain camelCase because they are data-format contracts.

Releases use the monorepo's `wp-release` workflow and are split to [github.com/wpelevator/php-changesets](https://github.com/wpelevator/php-changesets).
