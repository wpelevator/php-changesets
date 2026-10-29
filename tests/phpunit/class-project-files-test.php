<?php

namespace WPElevator\PHP_Changesets_Tests;

use PHPUnit\Framework\TestCase;
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
		foreach ( scandir( $this->directory ) as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				unlink( $this->directory . '/' . $name );
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
}
