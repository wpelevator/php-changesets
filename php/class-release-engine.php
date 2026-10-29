<?php

namespace WPElevator\PHP_Changesets;

/**
 * Plan the releases of every discovered package, then delegate the checked
 * application of their combined edits.
 */
final class Release_Engine {

	private Project_Files $files;

	private Project_Config $config_loader;

	private Changeset_Reader $reader;

	private Version_Planner $planner;

	private Version_Files $versions;

	private Release_Transaction $transaction;

	private Semver $semver;

	private Tag_Format $tag_format;

	private Git $git;

	public function __construct(
		Project_Files $files,
		?Project_Config $config_loader = null,
		?Git $git = null,
		?Semver $semver = null,
		?Tag_Format $tag_format = null
	) {
		$this->files = $files;
		$this->config_loader = $config_loader ?? new Project_Config( $files );
		$this->git = $git ?? new Git( $files->get_root() );
		$this->semver = $semver ?? new Semver();
		$this->tag_format = $tag_format ?? new Tag_Format();
		$this->reader = new Changeset_Reader();
		$this->planner = new Version_Planner( $this->semver, $this->tag_format );
		$this->versions = new Version_Files( $files, $this->git, $this->semver, $this->tag_format );
		$this->transaction = new Release_Transaction( $files );
	}

	/**
	 * Plan all file changes of every release before writing any of them.
	 *
	 * The public plan keys retain their camelCase JSON names. Each internal edit
	 * has an absolute path, the expected old contents or null, and the proposed
	 * new contents or null for a deletion. Sections bundle the edits of one
	 * release for the dry-run diff; a trailing section carries the no-op
	 * changesets that every release consumes together.
	 *
	 * With `packages`, like wp-release `changeset-version --package`, only
	 * those packages release: a changeset that also names other packages keeps
	 * only their entries, so it still applies to their releases, and
	 * changesets without packages are left for a run without packages.
	 *
	 * @param array{date?: string|null, prerelease?: string|null, snapshot?: string|null, time?: string, packages?: string[]} $options
	 *     The changelog date (today by default), a pre-release or snapshot identifier, the UTC
	 *     time of a snapshot, and the identifiers or paths of the packages to version.
	 * @return array{changesets: array<int, array{file: string, packages: array<string, string>, summary: string}>, releases: array<int, array{package: string, currentVersion: string, bump: string|null, nextVersion: string, tag: string|null, changesets: array<int, array{file: string, packages: array<string, string>, summary: string}>, changelog: string, paths: string[]}>, edits: array<int, array{path: string, old: ?string, new: ?string}>, sections: array<int, array{title: string, edits: array<int, array{path: string, old: ?string, new: ?string}>}>}
	 */
	public function plan( array $options = [] ): array {
		if ( $this->transaction->has_pending() ) {
			throw new Release_Exception( 'An interrupted release is pending; run changeset recover.' );
		}

		$prerelease = $options['prerelease'] ?? null;
		$snapshot = $options['snapshot'] ?? null;

		if ( null !== $prerelease && null !== $snapshot ) {
			throw new Release_Exception( '--prerelease cannot be combined with --snapshot.' );
		}

		$config = $this->config_loader->load();
		$discovery = new Package_Discovery( $this->files );
		$packages = $discovery->discover( $config );
		$directory = $this->files->resolve( $config['changesets'] );
		$changesets = $this->reader->read( $directory, array_column( $packages, 'name' ) );
		$selected = $discovery->names( $packages, $options['packages'] ?? [] );

		if ( [] !== $selected ) {
			$changesets = $this->selected_changesets( $changesets, $selected );
		}

		$current_versions = $this->read_versions( $config, $packages );
		$releases = $this->planner->plan( $config, $packages, $changesets, $current_versions );

		if ( null !== $prerelease ) {
			// Like wp-release, the pre-release transform also applies to the
			// stable versions that a nextVersion hook returns.
			$releases = $this->to_prereleases( $config, $packages, $releases, $prerelease );
		} elseif ( null !== $snapshot ) {
			$releases = $this->to_snapshots( $config, $packages, $releases, $snapshot, $options['time'] ?? gmdate( 'YmdHis' ) );
		}

		$date = $options['date'] ?? null;
		$root = $this->files->get_root();
		$planned_targets = [];
		$sections = [];
		$consumed_edits = [];

		foreach ( $releases as $index => $release ) {
			$package = $packages[ $index ];
			$paths = [];
			$release_edits = [];

			if ( null !== $release['bump'] ) {
				$release_edits = $this->version_edits( $config, $package, $release, $planned_targets, $date ?? gmdate( 'Y-m-d' ) );
				$releases[ $index ]['changelog'] = implode( "\n", $this->changelog_entries( $config['changelogEntry'] ?? null, $package, $release ) );
			}

			foreach ( $release_edits as $edit ) {
				$paths[] = $this->relative_path( $edit['path'] );
			}

			$consumed = [];

			foreach ( $changesets as $changeset ) {
				if ( isset( $changeset['packages'][ $release['package'] ] ) ) {
					$paths[] = ltrim( substr( $changeset['file'], strlen( $root ) ), '/' );
					$consumed[] = $this->consume_edit( $changeset );
				}
			}

			$releases[ $index ]['paths'] = $paths;
			$sections[] = [
				'title' => $release['package'],
				'edits' => $this->combine_edits( array_merge( $release_edits, $consumed ) ),
			];
			$consumed_edits = array_merge( $consumed_edits, $release_edits, $consumed );
		}

		// No-release changesets are consumed for every package, so they land in
		// a trailing section named after the changeset directory.
		$no_release_edits = [];

		foreach ( $changesets as $changeset ) {
			if ( [] === $changeset['packages'] ) {
				$no_release_edits[] = [
					'path' => $changeset['file'],
					'old' => (string) file_get_contents( $changeset['file'] ),
					'new' => null,
				];
			}
		}

		if ( [] !== $no_release_edits ) {
			$consumed_edits = array_merge( $consumed_edits, $no_release_edits );
			$sections[] = [
				'title' => $config['changesets'],
				'edits' => $no_release_edits,
			];
		}

		return [
			'changesets' => array_map(
				static fn( array $record ): array => [
					'file' => $record['name'],
					'packages' => $record['packages'],
					'summary' => $record['summary'],
				],
				$changesets
			),
			'releases' => array_values( $releases ),
			'edits' => $this->combine_edits( $consumed_edits ),
			'sections' => $sections,
		];
	}

	/**
	 * Create the missing release tags of the current package versions, like
	 * wp-release `changeset-tag`. Tags are created only when absent, so a
	 * rerun after a partial failure is safe, and every version target of a
	 * package must match the version before its tag is created.
	 *
	 * A package versioned by git tags has no version file, so its version is
	 * the expected `version`, or the latest changelog section above its latest
	 * tag. The release notes are the changelog section of the version, or the
	 * commits that changed the package since its previous tag.
	 *
	 * @param array{dryRun?: bool, untagged?: bool, since?: string|null, packages?: string[], version?: string|null, releaseTitle?: string|null, push?: bool} $options
	 *     List the tags without creating them, list only the untagged releases, tag only the
	 *     packages changed since a ref, tag only some packages, the expected version of the
	 *     one selected package, a release title template, and push the created tags.
	 * @return array{tags: array<int, array{package: string, version: string, tag: string, title: string, previousTag?: string, prerelease: bool, exists: bool, created: bool, notes: string}>}
	 */
	public function tag( array $options = [] ): array {
		if ( $this->transaction->has_pending() ) {
			throw new Release_Exception( 'An interrupted release is pending; run changeset recover.' );
		}

		$untagged = $options['untagged'] ?? false;
		$push = $options['push'] ?? false;

		if ( $untagged && $push ) {
			throw new Release_Exception( 'Listing untagged releases cannot be combined with pushing tags.' );
		}

		$config = $this->config_loader->load();
		$release_title = $options['releaseTitle'] ?? null;

		if ( null !== $release_title ) {
			if ( ! ( new Config_Schema( $this->semver ) )->is_release_title( $release_title ) ) {
				throw new Release_Exception( '--release-title needs a {name}, {path}, {version} or {tag} placeholder, since it would name every release alike.' );
			}

			$config['releaseTitle'] = $release_title;
		}

		$packages = ( new Package_Discovery( $this->files ) )->discover( $config );
		$versions = $this->read_versions( $config, $packages );
		$expected = $options['version'] ?? null;
		$selected = $this->tag_packages( $packages, $options['packages'] ?? [], $expected );
		$since = $options['since'] ?? null;

		if ( null !== $since ) {
			$changed_files = $this->changed_files( $since, false );
			$selected = array_values( array_filter( $selected, fn( array $package ): bool => [] !== $this->package_files( $this->relative_path( $package['directory'] ), $changed_files ) ) );
		}

		$dry_run = ( $options['dryRun'] ?? false ) || $untagged;
		$tags = [];

		foreach ( $selected as $package ) {
			$tags[] = $this->release_tag( $config, $package, $versions[ $package['name'] ], $expected, $dry_run );
		}

		// Push one tag at a time, since GitHub doesn't trigger workflows for a
		// push of more than three tags.
		foreach ( $push && ! $dry_run ? $tags : [] as $tag ) {
			if ( $tag['created'] ) {
				$this->git->run( [ 'push', $config['remote'], $tag['tag'] ] );
			}
		}

		return [ 'tags' => $untagged ? array_values( array_filter( $tags, static fn( array $tag ): bool => ! $tag['exists'] ) ) : $tags ];
	}

	/**
	 * The git ref that `status` and `add` compare with by default, like the
	 * wp-release `<remote>/<baseBranch>`, or null without a base branch.
	 */
	public function base_ref(): ?string {
		$config = $this->config_loader->load();

		return null === $config['baseBranch'] ? null : $config['remote'] . '/' . $config['baseBranch'];
	}

	/**
	 * The pending releases and, compared with a base ref like wp-release
	 * `changeset-status --since`, the changed packages without a changeset
	 * added since that ref. Changesets already on the base don't count, and an
	 * empty changeset added since the ref records an explicit no-release
	 * decision for every package. A package at the repository root owns every
	 * changed file. Without a ref, the packages compare with the base ref of
	 * the configured remote and base branch, if any.
	 *
	 * @param bool $uncommitted Whether to include uncommitted and untracked files, like before adding a changeset.
	 * @return array{since?: string, changesets: array, releases: array, changedPackages: string[], changedPackageFiles: array<string, string[]>, missingChangesets: string[]}
	 */
	public function status( ?string $since = null, bool $uncommitted = false ): array {
		$plan = $this->plan();
		$since = $since ?? $this->base_ref();
		$result = null === $since ? [] : [ 'since' => $since ];
		$result += [
			'changesets' => $plan['changesets'],
			'releases' => $plan['releases'],
			'changedPackages' => [],
			'changedPackageFiles' => [],
			'missingChangesets' => [],
		];

		if ( null !== $since ) {
			$config = $this->config_loader->load();
			$packages = ( new Package_Discovery( $this->files ) )->discover( $config );
			$changed_files = $this->changed_files( $since, $uncommitted );

			foreach ( $packages as $package ) {
				$files = $this->package_files( $this->relative_path( $package['directory'] ), $changed_files );

				if ( [] !== $files ) {
					$result['changedPackages'][] = $package['name'];
					$result['changedPackageFiles'][ $package['name'] ] = $files;
				}
			}

			$added = $this->changesets_since( $config, $plan['changesets'], $since, $uncommitted );
			$result['missingChangesets'] = in_array( [], array_column( $added, 'packages' ), true ) ? [] : array_values(
				array_filter(
					$result['changedPackages'],
					static fn( string $name ): bool => [] === array_filter( $added, static fn( array $changeset ): bool => isset( $changeset['packages'][ $name ] ) )
				)
			);
		}

		return $result;
	}

	public function apply( array $plan ): void {
		$this->transaction->apply( $plan['edits'] );
	}

	/**
	 * Read every package version and preflight its declared targets, so that
	 * mismatched or missing sources fail before anything planned below.
	 *
	 * @param array<int, array{name: string, path: string, directory: string, group: int}> $packages
	 * @return array<string, string> Package name to current version.
	 */
	private function read_versions( array $config, array $packages ): array {
		$versions = [];

		foreach ( $packages as $package ) {
			$group = $config['packages'][ $package['group'] ];
			$version_files = $this->versions_for( $package );
			$current = $version_files->read_current( $this->source( $config, $package ), $package );

			foreach ( $group['version']['to'] as $target ) {
				if ( ! $this->keeps_stable_tag( $target, $current ) ) {
					$version_files->propose( $target, $current, $current );
				}
			}

			$versions[ $package['name'] ] = $current;
		}

		return $versions;
	}

	/**
	 * The files changed since the merge base of a ref, relative to the
	 * project root, like the wp-release `getChangedFiles()`: with uncommitted
	 * files, the diff of the working tree and the untracked files.
	 *
	 * @param string|null $directory   Directory under the project root to limit the files to.
	 * @param string      $diff_filter Git diff filter, like ACMR for added and changed files.
	 * @return string[]
	 */
	private function changed_files( string $since, bool $uncommitted, ?string $directory = null, string $diff_filter = '' ): array {
		$pathspec = null === $directory ? [] : [ '--', '' === $directory ? '.' : $directory ];
		$range = $uncommitted ? trim( $this->git->run( [ 'merge-base', $since, 'HEAD' ] ) ) : $since . '...HEAD';
		$filter = '' === $diff_filter ? [] : [ '--diff-filter=' . $diff_filter ];
		$output = $this->git->run( array_merge( [ 'diff', '--name-only', '--relative' ], $filter, [ $range ], $pathspec ) );

		if ( $uncommitted ) {
			$output .= "\n" . $this->git->run( array_merge( [ 'ls-files', '--others', '--exclude-standard' ], $pathspec ) );
		}

		return array_values( array_unique( array_filter( preg_split( '/\r?\n/', $output ), 'strlen' ) ) );
	}

	/**
	 * The changed files of a package directory; a package at the root owns all.
	 *
	 * @param string[] $changed_files
	 * @return string[]
	 */
	private function package_files( string $path, array $changed_files ): array {
		return array_values(
			array_filter(
				$changed_files,
				static fn( string $file ): bool => '' === $path || $file === $path || 0 === strpos( $file, $path . '/' )
			)
		);
	}

	/**
	 * The pending changesets that were added or changed since a ref.
	 *
	 * @param array<int, array{file: string, packages: array<string, string>, summary: string}> $changesets
	 * @return array<int, array{file: string, packages: array<string, string>, summary: string}>
	 */
	private function changesets_since( array $config, array $changesets, string $since, bool $uncommitted ): array {
		$directory = $this->relative_path( $this->files->resolve( $config['changesets'] ) );
		$changed = $this->changed_files( $since, $uncommitted, $directory, 'ACMR' );

		return array_values(
			array_filter(
				$changesets,
				static fn( array $changeset ): bool => in_array( ltrim( $directory . '/' . $changeset['file'], '/' ), $changed, true )
			)
		);
	}

	/**
	 * Keep the entries of the selected packages in each changeset, and the
	 * other entries to keep in its file, leaving out the changesets that
	 * don't name a selected package, like those without packages.
	 *
	 * @param array<int, array{file: string, name: string, packages: array<string, string>, summary: string}> $changesets
	 * @param string[] $selected Selected package names.
	 * @return array<int, array{file: string, name: string, packages: array<string, string>, summary: string, remaining: array<string, string>}>
	 */
	private function selected_changesets( array $changesets, array $selected ): array {
		$kept = [];

		foreach ( $changesets as $changeset ) {
			$packages = array_intersect_key( $changeset['packages'], array_flip( $selected ) );

			if ( [] !== $packages ) {
				$kept[] = array_merge(
					$changeset,
					[
						'packages' => $packages,
						'remaining' => array_diff_key( $changeset['packages'], $packages ),
					]
				);
			}
		}

		return $kept;
	}

	/**
	 * Remove a consumed changeset, or keep the entries of the packages that
	 * this release leaves out.
	 *
	 * @param array{file: string, packages: array<string, string>, summary: string, remaining?: array<string, string>} $changeset
	 * @return array{path: string, old: string, new: string|null}
	 */
	private function consume_edit( array $changeset ): array {
		$remaining = $changeset['remaining'] ?? [];

		return [
			'path' => $changeset['file'],
			'old' => (string) file_get_contents( $changeset['file'] ),
			'new' => [] === $remaining ? null : $this->reader->format( $remaining, $changeset['summary'] ),
		];
	}

	/**
	 * The packages to tag: the given ones, or all of them. An expected
	 * version needs exactly one package, and selects a sole package.
	 *
	 * @param array<int, array{name: string, path: string, directory: string, group: int}> $packages
	 * @param string[] $identifiers
	 * @return array<int, array{name: string, path: string, directory: string, group: int}>
	 */
	private function tag_packages( array $packages, array $identifiers, ?string $expected ): array {
		$names = ( new Package_Discovery( $this->files ) )->names( $packages, $identifiers );

		if ( null !== $expected && [] === $names && 1 === count( $packages ) ) {
			$names = [ $packages[0]['name'] ];
		}

		if ( null !== $expected && 1 !== count( $names ) ) {
			throw new Release_Exception( 'An expected version requires exactly one package.' );
		}

		return [] === $names ? $packages : array_values( array_filter( $packages, static fn( array $package ): bool => in_array( $package['name'], $names, true ) ) );
	}

	/**
	 * Check, describe and, unless it's a dry run, create the tag of the
	 * current version of a package.
	 *
	 * @return array{package: string, version: string, tag: string, title: string, previousTag?: string, prerelease: bool, exists: bool, created: bool, notes: string}
	 */
	private function release_tag( array $config, array $package, string $current, ?string $expected, bool $dry_run ): array {
		$changelog = $this->changelog_contents( $config, $package );
		$git_tag = 'git-tag' === $config['packages'][ $package['group'] ]['version']['from']['kind'];
		$version = $git_tag && null !== $expected ? $expected : $this->released_version( $config, $package, $current, $changelog );

		if ( null !== $expected && $expected !== $version ) {
			throw new Release_Exception( "Package \"{$package['name']}\" is at version {$version}, not {$expected}." );
		}

		$format = $this->tag_format->of( $config, $package );
		$tag = $this->tag_format->format( $format, $package, $version );
		$exists = $this->versions->tag_exists( $tag );

		if ( ! $exists ) {
			$this->verify_targets( $config, $package, $version );
		}

		$previous = $this->previous_tag( $this->tag_format->format( $format, $package, '' ), $version );
		$notes = $this->changelog_section( $changelog, $version );

		if ( '' === $notes ) {
			$notes = $this->commit_notes( $package, $previous, $exists ? $tag : 'HEAD' );
		}

		if ( ! $exists && ! $dry_run ) {
			$this->git->run( [ 'tag', $tag ] );
		}

		$result = [
			'package' => $package['name'],
			'version' => $version,
			'tag' => $tag,
			'title' => $this->release_title( $config, $package, $version, $tag ),
		];

		if ( null !== $previous ) {
			$result['previousTag'] = $previous;
		}

		return $result + [
			'prerelease' => $this->semver->is_pre_release( $version ),
			'exists' => $exists,
			'created' => ! $exists && ! $dry_run,
			'notes' => $notes,
		];
	}

	/**
	 * Fail unless every version target of a package has the version; the
	 * readme Stable tag keeps the last stable version of a pre-release.
	 */
	private function verify_targets( array $config, array $package, string $version ): void {
		$version_files = $this->versions_for( $package );

		foreach ( $config['packages'][ $package['group'] ]['version']['to'] as $target ) {
			if ( ! $this->keeps_stable_tag( $target, $version ) ) {
				$version_files->propose( $target, $version, $version );
			}
		}
	}

	/**
	 * The tag of the highest version below a version, skipping pre-releases
	 * for a stable version, like wp-release `getPreviousTag()`.
	 */
	private function previous_tag( string $prefix, string $version ): ?string {
		$previous = null;

		foreach ( $this->versions->tagged_versions( $prefix ) as $candidate ) {
			if (
				$this->semver->is_valid( $candidate )
				&& ( $this->semver->is_pre_release( $version ) || ! $this->semver->is_pre_release( $candidate ) )
				&& 0 > $this->semver->compare( $candidate, $version )
				&& ( null === $previous || 0 < $this->semver->compare( $candidate, $previous ) )
			) {
				$previous = $candidate;
			}
		}

		return null === $previous ? null : $prefix . $previous;
	}

	/**
	 * Release notes from the commits that changed a package since its
	 * previous tag, newest first.
	 */
	private function commit_notes( array $package, ?string $previous, string $end ): string {
		$directory = $this->relative_path( $package['directory'] );
		$output = $this->git->run( [ 'log', '--format=%h%x09%s', null === $previous ? $end : $previous . '..' . $end, '--', '' === $directory ? '.' : $directory ] );
		$lines = [];

		foreach ( array_filter( explode( "\n", $output ), 'strlen' ) as $line ) {
			$parts = explode( "\t", $line, 2 );
			$lines[] = '- ' . ( $parts[1] ?? '' ) . ' (' . $parts[0] . ')';
		}

		return implode( "\n", $lines );
	}

	/**
	 * The title of a release: the releaseTitle template or callback, or by
	 * default `name@version`, and the version for a package at the root.
	 */
	private function release_title( array $config, array $package, string $version, string $tag ): string {
		$title = $config['releaseTitle'] ?? null;

		if ( is_callable( $title ) ) {
			$title = $this->call_release_title( $title, $package, $version, $tag );
		} elseif ( is_string( $title ) ) {
			$path = '.' === $package['path'] ? '' : $package['path'];
			$title = str_replace( [ '{name}', '{path}', '{version}', '{tag}' ], [ $package['name'], $path, $version, $tag ], $title );
		} else {
			$title = '.' === $package['path'] ? $version : $package['name'] . '@' . $version;
		}

		return $title;
	}

	private function call_release_title( callable $callback, array $package, string $version, string $tag ): string {
		try {
			$title = $callback(
				[
					'package' => [
						'name' => $package['name'],
						'path' => $package['path'],
					],
					'version' => $version,
					'tag' => $tag,
				]
			);
		} catch ( \Throwable $error ) {
			throw new Release_Exception( 'The releaseTitle callback failed for package ' . $package['name'] . ': ' . $error->getMessage(), 0, $error );
		}

		if ( ! is_string( $title ) || '' === trim( $title ) ) {
			throw new Release_Exception( 'The releaseTitle callback must return a non-empty string for package ' . $package['name'] . '.' );
		}

		return $title;
	}

	/**
	 * The version a package released last: its current version, or for a
	 * package versioned by git tags, a newer version of its changelog.
	 */
	private function released_version( array $config, array $package, string $current, string $changelog ): string {
		$version = $current;
		$matches = [];

		if (
			'git-tag' === $config['packages'][ $package['group'] ]['version']['from']['kind']
			&& 1 === preg_match( '/^##[ \t]+v?([0-9][0-9A-Za-z.-]*)(?:[ \t]|$)/m', $changelog, $matches )
			&& $this->semver->is_valid( $matches[1] )
			&& 0 < $this->semver->compare( $matches[1], $current )
		) {
			$version = $matches[1];
		}

		return $version;
	}

	/**
	 * The contents of a package changelog, empty when it is disabled or absent.
	 */
	private function changelog_contents( array $config, array $package ): string {
		$changelog = $config['packages'][ $package['group'] ]['changelog'] ?? $config['changelog'];
		$path = '' === $changelog ? '' : $this->files_for( $package )->resolve( $changelog );

		return '' !== $path && is_file( $path ) ? (string) file_get_contents( $path ) : '';
	}

	/**
	 * The body of the changelog section of a version, for release notes.
	 */
	private function changelog_section( string $changelog, string $version ): string {
		$pattern = '/^##[ \t]+\[?v?' . preg_quote( $version, '/' ) . '\]?(?:[ \t][^\r\n]*)?\r?\n(.*?)(?=^##[ \t]|\z)/ms';
		$matches = [];

		return 1 === preg_match( $pattern, $changelog, $matches ) ? trim( $matches[1] ) : '';
	}

	/**
	 * The version source of a package, where a git-tag source without its own
	 * pattern reads the tags of the package's effective tag format.
	 *
	 * @param array{name: string, path: string, directory: string, group: int} $package
	 * @return array<string, mixed>
	 */
	private function source( array $config, array $package ): array {
		$source = $config['packages'][ $package['group'] ]['version']['from'];

		if ( 'git-tag' === $source['kind'] && ! isset( $source['pattern'] ) ) {
			$source['pattern'] = $this->tag_format->of( $config, $package );
		}

		return $source;
	}

	/**
	 * Plan the version target edits and the changelog edit of one release.
	 *
	 * @param array<int, array{path: string, new: ?string}> $planned_targets Planned new contents by absolute path, shared across releases.
	 * @return array<int, array{path: string, old: ?string, new: ?string}>
	 */
	private function version_edits( array $config, array $package, array $release, array &$planned_targets, string $date ): array {
		$group = $config['packages'][ $package['group'] ];
		$version_files = $this->versions_for( $package );

		if ( $version_files->tag_exists( $release['tag'] ) ) {
			throw new Release_Exception( 'Proposed tag already exists: ' . $release['tag'] . '.' );
		}

		$edits = [];
		$current = $release['currentVersion'];

		foreach ( $group['version']['to'] as $target ) {
			$prior = isset( $target['file'] ) ? $this->prior_contents( $package, (string) $target['file'], $planned_targets ) : null;

			// The readme Stable tag keeps the last stable version for a pre-release,
			// since WordPress.org offers the stable tag to every site. A stable
			// release lifts it from that stable value even when the current version
			// of the package itself is still a pre-release.
			if ( $this->keeps_stable_tag( $target, $release['nextVersion'] ) ) {
				$edit = null;
			} elseif ( $this->keeps_stable_tag( $target, $current ) ) {
				// A pre-release current version skips the absent optional readme
				// like the preflight does, instead of failing the stable release.
				$stable = $version_files->read_optional_current( $target, $package );
				$edit = null === $stable ? null : $version_files->propose( $target, $stable, $release['nextVersion'], $prior );
			} else {
				$edit = $version_files->propose( $target, $current, $release['nextVersion'], $prior );
			}

			if ( null !== $edit ) {
				$planned_targets[ $edit['path'] ]['new'] = $edit['new'];
				$edits[] = $edit;
			}
		}

		$changelog_edits = [
			$this->maybe_changelog_edit( $config, $package, $release, $planned_targets, $date ),
			$this->maybe_readme_changelog_edit( $config, $package, $release, $planned_targets ),
		];

		foreach ( array_filter( $changelog_edits ) as $edit ) {
			$planned_targets[ $edit['path'] ]['new'] = $edit['new'];
			$edits[] = $edit;
		}

		return $edits;
	}

	/**
	 * Whether a target keeps its current value for a pre-release version.
	 *
	 * @param array<string, mixed> $target
	 */
	private function keeps_stable_tag( array $target, string $version ): bool {
		return 'wordpress-stable-tag' === ( $target['kind'] ?? '' ) && $this->semver->is_pre_release( $version );
	}

	/**
	 * Transform the planned releases into pre-release versions, numbered from
	 * the package tags like the wp-release `toPrereleases()` transform.
	 *
	 * Releases without a pending bump stay untouched; the pre-release numbering
	 * only applies to versions a run actually releases.
	 *
	 * @param array<int, array{name: string, path: string, directory: string, group: int}> $packages
	 * @param array<int, array{package: string, currentVersion: string, bump: string|null, nextVersion: string, tag: string|null, changesets: array<int, array{file: string, packages: array<string, string>, summary: string}>, changelog: string}> $releases
	 * @return array<int, array{package: string, currentVersion: string, bump: string|null, nextVersion: string, tag: string|null, changesets: array<int, array{file: string, packages: array<string, string>, summary: string}>, changelog: string}>
	 */
	private function to_prereleases( array $config, array $packages, array $releases, string $prerelease ): array {
		$by_name = [];

		foreach ( $packages as $package ) {
			$by_name[ $package['name'] ] = $package;
		}

		foreach ( $releases as $index => $release ) {
			if ( null !== $release['bump'] ) {
				$package = $by_name[ $release['package'] ];
				$format = $this->tag_format->of( $config, $package );
				$existing = $this->versions->tagged_versions( $this->tag_format->format( $format, $package, '' ) );
				$version = $this->semver->with_prerelease( $release['nextVersion'], $prerelease, $existing );

				$releases[ $index ]['nextVersion'] = $version;
				$releases[ $index ]['tag'] = $this->tag_format->format( $format, $package, $version );
			}
		}

		return $releases;
	}

	/**
	 * Transform the planned releases into snapshots, like wp-release
	 * `changeset-version --snapshot`: the next version with the identifier and
	 * the UTC time, so that every snapshot is unique.
	 *
	 * @param array<int, array{name: string, path: string, directory: string, group: int}> $packages
	 * @param array<int, array{package: string, bump: string|null, nextVersion: string, tag: string|null}> $releases
	 * @return array<int, array{package: string, bump: string|null, nextVersion: string, tag: string|null}>
	 */
	private function to_snapshots( array $config, array $packages, array $releases, string $snapshot, string $time ): array {
		foreach ( $releases as $index => $release ) {
			if ( null !== $release['bump'] ) {
				$package = $packages[ $index ];
				$version = $this->semver->with_snapshot( $release['nextVersion'], $snapshot, $time );

				$releases[ $index ]['nextVersion'] = $version;
				$releases[ $index ]['tag'] = $this->tag_format->format( $this->tag_format->of( $config, $package ), $package, $version );
			}
		}

		return $releases;
	}

	/**
	 * Plan the changelog edit of a package, honouring per-group overrides and
	 * the empty-string disable.
	 *
	 * @param array<int, array{path: string, new: ?string}> $planned_targets
	 */
	private function maybe_changelog_edit( array $config, array $package, array $release, array &$planned_targets, string $date ): ?array {
		$group = $config['packages'][ $package['group'] ];
		$changelog = $group['changelog'] ?? $config['changelog'];

		if ( '' === $changelog ) {
			return null;
		}

		$entries = $this->changelog_entries( $config['changelogEntry'] ?? null, $package, $release );
		$package_files = $this->files_for( $package );
		$prior = $this->prior_contents( $package, $changelog, $planned_targets );

		return $this->changelog_edit( $package_files, $changelog, $release['nextVersion'], $entries, $date, $prior );
	}

	/**
	 * Plan the `= {version} =` section of a plugin readme changelog, like
	 * wp-release readmeChangelog: the first of readme.txt and readme.md with a
	 * `== Changelog ==` heading gets the section after its heading, with one
	 * `*` bullet per changeset summary. A readme without the heading, or that
	 * already has the version, is left as it is.
	 *
	 * @param array<int, array{path: string, new: ?string}> $planned_targets
	 * @return array{path: string, old: string, new: string}|null
	 */
	private function maybe_readme_changelog_edit( array $config, array $package, array $release, array &$planned_targets ): ?array {
		$package_files = $this->files_for( $package );
		$edit = null;

		foreach ( $config['readmeChangelog'] ? [ 'readme.txt', 'readme.md' ] : [] as $name ) {
			$path = $package_files->resolve( $name );
			$contents = $this->prior_contents( $package, $name, $planned_targets ) ?? ( is_file( $path ) ? (string) file_get_contents( $path ) : null );

			if ( null === $edit && null !== $contents ) {
				$edit = $this->readme_changelog_edit( $path, $contents, $release );
			}
		}

		return $edit;
	}

	/**
	 * @return array{path: string, old: string, new: string}|null
	 */
	private function readme_changelog_edit( string $path, string $contents, array $release ): ?array {
		$version = $release['nextVersion'];

		if ( 1 !== preg_match( '/^==\s*Changelog\s*==[ \t]*$/mi', $contents, $matches, PREG_OFFSET_CAPTURE ) || 1 === preg_match( '/^=\s*' . preg_quote( $version, '/' ) . '\s*=$/m', $contents ) ) {
			return null;
		}

		$bullets = [];

		foreach ( $release['changesets'] as $changeset ) {
			$bullets[] = '* ' . str_replace( "\n", "\n  ", trim( $changeset['summary'] ) );
		}

		$marker_end = $matches[0][1] + strlen( $matches[0][0] );
		$after = (string) preg_replace( '/^(?:[ \t]*\r?\n)+/', '', substr( $contents, $marker_end ) );
		$line_ending = false !== strpos( $contents, "\r\n" ) ? "\r\n" : "\n";
		$section = '= ' . $version . " =\n\n" . implode( "\n", $bullets ) . "\n\n";

		return [
			'path' => $path,
			'old' => $contents,
			'new' => substr( $contents, 0, $marker_end ) . $line_ending . $line_ending . str_replace( "\n", $line_ending, $section ) . $after,
		];
	}

	/**
	 * Format one changelog entry line per changeset with the configured
	 * changelogEntry hook or the default summary bullet.
	 *
	 * @param array<string, mixed> $package
	 * @param array<string, mixed> $release
	 * @return array<int, string>
	 */
	private function changelog_entries( ?callable $hook, array $package, array $release ): array {
		$entries = [];

		foreach ( $release['changesets'] as $changeset ) {
			$formatted = null;

			if ( null !== $hook ) {
				$formatted = $this->format_changelog_entry( $hook, $package, $release, $changeset );
			}

			if ( is_string( $formatted ) && '' !== $formatted ) {
				$entries[] = str_replace( "\n", "\n  ", $formatted );
			} else {
				$entries[] = '- ' . str_replace( "\n", "\n  ", $changeset['summary'] );
			}
		}

		return $entries;
	}

	/**
	 * Invoke the changelogEntry hook for one changeset of one release.
	 *
	 * @param array<string, mixed> $package
	 * @param array<string, mixed> $release
	 * @param array{file: string, packages: array<string, string>, summary: string} $changeset
	 */
	private function format_changelog_entry( callable $hook, array $package, array $release, array $changeset ): ?string {
		try {
			$entry = $hook(
				[
					'package' => [
						'name' => $package['name'],
						'path' => $package['path'],
						'currentVersion' => $release['currentVersion'],
					],
					'changeset' => $changeset,
				]
			);
		} catch ( \Throwable $error ) {
			throw new Release_Exception( 'The changelogEntry hook failed for package ' . $package['name'] . ': ' . $error->getMessage(), 0, $error );
		}

		if ( ! is_string( $entry ) ) {
			throw new Release_Exception( 'The changelogEntry hook must return a string for changeset ' . $changeset['file'] . ' of package ' . $package['name'] . '.' );
		}

		return trim( $entry );
	}

	/**
	 * Chain edits to one file by passing the already-planned new contents as
	 * the contents override of the next descriptor.
	 *
	 * @return string|null Planned new contents of the path, or null.
	 */
	private function prior_contents( array $package, string $relative, array $planned_targets ): ?string {
		$directory = realpath( $package['directory'] );

		if ( false === $directory ) {
			return null;
		}

		return $planned_targets[ $directory . '/' . $relative ]['new'] ?? null;
	}

	/**
	 * The absolute path of a file relative to the project root, like the
	 * changed files of the status.
	 */
	public function absolute_path( string $relative ): string {
		return $this->files->get_root() . '/' . $relative;
	}

	private function relative_path( string $path ): string {
		return ltrim( substr( $path, strlen( $this->files->get_root() ) ), '/' );
	}

	private function versions_for( array $package ): Version_Files {
		if ( $package['directory'] === $this->files->get_root() ) {
			return $this->versions;
		}

		return new Version_Files( $this->files_for( $package ), $this->git, $this->semver, $this->tag_format );
	}

	/**
	 * Bind a resolver to a package directory without re-resolving its
	 * discovery-relative path against the repository root, so that packages
	 * under a configured `root` keep the same guarantee.
	 */
	private function files_for( array $package ): Project_Files {
		if ( $package['directory'] === $this->files->get_root() ) {
			return $this->files;
		}

		return new Project_Files( $package['directory'] );
	}

	/**
	 * Prepend a changelog section to a package's changelog file.
	 *
	 * @param array<int, string> $entries One entry line per changeset.
	 * @return array{path: string, old: string|null, new: string}
	 */
	private function changelog_edit( Project_Files $package_files, string $relative, string $version, array $entries, string $date, ?string $contents_override = null ): array {
		$path = $package_files->resolve( $relative );
		$old = $contents_override ?? ( is_file( $path ) ? (string) file_get_contents( $path ) : '' );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			throw new Release_Exception( 'Release date must use YYYY-MM-DD.' );
		}

		if ( preg_match( '/^##\s+(?:\[)?' . preg_quote( $version, '/' ) . '(?:\])?(?:\s|$)/m', $old ) ) {
			throw new Release_Exception( "Changelog already contains {$version}." );
		}

		// Match the wp-release section format: the version stays unbracketed and the date goes in parentheses.
		$section = '## ' . $version . ' (' . $date . ')' . "\n\n" . implode( "\n", $entries ) . "\n\n";

		if ( '' === $old ) {
			$old = null;
			$new = "# Changelog\n\n" . $section;
		} else {
			// Insert before the first version heading, or before the leading title,
			// matching the wp-release changelog insertion precedence.
			if ( preg_match( '/^##[ \t]+v?[0-9]+(?:\.[0-9]+){1,3}(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?(?:[ \t]+.*)?$/m', $old, $matches, PREG_OFFSET_CAPTURE ) ) {
				$anchor = $matches[0][1];
			} elseif ( preg_match( '/^#[ \t]+[^\r\n]+[ \t]*$/m', $old, $matches, PREG_OFFSET_CAPTURE ) ) {
				$anchor = $matches[0][1];
			} else {
				$anchor = 0;
			}

			$before = rtrim( substr( $old, 0, $anchor ), "\r\n" );
			$prefix = '' !== $before ? $before . "\n\n" : '';
			$new = $prefix . $section . substr( $old, $anchor );
		}

		return [
			'path' => $path,
			'old' => $old,
			'new' => $new,
		];
	}

	/**
	 * Compose edits to one path while rejecting disconnected intermediate states.
	 */
	private function combine_edits( array $edits ): array {
		$by_path = [];

		foreach ( $edits as $edit ) {
			$path = $edit['path'];

			if ( isset( $by_path[ $path ] ) ) {
				$previous = $by_path[ $path ];

				if ( $previous['old'] === $edit['old'] && $previous['new'] === $edit['new'] ) {
					// Releases that share a consumed changeset propose the same deletion.
				} elseif ( $previous['new'] !== $edit['old'] ) {
					throw new Release_Exception( "Conflicting planned edits for {$path}." );
				} else {
					$by_path[ $path ]['new'] = $edit['new'];
				}
			} else {
				$by_path[ $path ] = $edit;
			}
		}

		return array_values( $by_path );
	}
}
