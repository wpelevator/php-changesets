<?php

namespace WPElevator\PHP_Changesets_Tests;

use PHPUnit\Framework\TestCase;
use WPElevator\PHP_Changesets\Release_Exception;
use WPElevator\PHP_Changesets\Semver;

require_once __DIR__ . '/../../php/bootstrap.php';

final class Semver_Test extends TestCase {

	/**
	 * @dataProvider valid_versions
	 */
	public function test_supported_versions_parse( string $version ): void {
		$this->assertTrue( ( new Semver() )->is_valid( $version ), "The version $version is supported." );
	}

	public static function valid_versions(): array {
		return [
			[ '1.2.3' ],
			[ '0.0.0' ],
			[ '1.2.3-rc.0' ],
			[ '1.2.3-rc.11' ],
			[ '1.2.3-x.7.z.92' ],
			[ '1.2.4-rc.1' ],
			[ '2.1' ],
			[ '1.2.3.4' ],
			[ '2.1-beta.1' ],
		];
	}

	/**
	 * @dataProvider invalid_versions
	 */
	public function test_unsupported_versions_reject( string $version ): void {
		$this->assertFalse( ( new Semver() )->is_valid( $version ), "The version $version is not supported." );
	}

	public static function invalid_versions(): array {
		return [
			[ '1' ],
			[ '1.2.3.4.5' ],
			[ '1.2.3-' ],
			[ '1.2.3-.' ],
			[ '1.2.3+build' ],
			[ '1.2.3-rc.1+build' ],
			[ 'v1.2.3' ],
			[ '' ],
		];
	}

	public function test_the_stable_part_drops_the_prerelease(): void {
		$this->assertSame( '1.2.4', ( new Semver() )->main( '1.2.4-rc.1' ), 'The stable part of a pre-release is its base.' );
		$this->assertSame( '1.2.4', ( new Semver() )->main( '1.2.4' ), 'Stable versions carry their own main part.' );
	}

	/**
	 * @dataProvider bumps
	 */
	public function test_bumps_follow_semver_inc_and_keep_the_part_count( string $version, string $bump, string $expected ): void {
		$this->assertSame( $expected, ( new Semver() )->next( $version, $bump ), "A $bump of $version plans $expected." );
	}

	public static function bumps(): array {
		return [
			[ '1.2.3', 'patch', '1.2.4' ],
			[ '1.2.3', 'minor', '1.3.0' ],
			[ '1.2.3', 'major', '2.0.0' ],
			[ '2.1', 'patch', '2.1.1' ],
			[ '1.2.3.4', 'major', '2.0.0.0' ],
			[ '1.2.4-rc.1', 'patch', '1.2.4' ],
			[ '1.2.4-rc.1', 'minor', '1.3.0' ],
			[ '1.3.0-rc.1', 'minor', '1.3.0' ],
			[ '1.3.0-rc.1', 'major', '2.0.0' ],
			[ '2.0.0-rc.1', 'major', '2.0.0' ],
		];
	}

	public function test_missing_parts_compare_as_zero(): void {
		$this->assertSame( 0, ( new Semver() )->compare( '2.1', '2.1.0' ), 'A missing part counts as zero.' );
		$this->assertSame( 1, ( new Semver() )->compare( '1.2.3.1', '1.2.3' ), 'A fourth part outranks its absence.' );
	}

	public function test_precendence_compare_semantics(): void {
		$versions = [ '1.9.0', '1.10.0', '2.0.0-rc.1', '2.0.0-rc.2', '2.0.0-rc.11', '2.0.0-x.2', '2.0.0' ];

		$sorted = $versions;

		usort(
			$sorted,
			static fn( string $a, string $b ): int => ( new Semver() )->compare( $a, $b )
		);

		$this->assertSame(
			[ '1.9.0', '1.10.0', '2.0.0-rc.1', '2.0.0-rc.2', '2.0.0-rc.11', '2.0.0-x.2', '2.0.0' ],
			$sorted,
			'Stable versions outrank pre-releases of the same base and sequences compare numerically.'
		);
	}

	/**
	 * @dataProvider prerelease_inputs
	 */
	public function test_bare_identifiers_number_above_the_existing_tags( string $id, array $existing, string $expected ): void {
		$this->assertSame( $expected, ( new Semver() )->with_prerelease( '1.3.0', $id, $existing ), 'The pre-release transform numbers or reuses exactly like wp-release.' );
	}

	public static function prerelease_inputs(): array {
		return [
			[ 'rc', [], '1.3.0-rc.1' ],
			[ 'rc', [ '1.3.0-rc.0', '1.3.0-rc.3' ], '1.3.0-rc.4' ],
			[ 'beta', [ '1.3.0-rc.9' ], '1.3.0-beta.1' ],
			[ 'rc.2', [ '1.3.0-rc.1' ], '1.3.0-rc.2' ],
			[ 'nightly-pr-251', [], '1.3.0-nightly-pr-251.1' ],
		];
	}

	public function test_an_existing_explicit_prerelease_is_rejected(): void {
		try {
			( new Semver() )->with_prerelease( '1.3.0', 'rc.1', [ '1.3.0-rc.1', '1.3.0-rc.0' ] );
			$this->fail( 'An exact existing version must be rejected.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( '1.3.0-rc.1 already exists', $error->getMessage(), 'The diagnostic identifies the conflicting version.' );
		}
	}

	/**
	 * @dataProvider broken_prerelease_inputs
	 */
	public function test_broken_prerelease_inputs_are_rejected( string $id ): void {
		try {
			( new Semver() )->with_prerelease( '1.3.0', $id, [] );
			$this->fail( "The pre-release identifier $id must be rejected." );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'Invalid pre-release', $error->getMessage(), 'The diagnostic names the identifier shape.' );
		}
	}

	public static function broken_prerelease_inputs(): array {
		return [
			[ '' ],
			[ '1nightly' ],
			[ 'rc.1.2' ],
			[ 'rc.01' ],
			[ 'rc.01.1' ],
		];
	}

	public function test_a_prerelease_stable_input_is_rejected(): void {
		try {
			( new Semver() )->with_prerelease( '1.3.0-rc.1', 'beta', [] );
			$this->fail( 'A pre-release stable input must be rejected.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'expected a stable version', $error->getMessage(), 'The diagnostic explains the stable requirement.' );
		}
	}

	public function test_invalid_stable_inputs_are_rejected(): void {
		try {
			( new Semver() )->with_prerelease( '1.2.3.4.5', 'rc', [] );
			$this->fail( 'An unsupported stable input must be rejected.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'expected a stable version', $error->getMessage(), 'The diagnostic explains the stable requirement.' );
		}
	}
}
