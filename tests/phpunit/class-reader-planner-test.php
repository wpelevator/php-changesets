<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WPElevator\PHP_Changesets\Changeset_Reader;
use WPElevator\PHP_Changesets\Release_Exception;
use WPElevator\PHP_Changesets\Version_Planner;

require_once __DIR__ . '/../../php/bootstrap.php';

final class Reader_Planner_Test extends TestCase {

	private string $directory;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/php-changesets-' . bin2hex( random_bytes( 8 ) );
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

	public function testReadsSortedRecordsAndPlansHighestBump(): void {
		file_put_contents( $this->directory . '/z.md', "---\n'example/project': patch\n---\n\nPatch note.\n" );
		file_put_contents( $this->directory . '/a.md', "---\n\"example/project\": minor\n---\n\nMinor note.\n" );
		file_put_contents( $this->directory . '/empty.md', "---\n---\n" );
		file_put_contents( $this->directory . '/README.md', 'Ignored' );
		$records = ( new Changeset_Reader() )->read( $this->directory, 'example/project' );
		self::assertSame( [ 'a.md', 'empty.md', 'z.md' ], array_column( $records, 'name' ), 'Changesets should be ordered by file name and ignore README.md.' );
		self::assertSame( realpath( $this->directory ) . '/a.md', $records[0]['file'], 'Each record should identify its absolute file path.' );
		self::assertNull( $records[1]['bump'], 'Empty frontmatter should record no release.' );
		self::assertSame(
			[
				'bump' => 'minor',
				'nextVersion' => '0.3.0',
				'notes' => [
					[
						'name' => 'a.md',
						'summary' => 'Minor note.',
					],
					[
						'name' => 'z.md',
						'summary' => 'Patch note.',
					],
				],
			],
			( new Version_Planner() )->plan( '0.2.7', array_reverse( $records ) ),
			'The highest bump should win and release notes should remain sorted.'
		);
	}

	public function testReadsEscapedDoubleQuoteInProjectName(): void {
		file_put_contents( $this->directory . '/quoted.md', "---\n\"example/quoted\\\"name\": patch\n---\nNote\n" );
		$records = ( new Changeset_Reader() )->read( $this->directory, 'example/quoted"name' );
		self::assertSame( 'patch', $records[0]['bump'], 'An escaped double quote should match the corresponding project name.' );
	}


	public function testMissingDirectoryHasNoChangesets(): void {
		$records = ( new Changeset_Reader() )->read( $this->directory . '/missing', 'example/project' );
		self::assertSame( [], $records, 'A project without a changeset directory should have no pending changesets.' );
	}

	public function testRejectsDanglingDirectorySymlink(): void {
		symlink( $this->directory . '/missing', $this->directory . '/linked' );
		$this->expectException( Release_Exception::class );
		( new Changeset_Reader() )->read( $this->directory . '/linked', 'example/project' );
	}

	public function testEmptyChangesetsDoNotBump(): void {
		$plan = ( new Version_Planner() )->plan(
			'1.2.3',
			[
				[
					'name' => 'empty.md',
					'bump' => null,
					'summary' => '',
				],
			]
		);
		self::assertSame(
			[
				'bump' => null,
				'nextVersion' => '1.2.3',
				'notes' => [],
			],
			$plan,
			'A no-release changeset should leave the version and notes unchanged.'
		);
	}

	/** @dataProvider invalidChangesets */
	public function testRejectsInvalidChangesets( string $contents ): void {
		file_put_contents( $this->directory . '/bad.md', $contents );
		$this->expectException( Release_Exception::class );
		( new Changeset_Reader() )->read( $this->directory, 'example/project' );
	}

	public static function invalidChangesets(): array {
		return [
			[ 'missing frontmatter' ],
			[ "---\nexample/project: patch\n" ],
			[ "---\nexample/project: patch\n---\n" ],
			[ "---\nexample/project: invalid\n---\nNote" ],
			[ "---\nother/project: patch\n---\nNote" ],
			[ "---\nexample/project: patch\nexample/project: minor\n---\nNote" ],
			[ "---\nexample/project: patch\nother/project: minor\n---\nNote" ],
			[ "---\nexample/project: [patch]\n---\nNote" ],
		];
	}

	public function testRejectsSymlinkedChangeset(): void {
		symlink( __FILE__, $this->directory . '/linked.md' );
		$this->expectException( Release_Exception::class );
		( new Changeset_Reader() )->read( $this->directory, 'example/project' );
	}

	/** @dataProvider invalidVersions */
	public function testRejectsNonStableVersions( string $version ): void {
		$this->expectException( Release_Exception::class );
		( new Version_Planner() )->plan( $version, [] );
	}

	public static function invalidVersions(): array {
		return [ [ '1.2' ], [ '01.2.3' ], [ '1.2.3-rc.1' ], [ 'v1.2.3' ] ];
	}
}
