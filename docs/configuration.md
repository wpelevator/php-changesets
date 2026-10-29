---
title: Configuration
---

## Configuration

The configuration lives in `changesets.config.php` at the project root, the analog of `wp-release.config.mjs`. The global `--config PATH` selects another file for one command.

With no config, `init` infers the single project from a root Composer manifest or WordPress plugin headers as one group, tagged `v{version}` like the `wp-release` single-project default. The package is named by its Composer `name`, or for a plugin without one, by the slug of its main plugin file, like `demo` for `demo.php`, never by the checkout directory. `--project-name NAME` names any other project without a config. Readme changelog updates are opt-in, like in `wp-release`, so the scaffold of a plugin carries `readmeChangelog` commented out. When every directory under `packages/*` declares a Composer name and version, inference adds a second group for them. Other layouts keep the single group, and the scaffold carries a commented template that shows how to add groups. The config lives outside the changeset directory, so `.changeset/` holds only the Markdown changesets and its `README.md`.

The loader rejects the removed formats `.php-changesets.json`, `.changeset/config.php` and `config/changesets.php` with guidance pointing at `changesets.config.php`. It also rejects the upstream Changesets `.changeset/config.json`, since both tools would read and consume the same changeset files.

The config file is regular PHP (loaded with `require`) and must return an array, normally the result of `define_config()`, which validates the settings and hooks eagerly at load time and returns their canonical shape. The config helpers are namespaced functions, the PHP analogs of the `wp-release` `defineConfig()` and descriptor builders; import them with `use function`.

### Workspace settings

The canonical configuration is a workspace with package groups; a single-project config is one group covering the repository root:

| Key | Required | Default | Purpose |
| --- | --- | --- | --- |
| `packages` | Yes | — | Non-empty list of package groups, in release order. |
| `changesets` | No | `.changeset` | Directory containing changeset Markdown files, relative to the repository root. |
| `tagFormat` | No | `{path}/{version}` | Proposed tag format, like `plugins/alpha/1.2.0`, or `1.2.0` for a package at the root; must contain `{version}` exactly once and may use `{name}` and `{path}`. The default matches `wp-release`, and the global `--tag-format` replaces it and the group formats for one command. |
| `releaseTitle` | No | `null` | Title of the releases that `tag` reports, as a template with `{name}`, `{path}`, `{version}` and `{tag}` placeholders, like `'{version} (beta)'`, or a callable that receives `[ 'package' => [ 'name' => …, 'path' => … ], 'version' => …, 'tag' => … ]`. `null` titles them `name@version`, or the version for a package at the root. `--release-title` replaces it for one command. |
| `root` | No | `null` | Package path base that package globs resolve against; `null` uses the repository root. |
| `remote` | No | `origin` | Git remote of the base branch and of `tag --push`. |
| `baseBranch` | No | `null` | Branch on the remote that `status` and `add` compare with when there's no `--since`, like `main` for `origin/main`. |
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
| `idFrom` | No | `path` | Package name source: `composer.json#name`, `package.json#name`, or `path`. The package at the root (`.`) has no path, so it needs an `id` or a manifest source. Unlike in `wp-release`, the name of the checkout directory never names a package, so changesets keep working in forks, worktrees and CI. |
| `version` | Yes | — | `{ from, to }` descriptors; every releasable group needs one. |
| `changelog` | No | Inherited | Per-package changelog path; empty string disables, `null` inherits. `wp-release` has no per-group setting and always writes `CHANGELOG.md`. |
| `tagFormat` | No | Inherited | Per-package tag format; `null` inherits. `wp-release` only has the repository setting. |

Discovery walks the directories below `root`, bounded by the deepest configured glob, and never enters `.git`, `node_modules`, `vendor` or `dist`. A glob that matches no directory, and duplicate package identifiers across groups, are configuration errors. Only the literal `.` glob matches the package root. Patterns like `*` and `packages/**` never cover it, while in `wp-release` a pattern like `*` also matches the root. Group settings and descriptor files must resolve inside the package, like all project paths.

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

`define_config( array $config )` validates a workspace configuration when it loads, rejects unknown or malformed settings and returns the canonical shape that the other classes read.

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

Once a matching stable tag exists, it takes precedence over `initialVersion`. Tag-only releases do not modify `composer.json`: the release is recorded in the changelog, and `tag` tags the changelog version above the latest tag.

### Callback descriptors

Use `callback_version()` when the built-in descriptors can't find the version. Its callables must return the same result for the same contents and must not write files:

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
