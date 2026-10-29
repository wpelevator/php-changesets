---
title: GitHub Actions
---

## Release with GitHub Actions

The CLI prepares releases, and other actions publish them. These workflows check that pull requests add changesets, open a release pull request whenever changesets are pending on `main`, and after it's merged, create the tag and GitHub release of every released package. Packagist picks up the new tags of Composer packages; add the [WP Release Zip](https://github.com/marketplace/actions/wp-release-zip) and [WP Release Deploy SVN](https://github.com/marketplace/actions/wp-release-deploy-svn) actions to the `release` job to ship a WordPress plugin.

### Check pull requests

```yaml
name: Changesets

on:
  pull_request:

jobs:
  status:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
        with:
          fetch-depth: 0
      - uses: shivammathur/setup-php@v2
      - run: composer install --no-progress
      - run: vendor/bin/changeset status --since origin/${{ github.base_ref }} --ci
```

A pull request that changes a package without adding a changeset fails with an annotation on a changed file of the package and a job summary of the changed packages. Contributors fix it with `changeset add`, or with `changeset add --empty` for a change that needs no release.

### Release

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
      untagged: ${{ steps.untagged.outputs.result }}
    steps:
      - uses: actions/checkout@v7
        with:
          fetch-depth: 0
      - uses: shivammathur/setup-php@v2
      - run: composer install --no-progress
      - id: status
        run: vendor/bin/changeset status --github-output
      - id: untagged
        run: vendor/bin/changeset tag --untagged --github-output

  release-pr:
    needs: plan
    if: fromJSON( needs.plan.outputs.status ).changesets[0] != null
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
        with:
          fetch-depth: 0
      - uses: shivammathur/setup-php@v2
      - run: composer install --no-progress
      - run: vendor/bin/changeset version
      - uses: peter-evans/create-pull-request@v7
        with:
          branch: changeset-release/main
          title: Release
          commit-message: Release

  release:
    needs: plan
    if: fromJSON( needs.plan.outputs.untagged ).tags[0] != null
    runs-on: ubuntu-latest
    strategy:
      matrix:
        release: ${{ fromJSON( needs.plan.outputs.untagged ).tags }}
    steps:
      - uses: actions/checkout@v7
        with:
          fetch-depth: 0
      - uses: shivammathur/setup-php@v2
      - run: composer install --no-progress
      - name: Tag the release
        id: tag
        run: vendor/bin/changeset tag --package "$PACKAGE" --version "$VERSION" --push --github-output
        env:
          PACKAGE: ${{ matrix.release.package }}
          VERSION: ${{ matrix.release.version }}

      # Creates the GitHub Release, or updates it on a rerun.
      - uses: softprops/action-gh-release@v3
        with:
          tag_name: ${{ fromJSON( steps.tag.outputs.result ).tags[0].tag }}
          name: ${{ fromJSON( steps.tag.outputs.result ).tags[0].title }}
          body: ${{ fromJSON( steps.tag.outputs.result ).tags[0].notes }}
          prerelease: ${{ fromJSON( steps.tag.outputs.result ).tags[0].prerelease }}
```

The jobs need the full history (`fetch-depth: 0`) for the tags. `tag --untagged` lists the releases that still need a tag, and each `release` job tags one of them, checking that the package is at the listed version, and pushes the tag. The release notes come from the changelog section of each released version, and the titles are `name@version`, or the version for a package at the repository root, like `wp-release`. Tags pushed with the `GITHUB_TOKEN` don't trigger other workflows; pass a personal access token or GitHub App token to `actions/checkout` when a tag push should start one, like a Packagist or WordPress.org deploy.
