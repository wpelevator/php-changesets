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

	public function __construct(
		Project_Files $files,
		?Project_Config $config_loader = null,
		?Changeset_Reader $reader = null,
		?Version_Planner $planner = null,
		?Version_Files $versions = null,
		?Release_Transaction $transaction = null
	) {
		$this->files = $files;
		$this->config_loader = $config_loader ?? new Project_Config( $files );
		$this->reader = $reader ?? new Changeset_Reader();
		$this->planner = $planner ?? new Version_Planner();
		$this->versions = $versions ?? new Version_Files( $files );
		$this->transaction = $transaction ?? new Release_Transaction( $files );
		$this->semver = new Semver();
		$this->tag_format = new Tag_Format();
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
	 * @return array{changesets: array<int, array{file: string, packages: array<string, string>, summary: string}>, releases: array<int, array{package: string, currentVersion: string, bump: string|null, nextVersion: string, tag: string|null, changesets: array<int, array{file: string, packages: array<string, string>, summary: string}>, notes: array<int, array{name: string, summary: string}>, paths: string[]}>, edits: array<int, array{path: string, old: ?string, new: ?string}>, sections: array<int, array{title: string, edits: array<int, array{path: string, old: ?string, new: ?string}>}>}
	 */
	public function plan( ?string $date = null, ?string $prerelease = null ): array {
		if ( $this->transaction->has_pending() ) {
			throw new Release_Exception( 'An interrupted release is pending; run changesets recover.' );
		}

		$config = $this->config_loader->load();
		$packages = ( new Package_Discovery( $this->files ) )->discover( $config );
		$directory = $this->files->resolve( $config['changesets'] );
		$changesets = $this->reader->read( $directory, array_column( $packages, 'name' ) );

		$current_versions = $this->read_versions( $config, $packages );
		$releases = $this->planner->plan( $config, $packages, $changesets, $current_versions );

		if ( null !== $prerelease ) {
			// Like wp-release, the pre-release transform also applies to the
			// stable versions that a nextVersion hook returns.
			$releases = $this->to_prereleases( $config, $packages, $releases, $prerelease );
		}

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
			}

			foreach ( $release_edits as $edit ) {
				$paths[] = $this->relative_path( $edit['path'] );
			}

			$consumed = [];

			foreach ( $changesets as $changeset ) {
				if ( isset( $changeset['packages'][ $release['package'] ] ) ) {
					$paths[] = ltrim( substr( $changeset['file'], strlen( $root ) ), '/' );
					$consumed[] = [
						'path' => $changeset['file'],
						'old' => (string) file_get_contents( $changeset['file'] ),
						'new' => null,
					];
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
	 * List the release tags of the current package versions that don't exist
	 * yet, like wp-release `changeset-tag --untagged`, so that a workflow can
	 * create them after merging a release. The CLI never creates tags itself.
	 *
	 * A package versioned by git tags has no version file, so its released
	 * version is the latest changelog section above its latest tag.
	 *
	 * @return array{tags: array<int, array{package: string, version: string, tag: string, prerelease: bool, notes: string}>}
	 */
	public function untagged(): array {
		if ( $this->transaction->has_pending() ) {
			throw new Release_Exception( 'An interrupted release is pending; run changesets recover.' );
		}

		$config = $this->config_loader->load();
		$packages = ( new Package_Discovery( $this->files ) )->discover( $config );
		$versions = $this->read_versions( $config, $packages );
		$tags = [];

		foreach ( $packages as $package ) {
			$changelog = $this->changelog_contents( $config, $package );
			$version = $this->released_version( $config, $package, $versions[ $package['name'] ], $changelog );
			$tag = $this->tag_format->format( $this->tag_format->of( $config, $package ), $package, $version );

			if ( ! $this->versions->tag_exists( $tag ) ) {
				$tags[] = [
					'package' => $package['name'],
					'version' => $version,
					'tag' => $tag,
					'prerelease' => $this->semver->is_pre_release( $version ),
					'notes' => $this->changelog_section( $changelog, $version ),
				];
			}
		}

		return [ 'tags' => $tags ];
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
	 * @param array<int, array{package: string, currentVersion: string, bump: string|null, nextVersion: string, tag: string|null, changesets: array<int, array{file: string, packages: array<string, string>, summary: string}>, notes: array<int, array{name: string, summary: string}>}> $releases
	 * @return array<int, array{package: string, currentVersion: string, bump: string|null, nextVersion: string, tag: string|null, changesets: array<int, array{file: string, packages: array<string, string>, summary: string}>, notes: array<int, array{name: string, summary: string}>}>
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

	private function relative_path( string $path ): string {
		return ltrim( substr( $path, strlen( $this->files->get_root() ) ), '/' );
	}

	private function versions_for( array $package ): Version_Files {
		if ( $package['directory'] === $this->files->get_root() ) {
			return $this->versions;
		}

		return new Version_Files( $this->files_for( $package ) );
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
