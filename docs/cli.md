---
title: Command line
---

## Commands

Run commands from the project root, or pass `--root DIRECTORY`.

```sh
vendor/bin/changeset init
vendor/bin/changeset add --package example/alpha --bump patch --summary "Fix the settings link."
vendor/bin/changeset status --since origin/main
vendor/bin/changeset version --dry-run
vendor/bin/changeset version
# Review and commit the updated version and changelog files.
```

| Command | Purpose and options |
| --- | --- |
| `changeset init` | Create the changeset directory and, when no config exists, write a `changesets.config.php` scaffold from the inferred project settings. Inference adds a `packages/*` group only when every matched directory declares a Composer name and version; otherwise the scaffold stays one group and carries a commented template that shows how to add more groups. A plugin scaffold also carries the opt-in `readmeChangelog` setting, commented out. |
| `changeset add` | Add a changeset. Prompts for the affected packages, their bumps and the summary in a terminal; without one, pass `--package NAME` for each affected package, or `--since REF` for the changed ones, plus `--bump patch|minor|major --summary TEXT`. A package can be named by its identifier or its path under the configured root, like `plugins/alpha`. One `--bump` applies to every selected package; repeat `--bump` after each `--package` to give each its own bump. Use `--empty` for a no-release changeset, or `--name NAME` to choose the filename. |
| `changeset status` | Show the pending release plan. With `--since REF`, or the configured base branch, also check that every package changed since then has a changeset added since then, and exit with code 2 when one doesn't. |
| `changeset version` | Apply the pending release plan. Use `--dry-run` to preview it without writes, `--date YYYY-MM-DD` to set the changelog date, `--package NAME\|PATH` (repeatable) to version only some packages, `--prerelease ID` to release pre-releases instead, like `--prerelease rc` or `--prerelease rc.2`, and `--snapshot ID` for snapshots like `1.3.0-pr-251.20261012183005`. |
| `changeset tag` | Create the missing release tags of the current versions. Use `--dry-run` or `--untagged` to list them without creating any, `--since REF` to tag only the packages changed since `REF`, `--package NAME\|PATH` (repeatable) to tag only some packages, `--version VERSION` to fail unless the one selected package is at that version, `--release-title TEMPLATE` to title the releases and `--push` to push the created tags. |
| `changeset recover` | Restore original files after an interrupted release transaction. |

Every command accepts these global options:

| Option | Purpose |
| --- | --- |
| `--root DIRECTORY` | Project root, the current directory by default. |
| `--config PATH` | Config file to load instead of `changesets.config.php`; a relative path resolves from the current directory. |
| `--tag-format FORMAT` | Replace the tag format of the config and of every package group for one command, like `--tag-format '{name}@{version}'`. Pass the same format to every command that reads or plans the same tags. |
| `--project-name NAME` | Identifier of an inferred root package, for a project without a config or a Composer `name`, so that local checkouts and CI don't depend on the directory name. A config sets the same identifier with a group `id`. |
| `--base-branch BRANCH` | Branch on the remote that `status` and `add` compare with when there's no `--since`, replacing the `baseBranch` of the config. |
| `--remote NAME` | Git remote of the base branch and of `tag --push`, replacing the `remote` of the config, `origin` by default. |
| `--json` | Print the result as JSON. |
| `--github-output` | Set the result as GitHub Actions step outputs. |
| `--log-format text\|github` | Log as plain text, or as GitHub Actions annotations and job summaries. |
| `--ci` | Shorthand for `--log-format github`. |

The CLI does not commit or publish, and only `tag` creates and, with `--push`, pushes tags. Review and commit version and changelog changes separately. A second `version` run with no pending changesets makes no release edits.

## Add a changeset

A changeset's frontmatter maps one or more package names to a `patch`, `minor` or `major` bump, and its body is a nonempty Markdown summary:

```md
---
"example/alpha": minor
---

Add a feature.
```

A single changeset may release several packages by listing one `package: bump` mapping per line, so the frontmatter is a `Record<package name, bump>` like `wp-release` expects. Commit the generated file with the change. With one package, `add` selects it automatically; with several, it prompts in a terminal or requires one repeatable `--package NAME` per affected package. Like `wp-release changeset-add`, `--package a --bump minor --package b --bump patch` writes a different bump per package:

```sh
vendor/bin/changeset add --package example/alpha --bump minor --package plugins/beta --bump patch --summary "Share the settings screen."
```

With `--since REF`, `add` compares the changes since `REF`, including uncommitted and untracked files, since changesets are usually added before committing. The prompt marks the changed packages without a changeset and Enter selects them, and a single `--bump` without `--package` releases them, like `changeset add --since origin/main --bump patch --summary "…"` to fix a failing status check.

Unknown package names, duplicated entries and unsupported YAML are rejected against the discovered package names. Multiple changesets aggregate with the highest bump per package and keep their changelog entries in filename order.

Empty frontmatter records an explicit no-release decision and applies to every package, so `--empty` needs no package selection and is consumed by the next release of all of them.

## Check pull requests for changesets

In CI, `changeset status --since origin/main` (or `changeset status` with a configured `baseBranch` of `main`) requires a changeset added by the pull request for every package that it changes. Changesets already on the base don't count, and an empty changeset added by the pull request records an explicit no-release decision. A package at the repository root owns every changed file. The command exits with code 2 and reports `Changed package "…" has no changeset.` for each missing one; with the `github` log format, the error is an annotation on a changed file of the package. `--since` needs the history of the base, so check out with `fetch-depth: 0`.

The result adds `since`, `changedPackages`, `changedPackageFiles` with the changed files of each package, and `missingChangesets` to the release plan.

## Preview and apply a release

`status --json` and `version --dry-run --json` print the `wp-release` release-plan shape: `changesets` with `file`, `packages` and `summary` items, and one `releases[]` item per discovered package with `package`, `currentVersion`, `bump`, `nextVersion`, `tag`, `changesets`, the `changelog` Markdown list added to `CHANGELOG.md` and the affected `paths`, in configured group order. A release without changesets has a null bump and tag. Dry-run JSON also includes one combined `diff` string with a `### {package}` section per release.

`version` checks that each proposed tag does not exist, updates the configured version targets of every release, prepends a `## {version} ({date})` changelog section and consumes the changesets. Every package's targets are checked before any files are written, and the edits of all releases apply as one transaction, so a failure never leaves one package released and another pending. An interrupted write leaves a `.php-changesets-transaction/` journal and blocks `status` and `version`; run `changeset recover` before retrying.

## Version some packages

To release packages separately, for example with a release pull request for each, pass `--package NAME`, repeatable, to version only those packages. A changeset that also names other packages keeps only their entries and its summary, so it still applies to their releases with the same bump. Changesets without packages are left for a run without `--package`.

`version --snapshot ID` releases snapshots of the next versions for test builds, like `1.3.0-pr-251.20261012183005` for a pull request: the next version with the identifier and the UTC time, so that every snapshot is unique and they sort by time. Snapshots can't be combined with `--prerelease`, and like a pre-release, a snapshot keeps the readme `Stable tag`. Build the versioned files without committing them, since a snapshot consumes the changesets.

## Tags and release notes

After merging a release, `changeset tag` creates the missing tags of the current versions, and `--push` pushes them to the remote one at a time, since GitHub doesn't trigger workflows for a push of more than three tags. Tags are created only when absent, so a rerun after a partial failure is safe, and every version target of a package must match the version before its tag is created. `--dry-run` lists the tags without creating them, and `--untagged` lists only the releases without a tag, for example to release each of them with a separate workflow.

With `--json` or `--github-output`, each entry of `tags` has the `package`, `version`, `tag`, a `title`, the `previousTag` when there is one, a `prerelease` flag, whether the tag already `exists` or was `created`, and the release `notes`. The title is `name@version`, or the version for a package at the repository root, unless `releaseTitle` or `--release-title` sets a template with `{name}`, `{path}`, `{version}` and `{tag}` placeholders. The notes are the changelog section of the version, or without one the commits that changed the package since the previous tag, the highest version below the released one, skipping pre-releases for a stable version.

To release a single package, pass `--package NAME` and `--version VERSION`, which fails unless the package is at that version; a sole package is selected without `--package`. A package versioned by Git tags has no version file, so `--version` is the version to tag, and without it the version is the changelog version above its latest tag. The tags are read from the local repository, so check out the full history with its tags.

## Pre-releases

There is no pre mode and no state file. Pre-releases are a per-run transform of the release plan, like in `wp-release`: `changeset version --prerelease ID` computes the stable plan first, then turns every release's version into a pre-release of that stable version.

- A bare identifier like `rc` numbers one above the highest existing `<stable>-<identifier>.` version among the package's Git tags in its tag format, so the first pre-release of a stable version is `1.2.5-rc.1`. Tag each pre-release with the release workflow; the numbering counts those tags.
- An explicit `id.n` like `rc.2` is used as is, and a run fails when that exact version already exists among the tags.
- Versions have two to four numeric parts, like `X.Y.Z` and the `X.Y` and `X.Y.Z.W` versions common in WordPress plugins, with an optional pre-release like `X.Y.Z-<tag>.<n>`; build metadata is rejected. A bump keeps the number of parts, so a minor bump of `2.1` is `2.2`, like `wp-release`.
- A stable release of a pre-release current version graduates like `semver inc`: a patch of `1.2.4-rc.1` plans `1.2.4`, and a minor of `1.3.0-rc.1` plans `1.3.0`, while a minor of `1.2.4-rc.1` plans `1.3.0`.
- The readme `Stable tag` keeps the last stable version for a pre-release, since WordPress.org offers the stable tag to every site.
- With a `nextVersion` hook, the transform applies to the stable version that the hook returns.

The recommended flow is the `wp-release` one: cut a pre-release on a branch that isn't merged with `changeset version --prerelease rc`, tag it with the release workflow, and later run `changeset version` without the flag on the base branch to release the stable version.

## GitHub Actions output

`--github-output` writes the complete result as compact JSON to the `result` step output and each top-level string, number or boolean, like the dry-run `diff`, as its own output, like `wp-release`. Arrays and objects stay in `result`, so read them with `fromJSON( steps.tag.outputs.result ).tags`.

## Annotations and job summaries

`--log-format github`, the default when `GITHUB_ACTIONS=true` and also selected by `--ci`, prints errors as [workflow commands](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-commands), so they show up as annotations on the run and on the files of a pull request. Version mismatches are annotated on the file and line of the version, and missing changesets on a changed file of the package. File paths are relative to `GITHUB_WORKSPACE`, or the current directory without it. Pass `--log-format text` for plain text in GitHub Actions.

When `GITHUB_STEP_SUMMARY` is set, each command adds a section to the job summary of the run:

| Command | Summary |
| --- | --- |
| `status` | Each changed package with whether it has a changeset. |
| `version` | A table of the package releases with their version changes and bumps. |
| `tag` | Each tag with its title, previous tag and status, and its release notes in a collapsed section. |

A failed command adds its error to the summary, or a table of its problems with their files and lines.

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | Success, dry run, no changes, or help |
| `1` | Unexpected failure, such as a failed git command |
| `2` | Invalid options, failed release checks like a version mismatch or an existing tag, or changed packages without a changeset |
