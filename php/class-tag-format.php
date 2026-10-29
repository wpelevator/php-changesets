<?php

namespace WPElevator\PHP_Changesets;

/**
 * Expand tag formats with the {name}, {path} and {version} of a package, like
 * wp-release `formatTag()`.
 */
final class Tag_Format {

	/**
	 * Tag format of the package releases, like `plugins/alpha/1.2.0` in a
	 * monorepo, or `1.2.0` for a package at the repository root.
	 */
	public const DEFAULT = '{path}/{version}';

	/**
	 * Format the tag of a package release, or its tag prefix for an empty
	 * version. A package at the root has an empty {path}, and the empty
	 * placeholders leave no repeated or leading slashes.
	 *
	 * @param array{name: string, path: string} $package
	 */
	public function format( string $format, array $package, string $version ): string {
		$path = '.' === $package['path'] ? '' : $package['path'];
		$tag = str_replace( [ '{name}', '{path}', '{version}' ], [ $package['name'], $path, $version ], $format );

		return ltrim( (string) preg_replace( '#/{2,}#', '/', $tag ), '/' );
	}

	/**
	 * The effective tag format of a package: its group override or the
	 * repository setting.
	 *
	 * @param array{group: int} $package
	 */
	public function of( array $config, array $package ): string {
		return $config['packages'][ $package['group'] ]['tagFormat'] ?? $config['tagFormat'];
	}
}
