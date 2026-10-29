---
title: Comparison with wp-release
---

## Comparison with wp-release

PHP Changesets is the PHP counterpart of the `changeset-*` commands of [`wp-release`](../../../js/wp-release/docs/index.md), for projects that release with PHP and Git alone and don't use Node.js or JavaScript. Choose `wp-release` when the project already has a Node.js toolchain, or needs its builds, ZIPs, translations and deploys, and PHP Changesets when Composer is the only package manager. Both read the same changeset files and print the same `releases[]` plan, so a repository can switch between them.

| PHP Changesets | wp-release | Notes |
| --- | --- | --- |
| `changeset init` | No equivalent | Scaffolds `changesets.config.php` from the inferred project. |
| `changeset add` | `changeset-add` | Same options, including `--since` for the changed packages. |
| `changeset status` | `changeset-status` | Same `--since` check, exit code and result fields. |
| `changeset version` | `changeset-version` | Same options, without `--no-dependents`. |
| `changeset tag` | `changeset-tag` | Same options, including `--untagged` and `--push`. |
| `changeset recover` | No equivalent | Restores files after an interrupted release transaction. |
| `changesets.config.php` | `wp-release.config.mjs` | `define_config()` and the descriptor functions mirror `defineConfig()` and its builders. |

They also share these conventions: `--json` and `--github-output` on every command, `--log-format github` annotations and job summaries, global `--config`, `--tag-format` and `--project-name`, the `remote` and `baseBranch` settings with their `--remote` and `--base-branch` options, the `{path}/{version}` tag format with `v{version}` for an inferred root project, and the exit codes 0, 1 and 2.

### Deliberate differences

- The command is `changeset` with subcommands, like `changeset add`, where `wp-release` has `changeset-add` among its other commands. Run `changeset --help` for the commands.
- No single-project version flags like `--version-composer-json`: without a config, the project is inferred, and `changeset init` writes the inferred settings to `changesets.config.php` to change them.
- No `changelogInsertAfter`: new `CHANGELOG.md` sections go before the first version heading, and readme sections after the `== Changelog ==` heading.
- `tag` creates and pushes tags but not GitHub Releases. A workflow step like `softprops/action-gh-release` creates them from its result; see [GitHub Actions](github-actions.md).
- No `releaseNotes` callback: the release notes are the changelog section, or the commits since the previous tag.
- No library of primitives: the package is the config-driven CLI and its classes, without build, ZIP, checksum or deploy functions.
- A package at the repository root needs an `id`, a manifest name or `--project-name`, and the name of the checkout directory never names a package, so changesets keep working in forks, worktrees and CI.
- Only the literal `.` glob matches the package root, while in `wp-release` a pattern like `*` also matches it.
- A pre-release current version graduates like `semver inc` on a stable release, where `wp-release` rejects bumping one.
- Package groups can set their own `changelog` and `tagFormat`.

### Excluded by design

These `wp-release` features are left out, and the CLI never commits or publishes versions. Their settings and options are rejected as unknown:

- Fixed and linked version groups: every package releases from its own changesets and version descriptors.
- The dependents system and `version --no-dependents`: bundled or dependent packages release with their own changesets, not automatically after a source release.
- Publishing: no builds, ZIPs, i18n extraction, git commits, or package registry and WordPress.org deploys. The CLI only writes version, changelog and changeset files and creates the release tags.
