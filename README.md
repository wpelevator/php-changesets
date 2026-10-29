# PHP Changesets

Prepare multi-package releases for PHP projects and monorepos with changeset Markdown files, PHP and Git. Released as [`wpelevator/php-changesets`](https://github.com/wpelevator/php-changesets).

Contributors describe each change in a small Markdown file. When you release, the `changeset` command bumps the versions of every changed package, writes the changelogs and creates the release tags. It never commits or publishes: those steps belong to your workflow. It's for projects that don't use Node.js or JavaScript, and follows the release model of [`wp-release`](../../js/wp-release/README.md), the Node.js release tool with the same changeset commands.

The detailed documentation lives in [this package's `docs/` directory](docs/index.md): the [command line](docs/cli.md), [configuration](docs/configuration.md), [GitHub Actions](docs/github-actions.md) workflows, the [comparison with wp-release](docs/comparison.md) and [development](docs/development.md). The monorepo docs site builds it for [`wpelevator.github.io/docs/php/php-changesets/latest/`](https://wpelevator.github.io/docs/php/php-changesets/latest/).

## Quickstart

For a WordPress plugin or a Composer package at the repository root, with PHP 7.4 or later and Git:

```sh
composer require --dev wpelevator/php-changesets
vendor/bin/changeset init                  # Infer and write changesets.config.php
vendor/bin/changeset add                   # Describe a change; commit the file with it
vendor/bin/changeset status --since origin/main  # Check that changed packages have changesets
vendor/bin/changeset version --dry-run     # Preview the versions, changelog and diff
vendor/bin/changeset version               # Apply them; review and commit
vendor/bin/changeset tag                   # After merging: create the release tags, like v1.3.0
```

For development inside this monorepo, run the executable with `php packages/php/php-changesets/bin/changeset` or through the root Composer installation as `vendor/bin/changeset`, and the tests with `docker compose run --rm wordpress vendor/bin/phpunit --testsuite php/php-changesets`.

Releases use the monorepo's `wp-release` workflow and are split to [github.com/wpelevator/php-changesets](https://github.com/wpelevator/php-changesets).
