<?php

namespace WPElevator\PHP_Changesets;

/**
 * Parse, validate, bump and compare the supported stable and pre-release versions.
 *
 * Versions have two to four numeric parts, like the SemVer `X.Y.Z` and the
 * `X.Y` and `X.Y.Z.W` versions common in WordPress plugins, and an optional
 * pre-release of dot separated identifiers of digits, letters and hyphens.
 * Build metadata is rejected. Comparison mirrors wp-release `versions.ts`:
 * missing parts count as zero, stable versions outrank pre-releases of the
 * same base and numeric pre-release identifiers compare numerically.
 */
final class Semver {

	private const MAIN_PATTERN = '/^(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*)){1,3}$/D';

	private const PRERELEASE_PATTERN = '/^(?:[0-9A-Za-z-]+)(?:\.[0-9A-Za-z-]+)*$/D';

	private const BUMP_INDEXES = [
		'major' => 0,
		'minor' => 1,
		'patch' => 2,
	];

	/**
	 * The stable part of a version, like `1.2.3` from `1.2.3-rc.1`.
	 */
	public function main( string $version ): string {
		$position = strpos( $version, '-' );

		if ( false === $position ) {
			return $version;
		}

		return substr( $version, 0, (int) $position );
	}

	/**
	 * Whether the version is a supported stable or pre-release version.
	 */
	public function is_valid( string $version ): bool {
		if ( false !== strpos( $version, '+' ) || 1 !== preg_match( self::MAIN_PATTERN, $this->main( $version ) ) ) {
			return false;
		}

		$prerelease = $this->prerelease_string( $version );

		return null === $prerelease || 1 === preg_match( self::PRERELEASE_PATTERN, $prerelease );
	}

	/**
	 * Whether the version carries a pre-release.
	 */
	public function is_pre_release( string $version ): bool {
		return null !== $this->prerelease( $version );
	}

	/**
	 * Calculate the next stable version of a bump.
	 *
	 * The bumped part is raised, the parts after it reset to zero and the
	 * version keeps its number of parts, so a minor bump of `2.1` is `2.2` and
	 * of `1.2.3.4` is `1.3.0.0`; a patch bump of `2.1` adds the third part. A
	 * pre-release graduates like `semver inc`: when every part after the bumped
	 * one is zero, the bump releases its base, so a patch of `1.2.4-rc.1` is
	 * `1.2.4` and a minor of `1.3.0-rc.1` is `1.3.0`, while a minor of
	 * `1.2.4-rc.1` is `1.3.0`.
	 */
	public function next( string $version, string $bump ): string {
		if ( ! isset( self::BUMP_INDEXES[ $bump ] ) ) {
			throw new Release_Exception( "Invalid bump \"$bump\"; expected patch, minor or major." );
		}

		$parts = $this->stable_parts( $version );
		$index = self::BUMP_INDEXES[ $bump ];

		if ( $this->is_pre_release( $version ) && $this->zero_after( $parts, $index ) ) {
			return $this->main( $version );
		}

		if ( PHP_INT_MAX === ( $parts[ $index ] ?? 0 ) ) {
			throw new Release_Exception( "Version component cannot be incremented: $version" );
		}

		$length = max( count( $parts ), $index + 1 );
		$next = [];

		for ( $position = 0; $position < $length; ++$position ) {
			$next[] = $this->bumped_part( $parts[ $position ] ?? 0, $position, $index );
		}

		return implode( '.', $next );
	}

	/**
	 * Compare two supported versions by precedence.
	 *
	 * Missing parts count as zero, stable versions outrank pre-releases of
	 * the same base, and pre-releases compare identifier by identifier, with
	 * numeric identifiers compared numerically and others lexically.
	 */
	public function compare( string $a, string $b ): int {
		$left = $this->stable_parts( $a );
		$right = $this->stable_parts( $b );
		$length = max( count( $left ), count( $right ) );
		$order = 0;

		for ( $index = 0; 0 === $order && $index < $length; ++$index ) {
			$order = ( $left[ $index ] ?? 0 ) <=> ( $right[ $index ] ?? 0 );
		}

		if ( 0 === $order ) {
			$order = $this->compare_prerelease( $this->prerelease( $a ), $this->prerelease( $b ) );
		}

		return $order;
	}

	/**
	 * Apply the wp-release `getPrereleaseVersion` transform to a stable
	 * release version.
	 *
	 * A bare identifier like `rc` numbers one above the highest existing
	 * `<stable>-<identifier>.<n>` version among the tags, so the first one is
	 * `rc.1`. An explicit `id.n` like `rc.2` is used as-is and errors when
	 * that exact version already exists.
	 *
	 * @param string[] $existing Existing versions of the package, like its tags.
	 */
	public function with_prerelease( string $stable, string $prerelease, array $existing ): string {
		$match = [];

		if ( 1 !== preg_match( '/^([A-Za-z][0-9A-Za-z-]*)(?:\.(0|[1-9][0-9]*))?$/D', $prerelease, $match ) ) {
			throw new Release_Exception( "Invalid pre-release \"$prerelease\"; expected an identifier with an optional number, like rc or rc.1." );
		}

		if ( ! $this->is_valid( $stable ) || $this->is_pre_release( $stable ) ) {
			throw new Release_Exception( "Cannot release \"$stable\" as a pre-release: expected a stable version." );
		}

		$identifier = $match[1];

		if ( isset( $match[2] ) ) {
			$version = $stable . '-' . $prerelease;
		} else {
			$number = $this->next_prerelease_number( $stable . '-' . $identifier . '.', $existing );
			$version = $stable . '-' . $identifier . '.' . $number;
		}

		if ( in_array( $version, $existing, true ) ) {
			throw new Release_Exception( "Version $version already exists; pass a pre-release without a number to release the next one." );
		}

		return $version;
	}

	/**
	 * The pre-release identifiers of a version, or null when it is stable.
	 *
	 * @return string[]|null
	 */
	public function prerelease( string $version ): ?array {
		$prerelease = $this->prerelease_string( $version );

		if ( null === $prerelease ) {
			return null;
		}

		if ( 1 !== preg_match( self::PRERELEASE_PATTERN, $prerelease ) ) {
			throw new Release_Exception( "Invalid pre-release version: $version" );
		}

		return explode( '.', $prerelease );
	}

	/**
	 * The raw pre-release span of a version, or null when it is stable.
	 */
	private function prerelease_string( string $version ): ?string {
		$position = strpos( $version, '-' );

		if ( false === $position || 0 === $position ) {
			return null;
		}

		return substr( $version, $position + 1 );
	}

	/**
	 * Validate a supported version and return its numeric parts.
	 *
	 * @return int[]
	 */
	private function stable_parts( string $version ): array {
		if ( ! $this->is_valid( $version ) ) {
			throw new Release_Exception( "Invalid version: $version" );
		}

		$parts = [];

		foreach ( explode( '.', $this->main( $version ) ) as $part ) {
			if ( $this->exceeds_int( $part ) ) {
				throw new Release_Exception( "Version component is too large: $version" );
			}

			$parts[] = (int) $part;
		}

		return $parts;
	}

	private function exceeds_int( string $part ): bool {
		$max = (string) PHP_INT_MAX;

		return strlen( $part ) > strlen( $max ) || ( strlen( $part ) === strlen( $max ) && strcmp( $part, $max ) > 0 );
	}

	/**
	 * Whether every part after an index is zero or missing.
	 *
	 * @param int[] $parts
	 */
	private function zero_after( array $parts, int $index ): bool {
		return [] === array_filter( array_slice( $parts, $index + 1 ) );
	}

	private function bumped_part( int $part, int $position, int $index ): int {
		if ( $position === $index ) {
			return $part + 1;
		}

		return $position < $index ? $part : 0;
	}

	/**
	 * @param string[]|null $a
	 * @param string[]|null $b
	 */
	private function compare_prerelease( ?array $a, ?array $b ): int {
		if ( null === $a || null === $b ) {
			return ( null === $a ? 1 : 0 ) - ( null === $b ? 1 : 0 );
		}

		$length = min( count( $a ), count( $b ) );
		$order = 0;

		for ( $index = 0; 0 === $order && $index < $length; ++$index ) {
			$order = $this->compare_identifier( $a[ $index ], $b[ $index ] );
		}

		return 0 === $order ? count( $a ) <=> count( $b ) : $order;
	}

	private function compare_identifier( string $a, string $b ): int {
		$left_numeric = 1 === preg_match( '/^[0-9]+$/D', $a );
		$right_numeric = 1 === preg_match( '/^[0-9]+$/D', $b );

		if ( $left_numeric && $right_numeric ) {
			return (int) $a <=> (int) $b;
		}

		if ( $left_numeric || $right_numeric ) {
			return $left_numeric ? -1 : 1;
		}

		return strcmp( $a, $b ) <=> 0;
	}

	/**
	 * One above the highest numeric sequence after a version prefix.
	 *
	 * @param string[] $existing
	 */
	private function next_prerelease_number( string $prefix, array $existing ): int {
		$numbers = [ 0 ];

		foreach ( $existing as $version ) {
			$tail = 0 === strpos( (string) $version, $prefix ) ? substr( (string) $version, strlen( $prefix ) ) : '';

			if ( 1 === preg_match( '/^[0-9]+$/D', $tail ) ) {
				$numbers[] = (int) $tail;
			}
		}

		return max( $numbers ) + 1;
	}
}
