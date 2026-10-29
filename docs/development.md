---
title: Development
---

## Development

Classes live in `php/` with Composer classmap autoloading, and tests live in `tests/phpunit/`. The `bin/changeset` entry point loads `php/bootstrap.php` so it also runs directly from a checkout. Run the package tests from the monorepo root:

```sh
docker compose run --rm wordpress vendor/bin/phpunit --testsuite php/php-changesets
```

### Architecture

| Component | Responsibility |
| --- | --- |
| `Project_Files` | Hold the canonical project root and resolve safe paths inside it, also for a package directory. The other project classes share it. |
| `Config_Schema` and `functions.php` | Validate the workspace configuration and its hooks through `define_config()`, and build the shared version descriptors with the namespaced builder functions, which Composer autoloads as `files`. |
| `Package_Discovery` | Find package directories from the configured globs, with id resolution and duplicate and empty-match detection. |
| `Cli` | Resolve the project root once, construct the project-bound services, parse commands and global options, prompt for package selection and changeset content, create changesets and configuration, print results and step outputs, set the exit code and invoke recovery. |
| `Git` | Run git commands in one directory and throw `Git_Exception` on failures, which exit with code 1. Tests replace it with a subclass. |
| `Logger` | Log plain text, or GitHub Actions annotations and job summaries with the `github` log format. |
| `Release_Engine` | Load the configuration and changesets, plan the versions, turn them into pre-releases for `--prerelease` or snapshots for `--snapshot`, version only the `--package` selection, format the changelog and readme changelog entries with the hooks, and build one ordered in-memory edit plan. It also finds the changed packages without a changeset since a ref, and creates and pushes the tags that released versions still need, with their titles and release notes. |
| `Semver` | Parse, bump and compare the supported stable and pre-release versions, and number the pre-release versions of the stable plan from the package tags. |
| `Tag_Format` | Expand the `{name}`, `{path}` and `{version}` placeholders of tag formats and git-tag patterns, like `wp-release` `formatTag()`. |
| `Changeset_Reader` and `Version_Planner` | Read ordered multi-package changesets and assemble the `releases[]` plan of every package, with the highest bump, next version, tag and changelog. |
| `Version_Files` | Resolve version descriptors and propose bounded in-memory edits without writing files. |
| `Release_Transaction` | Check the expected contents, journal and stage the edits of every package, apply them as one transaction and recover interrupted writes. |

`Project_Config`, `Version_Files`, `Release_Engine` and `Release_Transaction` are instance-based and receive `Project_Files` through their constructors. Constructors only store their dependencies, and the optional `Git`, `Semver` and `Tag_Format` arguments of `Release_Engine`, `Version_Files` and `Version_Planner` default to new instances, so tests can replace them. Validation errors are `Release_Exception`s, which exit with code 2 and can list problems with their files and lines for annotations. Package directories come from `Package_Discovery` in configured group order; the engine plans one release per discovered package and applies their combined edits as one transaction.

`status` and `version --dry-run` only inspect the plan. `version` passes the same edits to the transaction layer. Only the CLI's `init` and `add` commands and the transaction layer write project files.

PHP methods, parameters and local variables use snake_case. External JSON and configuration keys such as `currentVersion`, `nextVersion`, `tagFormat`, `initialVersion` and `ifPresent` stay camelCase, since other tools read them.

Releases use the monorepo's `wp-release` workflow and are split to [github.com/wpelevator/php-changesets](https://github.com/wpelevator/php-changesets).
