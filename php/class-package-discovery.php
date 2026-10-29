<?php

namespace WPElevator\PHP_Changesets;

/**
 * Discover package directories for the configured package groups.
 */
final class Package_Discovery {

	/**
	 * Directories that never contain configured packages and are never traversed.
	 */
	private const SKIPPED_DIRECTORIES = [ '.git', 'node_modules', 'vendor', 'dist' ];

	private Project_Files $files;

	public function __construct( Project_Files $files ) {
		$this->files = $files;
	}

	/**
	 * Find a package by its identifier, or by its path under the configured
	 * root like `plugins/alpha/`, as wp-release `findPackage()` does.
	 *
	 * @param array<int, array{name: string, path: string, directory: string, group: int}> $packages
	 * @return array{name: string, path: string, directory: string, group: int}|null
	 */
	public function find( array $packages, string $identifier ): ?array {
		$path = rtrim( (string) preg_replace( '#^\./#', '', str_replace( '\\', '/', $identifier ) ), '/' );
		$by_name = array_values( array_filter( $packages, static fn( array $package ): bool => $package['name'] === $identifier ) );
		$by_path = array_values( array_filter( $packages, static fn( array $package ): bool => $package['path'] === $path ) );

		return $by_name[0] ?? $by_path[0] ?? null;
	}

	/**
	 * Resolve package identifiers or paths to the names of the packages,
	 * keeping their order and rejecting unknown or repeated packages.
	 *
	 * @param array<int, array{name: string, path: string, directory: string, group: int}> $packages
	 * @param string[] $identifiers Package identifiers, or their paths under the configured root.
	 * @return string[]
	 */
	public function names( array $packages, array $identifiers ): array {
		$names = [];

		foreach ( $identifiers as $identifier ) {
			$package = $this->find( $packages, $identifier );

			if ( null === $package ) {
				throw new Release_Exception( "Unknown package: {$identifier}." );
			}

			if ( in_array( $package['name'], $names, true ) ) {
				throw new Release_Exception( "Package {$package['name']} is selected more than once." );
			}

			$names[] = $package['name'];
		}

		return $names;
	}

	/**
	 * Find the packages of every configured group, in configured group order.
	 *
	 * Packages are ordered by group, then by relative path within the group.
	 * The walk is bounded by the deepest configured glob, mirroring wp-release.
	 *
	 * @return array<int, array{name: string, path: string, directory: string, group: int}>
	 */
	public function discover( array $config ): array {
		$root = $this->root( $config );
		$directories = $this->directories( $root, '', $this->max_depth( $config['packages'] ) );
		$packages = [];
		$names = [];

		foreach ( $config['packages'] as $index => $group ) {
			$expression = $this->glob_expression( $group['glob'] );
			// Only the explicit root glob matches the package root itself; free-form
			// patterns like * must not sweep the repository root incidentally, unlike
			// wp-release, where * also matches the root.
			$candidates = '.' === $group['glob'] ? array_merge( [ '.' ], $directories ) : $directories;
			$matches = [];

			foreach ( $candidates as $candidate ) {
				if ( 1 === preg_match( $expression, $candidate ) && ! $this->excluded( $candidate, $group['exclude'] ) ) {
					$matches[] = $candidate;
				}
			}

			sort( $matches );

			if ( [] === $matches ) {
				throw new Release_Exception( sprintf( 'Package glob "%s" did not match any directories under %s.', $group['glob'], $root ) );
			}

			$id = $group['id'] ?? null;

			if ( null !== $id && 1 < count( $matches ) ) {
				throw new Release_Exception( sprintf( 'The package identifier "%s" would name every one of the %d directories that the glob "%s" matches; narrow the glob or use idFrom.', $id, count( $matches ), $group['glob'] ) );
			}

			foreach ( $matches as $relative_path ) {
				$directory = '.' === $relative_path ? $root : $root . '/' . $relative_path;
				$name = $id ?? $this->package_name( $directory, $relative_path, $group['idFrom'] );

				if ( isset( $names[ $name ] ) ) {
					throw new Release_Exception( sprintf( 'Package identifier "%s" is configured more than once.', $name ) );
				}

				$names[ $name ] = true;
				$packages[] = [
					'name' => $name,
					'path' => $relative_path,
					'directory' => $directory,
					'group' => $index,
				];
			}
		}

		return $packages;
	}

	private function root( array $config ): string {
		$root = $this->files->get_root();
		$relative = $config['root'] ?? null;

		if ( null !== $relative ) {
			$base = realpath( $this->files->resolve( $relative ) );

			if ( false === $base || ! is_dir( $base ) ) {
				throw new Release_Exception( sprintf( 'Configured package root is missing: %s.', $relative ) );
			}

			$root = $base;
		}

		return $root;
	}

	/**
	 * Depth bound for the bounded directory walk, like wp-release.
	 *
	 * @param array<int, mixed> $groups
	 */
	private function max_depth( array $groups ): int {
		$depth = 1;

		foreach ( $groups as $group ) {
			$segments = explode( '/', $group['glob'] );

			if ( in_array( '**', $segments, true ) ) {
				return PHP_INT_MAX;
			}

			$depth = max( $depth, count( $segments ) + 2 );
		}

		return $depth;
	}

	/**
	 * Collect directories below a base path, up to the depth bound.
	 *
	 * @return string[]
	 */
	private function directories( string $base, string $prefix, int $max_depth ): array {
		$directory = '' === $prefix ? $base : $base . '/' . $prefix;

		if ( ! is_dir( $directory ) ) {
			return [];
		}

		$entries = scandir( $directory );

		if ( false === $entries ) {
			return [];
		}

		$found = [];

		foreach ( $entries as $entry ) {
			if ( '.' !== $entry && '..' !== $entry && ! in_array( $entry, self::SKIPPED_DIRECTORIES, true ) ) {
				$path = $directory . '/' . $entry;

				if ( ! is_link( $path ) && is_dir( $path ) ) {
					$relative = '' === $prefix ? $entry : $prefix . '/' . $entry;
					$found[] = $relative;

					if ( count( explode( '/', $relative ) ) < $max_depth ) {
						$found = array_merge( $found, $this->directories( $base, $relative, $max_depth ) );
					}
				}
			}
		}

		return $found;
	}

	private function excluded( string $candidate, array $excludes ): bool {
		foreach ( $excludes as $exclude ) {
			if ( 1 === preg_match( $this->glob_expression( $exclude ), $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Resolve a package identifier from its manifest field or directory path.
	 *
	 * The package at the root has no path of its own, and the name of the
	 * directory changes with every checkout, so it needs an id or a manifest.
	 */
	private function package_name( string $directory, string $relative_path, string $id_from ): string {
		if ( 'path' === $id_from && '.' === $relative_path ) {
			throw new Release_Exception( 'The package at the root has no path to name it by; set its id, like \'id\' => \'my-plugin\', or use idFrom with composer.json#name.' );
		}

		if ( 'path' === $id_from ) {
			return $relative_path;
		}

		$parts = explode( '#', $id_from, 2 );
		$file = $parts[0];
		$field = $parts[1] ?? '';
		$path = $directory . '/' . $file;
		$manifest = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
		$name = is_array( $manifest ) ? ( $manifest[ $field ] ?? null ) : null;

		if ( ! is_string( $name ) || '' === trim( $name ) ) {
			throw new Release_Exception( sprintf( 'Package identifier could not be read from "%s" in %s.', $id_from, $directory ) );
		}

		return $name;
	}

	/**
	 * Convert a glob relative to the package root to an anchored expression,
	 * where * and ? match within one path segment and ** matches any segments.
	 */
	private function glob_expression( string $glob ): string {
		$segments = explode( '/', str_replace( '\\', '/', $glob ) );
		$pattern = [];

		foreach ( $segments as $segment ) {
			if ( '**' === $segment ) {
				$pattern[] = '.*';
			} else {
				$pattern[] = str_replace( [ '\\*', '\\?' ], [ '[^\\/]*', '[^\\/]' ], preg_quote( $segment, '/' ) );
			}
		}

		return '/^' . implode( '\\/', $pattern ) . '$/D';
	}
}
