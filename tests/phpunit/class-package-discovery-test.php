<?php

namespace WPElevator\PHP_Changesets_Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WPElevator\PHP_Changesets\Package_Discovery;
use WPElevator\PHP_Changesets\Project_Files;
use WPElevator\PHP_Changesets\Release_Exception;

require_once __DIR__ . '/../../php/bootstrap.php';

final class Package_Discovery_Test extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/php-changesets-discovery-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->root );
		mkdir( $this->root . '/a' );
		mkdir( $this->root . '/b' );
		mkdir( $this->root . '/nested/sub', 0777, true );
		file_put_contents( $this->root . '/a/composer.json', "{\"name\":\"nam-a\"}\n" );
		file_put_contents( $this->root . '/b/package.json', "{\"name\":\"nam-b\"}\n" );
	}

	protected function tearDown(): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}

		rmdir( $this->root );
	}

	private function group( string $glob, array $group = [] ): array {
		return $this->discover( [ array_merge( [ 'glob' => $glob ], $group ) ] );
	}

	private function discover( array $packages, ?string $root = null ): array {
		$grouped = array_map(
			static fn( array $group ): array => array_merge(
				[
					'version' => [
						'from' => \WPElevator\PHP_Changesets\git_tag_version(),
						'to' => [],
					],
				],
				$group
			),
			$packages
		);
		$settings = null === $root ? [ 'packages' => $grouped ] : [
			'root' => $root,
			'packages' => $grouped,
		];
		$config = \WPElevator\PHP_Changesets\define_config( $settings );

		return ( new Package_Discovery( new Project_Files( $this->root ) ) )->discover( $config );
	}

	public function test_globs_match_relative_directories_in_order(): void {
		$packages = $this->group( '*' );

		$this->assertSame( [ 'a', 'b', 'nested' ], array_column( $packages, 'path' ), 'Single-segment globs match their path level in sorted order.' );
		$this->assertSame( [ 'a', 'b', 'nested' ], array_column( $packages, 'name' ), 'Path identifiers fall back to the directory name.' );
		$this->assertSame( $this->root . '/a', $packages[0]['directory'], 'Each package carries its absolute directory.' );
	}

	public function test_limit_depth_globs_do_not_recurse_deeper_than_needed(): void {
		$packages = $this->group( 'nested/sub' );

		$this->assertSame( [ 'nested/sub' ], array_column( $packages, 'path' ), 'Exact globs match one directory.' );
	}

	public function test_empty_glob_matches_fail(): void {
		try {
			$this->group( 'missing/*' );
			$this->fail( 'Empty glob matches must fail.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'missing/*', $error->getMessage(), 'The diagnostic identifies the empty glob.' );
			$this->assertStringContainsString( 'did not match any directories', $error->getMessage(), 'The diagnostic explains the empty match.' );
		}
	}

	public function test_excludes_remove_matches(): void {
		$packages = $this->group( '*', [ 'exclude' => [ 'a', 'nested/**' ] ] );

		$this->assertSame( [ 'b', 'nested' ], array_column( $packages, 'name' ), 'Excluded paths drop out; the parent directory itself only drops with its own pattern.' );
	}

	public function test_identifiers_come_from_manifests(): void {
		$packages = $this->discover(
			[
				[
					'glob' => 'a',
					'idFrom' => 'composer.json#name',
				],
				[
					'glob' => 'b',
					'idFrom' => 'package.json#name',
				],
				[ 'glob' => 'nested' ],
			]
		);

		$this->assertSame(
			[ 'nam-a', 'nam-b', 'nested' ],
			array_column( $packages, 'name' ),
			'Manifest identifiers and path identifiers resolve per group.'
		);
		$this->assertSame( [ 0, 1, 2 ], array_column( $packages, 'group' ), 'Packages stay in configured group order.' );
	}

	public function test_the_root_package_needs_an_id_or_a_manifest(): void {
		try {
			$this->group( '.' );
			$this->fail( 'A root package named by its path must be rejected.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'set its id', $error->getMessage(), 'The checkout directory name never names the root package; the diagnostic points at the id.' );
		}

		$this->assertSame( [ 'example' ], array_column( $this->group( '.', [ 'id' => 'example' ] ), 'name' ), 'A fixed id names the root package.' );
	}

	public function test_unresolvable_identifiers_fail(): void {
		mkdir( $this->root . '/c' );

		$this->expectException( Release_Exception::class );
		$this->expectExceptionMessage( 'composer.json#name' );

		$this->group( 'c', [ 'idFrom' => 'composer.json#name' ] );
	}

	public function test_duplicate_identifiers_fail(): void {
		file_put_contents( $this->root . '/b/composer.json', "{\"name\":\"nam-a\"}\n" );

		$this->expectException( Release_Exception::class );
		$this->expectExceptionMessage( 'more than once' );

		$this->discover(
			[
				[
					'glob' => 'a',
					'idFrom' => 'composer.json#name',
				],
				[
					'glob' => 'b',
					'idFrom' => 'composer.json#name',
				],
			]
		);
	}

	public function test_skipped_directories_never_contain_packages(): void {
		mkdir( $this->root . '/vendor' );
		mkdir( $this->root . '/vendor/real' );
		mkdir( $this->root . '/dist' );
		mkdir( $this->root . '/dist/built' );
		mkdir( $this->root . '/node_modules' );
		mkdir( $this->root . '/node_modules/nodepkg' );
		mkdir( $this->root . '/.git' );
		mkdir( $this->root . '/.git/refs' );

		$packages = $this->group( '**' );

		$this->assertSame( [ 'a', 'b', 'nested', 'nested/sub' ], array_column( $packages, 'path' ), 'Tool and build directories are never package candidates.' );
	}

	public function test_symlinked_directories_are_skipped(): void {
		mkdir( $this->root . '/target' );
		symlink( $this->root . '/target', $this->root . '/linked' );

		$packages = $this->group( '*' );

		$this->assertSame( [ 'a', 'b', 'nested', 'target' ], array_column( $packages, 'path' ), 'Symlinked directories stay out of discovery.' );
	}

	public function test_root_config_limits_discovery(): void {
		mkdir( $this->root . '/work' );
		mkdir( $this->root . '/work/alpha' );
		mkdir( $this->root . '/outside' );

		$packages = $this->discover( [ [ 'glob' => '*' ] ], 'work' );

		$this->assertSame( [ 'alpha' ], array_column( $packages, 'path' ), 'Package discovery is bounded by the configured root.' );
	}
}
