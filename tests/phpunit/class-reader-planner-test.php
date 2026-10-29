<?php

namespace WPElevator\PHP_Changesets_Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WPElevator\PHP_Changesets\Changeset_Reader;
use WPElevator\PHP_Changesets\Release_Exception;
use WPElevator\PHP_Changesets\Version_Planner;
use function WPElevator\PHP_Changesets\define_config;
use function WPElevator\PHP_Changesets\git_tag_version;

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
				$path = $this->directory . '/' . $name;

				if ( is_dir( $path ) && ! is_link( $path ) ) {
					rmdir( $path );
				} else {
					unlink( $path );
				}
			}
		}

		rmdir( $this->directory );
	}

	public function test_public_parameter_names_use_snake_case(): void {
		$reader = new ReflectionMethod( Changeset_Reader::class, 'read' );
		$planner = new ReflectionMethod( Version_Planner::class, 'plan' );

		$this->assertSame( 'package_names', $reader->getParameters()[1]->getName(), 'The reader uses a snake_case public parameter name.' );
		$this->assertSame(
			[ 'config', 'packages', 'changesets', 'current_versions' ],
			array_map( static fn( \ReflectionParameter $parameter ): string => $parameter->getName(), $planner->getParameters() ),
			'The planner uses snake_case public parameter names.'
		);
	}

	public function test_reads_multi_package_mapping_and_plans_per_package(): void {
		file_put_contents( $this->directory . '/z-fix.md', "---\nexample/alpha: patch\n---\n\nAlpha fix.\n" );
		file_put_contents( $this->directory . '/a-feature.md', "---\n\"example/beta\": minor\n---\n\nMinor note.\n" );
		file_put_contents( $this->directory . '/shared.md', "---\nexample/alpha: major\nexample/beta: patch\n---\n\nShared note.\n" );
		file_put_contents( $this->directory . '/empty.md', "---\n---\n" );
		file_put_contents( $this->directory . '/README.md', 'Ignored' );

		$records = ( new Changeset_Reader() )->read( $this->directory, [ 'example/alpha', 'example/beta' ] );

		$this->assertSame( [ 'a-feature.md', 'empty.md', 'shared.md', 'z-fix.md' ], array_column( $records, 'name' ), 'Changesets should be ordered by file name and ignore README.md.' );
		$this->assertSame( realpath( $this->directory ) . '/a-feature.md', $records[0]['file'], 'Each record should identify its absolute file path.' );
		$this->assertSame( [ 'example/beta' => 'minor' ], $records[0]['packages'], 'Frontmatter should map package names to bumps.' );
		$this->assertSame(
			[
				'example/alpha' => 'major',
				'example/beta' => 'patch',
			],
			$records[2]['packages'],
			'One changeset may name several packages.'
		);
		$this->assertSame( [], $records[1]['packages'], 'Empty frontmatter should record a no-release changeset.' );

		$config = $this->config();
		$packages = [
			[
				'name' => 'example/alpha',
				'path' => 'packages/alpha',
				'directory' => $this->directory . '/packages/alpha',
				'group' => 0,
			],
			[
				'name' => 'example/beta',
				'path' => 'packages/beta',
				'directory' => $this->directory . '/packages/beta',
				'group' => 0,
			],
		];
		$releases = ( new Version_Planner() )->plan(
			$config,
			$packages,
			$records,
			[
				'example/alpha' => '0.2.7',
				'example/beta' => '1.0.0',
			]
		);

		$this->assertSame(
			[
				[
					'package' => 'example/alpha',
					'currentVersion' => '0.2.7',
					'bump' => 'major',
					'nextVersion' => '1.0.0',
					'tag' => 'example/alpha-v1.0.0',
					'changesets' => [
						[
							'file' => 'shared.md',
							'packages' => [
								'example/alpha' => 'major',
								'example/beta' => 'patch',
							],
							'summary' => 'Shared note.',
						],
						[
							'file' => 'z-fix.md',
							'packages' => [ 'example/alpha' => 'patch' ],
							'summary' => 'Alpha fix.',
						],
					],
					'notes' => [
						[
							'name' => 'shared.md',
							'summary' => 'Shared note.',
						],
						[
							'name' => 'z-fix.md',
							'summary' => 'Alpha fix.',
						],
					],
				],
				[
					'package' => 'example/beta',
					'currentVersion' => '1.0.0',
					'bump' => 'minor',
					'nextVersion' => '1.1.0',
					'tag' => 'example/beta-v1.1.0',
					'changesets' => [
						[
							'file' => 'a-feature.md',
							'packages' => [ 'example/beta' => 'minor' ],
							'summary' => 'Minor note.',
						],
						[
							'file' => 'shared.md',
							'packages' => [
								'example/alpha' => 'major',
								'example/beta' => 'patch',
							],
							'summary' => 'Shared note.',
						],
					],
					'notes' => [
						[
							'name' => 'a-feature.md',
							'summary' => 'Minor note.',
						],
						[
							'name' => 'shared.md',
							'summary' => 'Shared note.',
						],
					],
				],
			],
			$releases,
			'The highest bump should win per package and release notes should aggregate in filename order.'
		);
	}

	public function test_packages_without_changesets_plan_null_bump_releases(): void {
		$releases = ( new Version_Planner() )->plan(
			$this->config(),
			[
				[
					'name' => 'example/alpha',
					'path' => '.',
					'directory' => $this->directory,
					'group' => 0,
				],
			],
			[
				[
					'file' => $this->directory . '/empty.md',
					'name' => 'empty.md',
					'packages' => [],
					'summary' => '',
				],
			],
			[ 'example/alpha' => '1.2.3' ]
		);

		$this->assertSame(
			[
				[
					'package' => 'example/alpha',
					'currentVersion' => '1.2.3',
					'bump' => null,
					'nextVersion' => '1.2.3',
					'tag' => null,
					'changesets' => [],
					'notes' => [],
				],
			],
			$releases,
			'A package without changesets keeps its version and has a null bump and tag.'
		);
	}

	public function test_next_version_hook_replaces_the_stable_bump(): void {
		$captured = null;
		$config = $this->config(
			[
				'nextVersion' => static function ( array $input ) use ( &$captured ): string {
					$captured = $input;

					return sprintf( '%s.0.0', (int) $input['currentVersion'] + 1 );
				},
			]
		);

		$releases = ( new Version_Planner() )->plan(
			$config,
			[
				[
					'name' => 'example/alpha',
					'path' => 'packages/alpha',
					'directory' => $this->directory,
					'group' => 0,
				],
			],
			[
				[
					'file' => $this->directory . '/fix.md',
					'name' => 'fix.md',
					'packages' => [ 'example/alpha' => 'patch' ],
					'summary' => 'Fix.',
				],
			],
			[ 'example/alpha' => '2.2.7' ]
		);

		$this->assertSame(
			[
				'package' => [
					'name' => 'example/alpha',
					'path' => 'packages/alpha',
					'currentVersion' => '2.2.7',
				],
				'bump' => 'patch',
				'currentVersion' => '2.2.7',
				'changesets' => [
					[
						'file' => 'fix.md',
						'packages' => [ 'example/alpha' => 'patch' ],
						'summary' => 'Fix.',
					],
				],
			],
			$captured,
			'The hook input carries the package record, bump, current version and changeset records like wp-release NextVersionInput.'
		);

		$this->assertSame( '3.0.0', $releases[0]['nextVersion'], 'The nextVersion hook replaces the stable bump computation.' );
		$this->assertSame( '2.2.7', $releases[0]['currentVersion'], 'The hook input reports the current version.' );
		$this->assertSame( 'patch', $releases[0]['bump'], 'The hook input reports the highest bump.' );
	}

	public function test_next_version_hook_invalid_results_are_rejected(): void {
		$config = $this->config( [ 'nextVersion' => static fn( array $input ): string => $input['currentVersion'] . '+build' ] );

		try {
			( new Version_Planner() )->plan(
				$config,
				[
					[
						'name' => 'example/alpha',
						'path' => '.',
						'directory' => $this->directory,
						'group' => 0,
					],
				],
				[
					[
						'file' => $this->directory . '/fix.md',
						'name' => 'fix.md',
						'packages' => [ 'example/alpha' => 'patch' ],
						'summary' => 'Fix.',
					],
				],
				[ 'example/alpha' => '1.2.3' ]
			);
			$this->fail( 'Build metadata results must be rejected from the nextVersion hook.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'nextVersion hook', $error->getMessage(), 'The diagnostic names the failing hook.' );
		}
	}

	public function test_stable_planning_graduates_a_prerelease(): void {
		$releases = ( new Version_Planner() )->plan(
			$this->config(),
			[
				[
					'name' => 'example/alpha',
					'path' => 'packages/alpha',
					'directory' => $this->directory,
					'group' => 0,
				],
			],
			[
				[
					'file' => $this->directory . '/fix.md',
					'name' => 'fix.md',
					'packages' => [ 'example/alpha' => 'patch' ],
					'summary' => 'Fix.',
				],
			],
			[ 'example/alpha' => '1.2.4-rc.1' ]
		);

		$this->assertSame( '1.2.4-rc.1', $releases[0]['currentVersion'], 'The pre-release current version is reported unchanged.' );
		$this->assertSame( '1.2.4', $releases[0]['nextVersion'], 'A patch of a pre-release releases its base like semver inc, instead of skipping it.' );
		$this->assertSame( 'example/alpha-v1.2.4', $releases[0]['tag'], 'The stable plan proposes the stable tag.' );
	}

	/**
	 * @dataProvider wordpress_versions
	 */
	public function test_two_and_four_part_versions_keep_their_shape( string $current, string $bump, string $next ): void {
		$releases = ( new Version_Planner() )->plan(
			$this->config(),
			[
				[
					'name' => 'example/alpha',
					'path' => 'packages/alpha',
					'directory' => $this->directory,
					'group' => 0,
				],
			],
			[
				[
					'file' => $this->directory . '/fix.md',
					'name' => 'fix.md',
					'packages' => [ 'example/alpha' => $bump ],
					'summary' => 'Fix.',
				],
			],
			[ 'example/alpha' => $current ]
		);

		$this->assertSame( $next, $releases[0]['nextVersion'], "A $bump of $current keeps the part count like wp-release getNextVersion()." );
	}

	public static function wordpress_versions(): array {
		return [
			[ '2.1', 'minor', '2.2' ],
			[ '2.1', 'patch', '2.1.1' ],
			[ '1.2.3.4', 'minor', '1.3.0.0' ],
			[ '1.2.3.4', 'patch', '1.2.4.0' ],
		];
	}

	public function test_tag_formats_expand_name_path_and_version(): void {
		$changesets = [
			[
				'file' => $this->directory . '/fix.md',
				'name' => 'fix.md',
				'packages' => [ 'example/alpha' => 'minor' ],
				'summary' => 'Add a feature.',
			],
		];

		$releases = ( new Version_Planner() )->plan(
			$this->config(),
			[
				[
					'name' => 'example/alpha',
					'path' => 'packages/alpha',
					'directory' => $this->directory,
					'group' => 0,
				],
			],
			$changesets,
			[ 'example/alpha' => '0.9.9' ]
		);

		$this->assertSame( 'example/alpha-v0.10.0', $releases[0]['tag'], 'Tag formats expand the placeholders.' );

		$releases = ( new Version_Planner() )->plan(
			$this->config(
				[
					'tagFormat' => '{path}/{version}',
					'packages' => [
						[
							'glob' => 'packages/*',
							'tagFormat' => 'tags/{name}/{version}',
							'version' => [
								'from' => git_tag_version(),
								'to' => [],
							],
						],
					],
				]
			),
			[
				[
					'name' => 'example/alpha',
					'path' => 'packages/alpha',
					'directory' => $this->directory,
					'group' => 0,
				],
			],
			$changesets,
			[ 'example/alpha' => '0.9.9' ]
		);

		$this->assertSame( 'tags/example/alpha/0.10.0', $releases[0]['tag'], 'A per-group tag format overrides the repository setting.' );

		$releases = ( new Version_Planner() )->plan(
			$this->config(
				[
					'tagFormat' => '{path}/{version}',
					'packages' => [
						[
							'glob' => '.',
							'version' => [
								'from' => git_tag_version(),
								'to' => [],
							],
						],
					],
				]
			),
			[
				[
					'name' => 'example/alpha',
					'path' => '.',
					'directory' => $this->directory,
					'group' => 0,
				],
			],
			$changesets,
			[ 'example/alpha' => '0.9.9' ]
		);

		$this->assertSame( '0.10.0', $releases[0]['tag'], 'A package at the repository root leaves no empty path prefix.' );
	}

	public function test_escaped_double_quote_in_package_name(): void {
		file_put_contents( $this->directory . '/quoted.md', "---\n\"example/quoted\\\"name\": patch\n---\nNote\n" );

		$records = ( new Changeset_Reader() )->read( $this->directory, [ 'example/quoted"name' ] );

		$this->assertSame( 'patch', $records[0]['packages']['example/quoted"name'], 'An escaped double quote should match the corresponding package name.' );
	}

	public function test_missing_directory_has_no_changesets(): void {
		$records = ( new Changeset_Reader() )->read( $this->directory . '/missing', [ 'example/project' ] );

		$this->assertSame( [], $records, 'A project without a changeset directory should have no pending changesets.' );
	}

	public function test_rejects_dangling_directory_symlink(): void {
		symlink( $this->directory . '/missing', $this->directory . '/linked' );

		$this->expectException( Release_Exception::class );

		( new Changeset_Reader() )->read( $this->directory . '/linked', [ 'example/project' ] );
	}

	public function test_rejects_symlinked_changeset(): void {
		symlink( __FILE__, $this->directory . '/linked.md' );

		$this->expectException( Release_Exception::class );

		( new Changeset_Reader() )->read( $this->directory, [ 'example/project' ] );
	}

	/**
	 * @dataProvider invalid_changesets
	 */
	public function test_rejects_invalid_changesets( string $contents ): void {
		file_put_contents( $this->directory . '/bad.md', $contents );

		$this->expectException( Release_Exception::class );

		( new Changeset_Reader() )->read( $this->directory, [ 'example/project', 'example/other' ] );
	}

	public static function invalid_changesets(): array {
		return [
			[ 'missing frontmatter' ],
			[ "---\nexample/project: patch\n" ],
			[ "---\nexample/project: patch\n---\n" ],
			[ "---\nexample/project: invalid\n---\nNote" ],
			[ "---\nother/project: patch\n---\nNote" ],
			[ "---\nexample/project: patch\n---\n" ],
			[ "---\nexample/project: patch\nexample/project: minor\n---\nNote" ],
			[ "---\nexample/project: patch\nother/project: minor\n---\nNote" ],
			[ "---\nexample/project: [patch]\n---\nNote" ],
		];
	}

	/**
	 * @dataProvider invalid_versions
	 */
	public function test_rejects_non_stable_versions( string $version ): void {
		$this->expectException( Release_Exception::class );

		( new Version_Planner() )->plan(
			$this->config(),
			[
				[
					'name' => 'example/alpha',
					'path' => '.',
					'directory' => $this->directory,
					'group' => 0,
				],
			],
			[],
			[ 'example/alpha' => $version ]
		);
	}

	public static function invalid_versions(): array {
		return [ [ '1' ], [ '01.2.3' ], [ '1.2.3.4.5' ], [ '1.2.3+build' ], [ 'v1.2.3' ] ];
	}

	private function config( array $overrides = [] ): array {
		$settings = array_merge(
			[
				'tagFormat' => '{name}-v{version}',
				'packages' => [
					[
						'glob' => 'packages/*',
						'version' => [
							'from' => git_tag_version(),
							'to' => [],
						],
					],
				],
			],
			$overrides
		);

		if ( isset( $settings['packages'] ) && [] === $settings['packages'] ) {
			unset( $settings['packages'] );
		}

		return define_config( $settings );
	}
}
