<?php

namespace WPElevator\PHP_Changesets_Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
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

	public function test_public_parameter_names_use_snake_case(): void {
		$reader = new ReflectionMethod( Changeset_Reader::class, 'read' );
		$planner = new ReflectionMethod( Version_Planner::class, 'plan' );

		$this->assertSame( 'project_name', $reader->getParameters()[1]->getName(), 'The reader uses a snake_case public parameter name.' );
		$this->assertSame( 'current_version', $planner->getParameters()[0]->getName(), 'The planner uses a snake_case public parameter name.' );
	}

	public function test_reads_sorted_records_and_plans_highest_bump(): void {
		file_put_contents( $this->directory . '/z.md', "---\n'example/project': patch\n---\n\nPatch note.\n" );
		file_put_contents( $this->directory . '/a.md', "---\n\"example/project\": minor\n---\n\nMinor note.\n" );
		file_put_contents( $this->directory . '/empty.md', "---\n---\n" );
		file_put_contents( $this->directory . '/README.md', 'Ignored' );

		$records = ( new Changeset_Reader() )->read( $this->directory, 'example/project' );

		$this->assertSame( [ 'a.md', 'empty.md', 'z.md' ], array_column( $records, 'name' ), 'Changesets should be ordered by file name and ignore README.md.' );
		$this->assertSame( realpath( $this->directory ) . '/a.md', $records[0]['file'], 'Each record should identify its absolute file path.' );
		$this->assertNull( $records[1]['bump'], 'Empty frontmatter should record no release.' );
		$this->assertSame(
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

	public function test_reads_escaped_double_quote_in_project_name(): void {
		file_put_contents( $this->directory . '/quoted.md', "---\n\"example/quoted\\\"name\": patch\n---\nNote\n" );

		$records = ( new Changeset_Reader() )->read( $this->directory, 'example/quoted"name' );

		$this->assertSame( 'patch', $records[0]['bump'], 'An escaped double quote should match the corresponding project name.' );
	}

	public function test_missing_directory_has_no_changesets(): void {
		$records = ( new Changeset_Reader() )->read( $this->directory . '/missing', 'example/project' );

		$this->assertSame( [], $records, 'A project without a changeset directory should have no pending changesets.' );
	}

	public function test_rejects_dangling_directory_symlink(): void {
		symlink( $this->directory . '/missing', $this->directory . '/linked' );

		$this->expectException( Release_Exception::class );

		( new Changeset_Reader() )->read( $this->directory . '/linked', 'example/project' );
	}

	public function test_empty_changesets_do_not_bump(): void {
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

		$this->assertSame(
			[
				'bump' => null,
				'nextVersion' => '1.2.3',
				'notes' => [],
			],
			$plan,
			'A no-release changeset should leave the version and notes unchanged.'
		);
	}

	/**
	 * @dataProvider invalid_changesets
	 */
	public function test_rejects_invalid_changesets( string $contents ): void {
		file_put_contents( $this->directory . '/bad.md', $contents );

		$this->expectException( Release_Exception::class );

		( new Changeset_Reader() )->read( $this->directory, 'example/project' );
	}

	public static function invalid_changesets(): array {
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

	public function test_rejects_symlinked_changeset(): void {
		symlink( __FILE__, $this->directory . '/linked.md' );

		$this->expectException( Release_Exception::class );

		( new Changeset_Reader() )->read( $this->directory, 'example/project' );
	}

	/**
	 * @dataProvider invalid_versions
	 */
	public function test_rejects_non_stable_versions( string $version ): void {
		$this->expectException( Release_Exception::class );

		( new Version_Planner() )->plan( $version, [] );
	}

	public static function invalid_versions(): array {
		return [ [ '1.2' ], [ '01.2.3' ], [ '1.2.3-rc.1' ], [ 'v1.2.3' ] ];
	}
}
