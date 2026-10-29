# PHP Changesets

Prepare releases for one PHP project with changeset Markdown files, PHP and Git. This package is currently developed in the monorepo and is not published yet. Install it in a consumer through a Composer path repository during development; after publication, install `wpelevator/php-changesets` as a development dependency and run `vendor/bin/changesets`. Node is not required at runtime. PHP 7.4 or newer is required.

```sh
composer require --dev wpelevator/php-changesets # After publication.
vendor/bin/changesets init
vendor/bin/changesets add --bump patch --summary "Fix the settings link."
vendor/bin/changesets status --json
vendor/bin/changesets version --dry-run
vendor/bin/changesets version
vendor/bin/changesets recover
```

`init` creates `.changeset/` and, when no config exists, `.php-changesets.json` from the inferred project settings. `add` prompts for missing bump and summary in a terminal; use `--empty` to record a change that needs no release, or `--name NAME` to choose the filename. Commit the generated changeset with the change. `status` reports the pending plan; `version --dry-run` previews the file diff and `version --dry-run --json` includes it in the JSON result. `version --date YYYY-MM-DD` selects the changelog date. Changelog sections use the wp-release heading format `## {version} ({date})`. All commands accept `--root DIRECTORY` (default: current directory).

Review and commit the resulting version and changelog changes separately. The CLI does not commit, tag, push or publish. It checks that the proposed tag does not already exist before applying a release. An interrupted write leaves a `.php-changesets-transaction/` journal and blocks `status` and `version`; run `vendor/bin/changesets recover` to restore the original files before retrying. A tag-only Composer library without a `version` field uses the highest stable `vX.Y.Z` Git tag and does not modify `composer.json`; create an initial stable tag or explicitly configure an initial version before planning a release. A second `version` run with no pending changesets makes no release edits.

The supported changeset format has one quoted or unquoted project name, a `patch`, `minor` or `major` bump, and a nonempty Markdown summary between frontmatter and the end of the file. Empty frontmatter records no release. Unknown or multiple packages and unsupported YAML are rejected. Multiple changesets use the highest bump and preserve their ordered notes. Only stable `X.Y.Z` versions are supported.

```md
---
"example/plugin": minor
---

Add a feature.
```

Configuration can live in `.php-changesets.json`, `.changeset/config.php`, or `config/changesets.php`. The preferred PHP location is `.changeset/config.php`, beside the changeset files; `config/changesets.php` fits projects with a shared config directory. Use exactly one of these files. A PHP config must return an array and may define callbacks. The PHP CLI does not read the upstream `.changeset/config.json` file. Set a single version source and explicit targets. This JSON example updates a WordPress plugin header and its readme:

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

A Git source uses `{ "kind": "git-tag", "pattern": "v{version}" }` and can have an empty `to` list. For a repository with no matching stable tag, set `initialVersion` on this source to an explicit stable version, for example `{ "kind": "git-tag", "pattern": "v{version}", "initialVersion": "0.1.0" }`. Once a matching stable tag exists, the highest matching tag supplies the current version. Other file descriptor kinds include `composer-json` and `php-constant`; the latter also requires a `constant` name. `ifPresent: true` makes a `wordpress-stable-tag` or `composer-json` target optional. Set `changelog` to an empty string to disable its generation. Paths must remain inside the project. Version targets must match the source version and resolve unambiguously before any files are written.

For a version format that the built-in descriptors do not cover, use a `callback` descriptor in either PHP config location. Its `resolve` callable receives the file contents and returns an array with the version `value`, zero-based byte `offset`, and byte `length`, or `null` when absent. The returned span must contain exactly the version string. By default, the CLI replaces only that span. An optional `replace` callable receives the original contents, old version, and new version and returns the complete proposed contents. The CLI checks that `resolve` finds the new version in either result before writing. The same descriptor can be both the source and a target:

```php
<?php
$resolve = static function ( string $contents ): ?array {
    if ( ! preg_match( '/^Release: (\d+\.\d+\.\d+)$/m', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
        return null;
    }
    return array( 'value' => $matches[1][0], 'offset' => $matches[1][1], 'length' => strlen( $matches[1][0] ) );
};
$version = array( 'kind' => 'callback', 'file' => 'release.txt', 'resolve' => $resolve );
return array( 'name' => 'example/library', 'version' => array( 'from' => $version, 'to' => array( $version ) ) );
```

The `resolve` callback runs for status and release planning; `replace` runs when a version bump is planned, including dry runs. Keep both deterministic and free of writes; returned contents join the same checked release transaction as built-in targets. Callback descriptors are available only in PHP config files; JSON cannot invoke PHP functions. A PHP config must be a regular file within the project root, and the CLI rejects a project with both config formats.

`status --json` and `version --dry-run --json` report `project`, `currentVersion`, `bump`, `nextVersion`, `tag`, ordered `changesets`, `notes`, and affected `paths`; the dry run also reports `diff`. No-release plans have a null bump and tag. Pre-releases, snapshots, multi-package changesets, and PECL versions are deferred.

## Development

Classes live in `php/` with Composer classmap autoloading, and tests live in `tests/phpunit/`, matching this monorepo's PHP package layout. The `bin/changesets` entry point loads `php/bootstrap.php` so it also runs directly from a checkout. Run `docker compose run --rm wordpress vendor/bin/phpunit --testsuite php/php-changesets` from the monorepo root to test the package.

The first MVP tag `php/php-changesets/0.1.0` was created separately, because the PHP CLI does not create tags. The package now releases through the monorepo's `wp-release` workflow like the other `php/*` packages, and its split push still needs the `wpelevator/php-changesets` release repository.
