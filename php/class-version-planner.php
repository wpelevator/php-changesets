<?php

namespace WPElevator\PHP_Changesets;

/**
 * Combine changesets into one release per package, with the highest bump and
 * the ordered release notes.
 *
 * The releases carry the wp-release PackageRelease data contract: package,
 * currentVersion, bump, nextVersion, tag, changesets, notes and paths. Paths
 * are appended by the engine once its file edits are planned. A pre-release
 * current version graduates like `semver inc`, so a patch of `1.2.4-rc.1`
 * plans `1.2.4`; see Semver::next().
 */
final class Version_Planner {

	private const BUMP_RANKS = [
		'patch' => 1,
		'minor' => 2,
		'major' => 3,
	];

	private Semver $semver;

	private Tag_Format $tag_format;

	public function __construct() {
		$this->semver = new Semver();
		$this->tag_format = new Tag_Format();
	}

	/**
	 * Plan one release for every package, in package order.
	 *
	 * @param array{tagFormat: string, nextVersion: callable|null, packages: array<int, mixed>} $config
	 *        Canonical workspace configuration.
	 * @param array<int, array{name: string, path: string, directory: string, group: int}> $packages
	 *        Discovered packages in configured order.
	 * @param array<int, array{file: string, name: string, packages: array<string, string>, summary: string}> $changesets
	 *        Parsed changesets in filename order.
	 * @param array<string, string> $current_versions Package name to current version.
	 * @return array<int, array{package: string, currentVersion: string, bump: string|null, nextVersion: string, tag: string|null, changesets: array<int, array{file: string, packages: array<string, string>, summary: string}>, notes: array<int, array{name: string, summary: string}>}>
	 */
	public function plan( array $config, array $packages, array $changesets, array $current_versions ): array {
		$releases = [];

		foreach ( $packages as $package ) {
			$release = [
				'package' => $package['name'],
				'currentVersion' => $current_versions[ $package['name'] ] ?? '',
				'bump' => null,
				'nextVersion' => $current_versions[ $package['name'] ] ?? '',
				'tag' => null,
				'changesets' => [],
				'notes' => [],
			];

			if ( ! $this->semver->is_valid( $release['currentVersion'] ) ) {
				throw new Release_Exception( 'Invalid version of package ' . $package['name'] . ': ' . $release['currentVersion'] );
			}

			foreach ( $changesets as $changeset ) {
				$level = $changeset['packages'][ $package['name'] ] ?? null;

				if ( null !== $level ) {
					$release['changesets'][] = [
						'file' => $changeset['name'],
						'packages' => $changeset['packages'],
						'summary' => $changeset['summary'],
					];

					$release['notes'][] = [
						'name' => $changeset['name'],
						'summary' => $changeset['summary'],
					];

					if ( null === $release['bump'] || self::BUMP_RANKS[ $level ] > self::BUMP_RANKS[ $release['bump'] ] ) {
						$release['bump'] = $level;
					}
				}
			}

			if ( null !== $release['bump'] ) {
				$release['nextVersion'] = $this->next_version( $config, $package, $release );
				$release['tag'] = $this->tag_format->format( $this->tag_format->of( $config, $package ), $package, $release['nextVersion'] );
			}

			$releases[] = $release;
		}

		return $releases;
	}

	/**
	 * Compute the next version from the configured hook or the bump.
	 */
	private function next_version( array $config, array $package, array $release ): string {
		$hook = $config['nextVersion'] ?? null;

		if ( null === $hook ) {
			return $this->semver->next( $release['currentVersion'], $release['bump'] );
		}

		try {
			$next = $hook(
				[
					'package' => [
						'name' => $package['name'],
						'path' => $package['path'],
						'currentVersion' => $release['currentVersion'],
					],
					'bump' => $release['bump'],
					'currentVersion' => $release['currentVersion'],
					'changesets' => $release['changesets'],
				]
			);
		} catch ( \Throwable $error ) {
			throw new Release_Exception( 'The nextVersion hook failed for package ' . $package['name'] . ': ' . $error->getMessage(), 0, $error );
		}

		if ( ! is_string( $next ) || ! $this->semver->is_valid( $next ) ) {
			throw new Release_Exception( 'The nextVersion hook must return a supported full version for package ' . $package['name'] . '.' );
		}

		return $next;
	}
}
