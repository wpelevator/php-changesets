<?php

declare(strict_types=1);

namespace WPElevator\PHP_Changesets;

final class Version_Planner {

	// Public parameter names are part of the API.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName
	public function plan( string $currentVersion, array $changesets ): array {
		$current_version = $currentVersion;
		// phpcs:enable WordPress.NamingConventions.ValidVariableName
		if ( ! preg_match( '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $current_version, $matches ) ) {
			throw new Release_Exception( "Invalid stable version: $current_version" );
		}
		$parts = array_slice( $matches, 1 );
		foreach ( $parts as $part ) {
			if ( strlen( $part ) > strlen( (string) PHP_INT_MAX ) || ( strlen( $part ) === strlen( (string) PHP_INT_MAX ) && strcmp( $part, (string) PHP_INT_MAX ) > 0 ) ) {
				throw new Release_Exception( "Version component is too large: $current_version" );
			}
		}
		usort( $changesets, static fn( array $a, array $b ): int => strcmp( $a['name'], $b['name'] ) );
		$ranks = [
			'patch' => 1,
			'minor' => 2,
			'major' => 3,
		];
		$bump = null;
		$notes = [];
		foreach ( $changesets as $changeset ) {
			$level = $changeset['bump'];
			if ( null !== $level ) {
				if ( ! isset( $ranks[ $level ] ) ) {
					throw new Release_Exception( "Invalid changeset bump: $level" );
				}
				if ( null === $bump || $ranks[ $level ] > $ranks[ $bump ] ) {
					$bump = $level;
				}
				$notes[] = [
					'name' => $changeset['name'],
					'summary' => $changeset['summary'],
				];
			}
		}
		$next = $current_version;
		if ( null !== $bump ) {
			$index = [
				'major' => 0,
				'minor' => 1,
				'patch' => 2,
			][ $bump ];
			if ( (string) PHP_INT_MAX === $parts[ $index ] ) {
				throw new Release_Exception( "Version component cannot be incremented: $current_version" );
			}
			$parts[ $index ] = (string) ( (int) $parts[ $index ] + 1 );
			for ( $i = $index + 1; $i < 3; $i++ ) {
				$parts[ $i ] = '0';
			}
			$next = implode( '.', $parts );
		}
		return [
			'bump' => $bump,
			'nextVersion' => $next,
			'notes' => $notes,
		];
	}
}
