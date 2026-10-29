<?php

namespace WPElevator\PHP_Changesets_Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WPElevator\PHP_Changesets\Project_Files;
use WPElevator\PHP_Changesets\Release_Exception;

require_once __DIR__ . '/../../php/bootstrap.php';

final class Project_Files_Test extends TestCase {

	private string $directory;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/php-changesets-files-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->directory );
	}

	protected function tearDown(): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}

		rmdir( $this->directory );
	}

	public function test_root_is_canonical_and_relative_paths_resolve(): void {
		$files = new Project_Files( $this->directory . '/.' );

		$this->assertSame( realpath( $this->directory ), $files->get_root(), 'The project root is canonicalized once.' );
		$this->assertSame( $this->directory . '/CHANGELOG.md', $files->resolve( 'CHANGELOG.md' ), 'A missing project file resolves within the canonical root.' );
	}

	public function test_missing_root_is_rejected(): void {
		$this->expectException( Release_Exception::class );
		$this->expectExceptionMessage( 'Project root does not exist.' );

		new Project_Files( $this->directory . '/missing' );
	}

	public function test_parent_traversal_is_rejected(): void {
		$files = new Project_Files( $this->directory );

		$this->expectException( Release_Exception::class );

		$files->resolve( '../outside' );
	}

	public function test_escaping_symlink_is_rejected(): void {
		symlink( sys_get_temp_dir(), $this->directory . '/linked' );
		$files = new Project_Files( $this->directory );

		$this->expectException( Release_Exception::class );

		$files->resolve( 'linked/outside' );
	}

	public function test_package_resolves_relative_to_the_package_directory(): void {
		mkdir( $this->directory . '/packages' );
		mkdir( $this->directory . '/packages/alpha' );
		$files = new Project_Files( $this->directory );
		$package = $files->package( 'packages/alpha' );

		$this->assertSame( $this->directory . '/packages/alpha/HEADER.md', $package->resolve( 'HEADER.md' ), 'A package resolver resolves paths inside its own root.' );
	}

	public function test_missing_package_directories_are_rejected(): void {
		$files = new Project_Files( $this->directory );

		$this->expectException( Release_Exception::class );

		$files->package( 'packages/missing' );
	}

	public function test_package_paths_must_stay_inside_the_project(): void {
		$files = new Project_Files( $this->directory );

		$this->expectException( Release_Exception::class );

		$files->package( '../outside' );
	}

	public function test_symlinked_package_directories_are_rejected(): void {
		symlink( $this->directory, $this->directory . '/linked' );
		$files = new Project_Files( $this->directory );

		$this->expectException( Release_Exception::class );

		$files->package( 'linked' );
	}
}
