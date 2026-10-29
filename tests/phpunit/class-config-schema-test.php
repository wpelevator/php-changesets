<?php

namespace WPElevator\PHP_Changesets_Tests;

use PHPUnit\Framework\TestCase;
use WPElevator\PHP_Changesets\Release_Exception;

require_once __DIR__ . '/../../php/bootstrap.php';

final class Config_Schema_Test extends TestCase {

	public function test_builders_produce_descriptors_for_sources_and_targets(): void {
		$this->assertSame(
			[
				'kind' => 'composer-json',
				'file' => 'composer.json',
				'ifPresent' => true,
			],
			\WPElevator\PHP_Changesets\composer_json_version(),
			'The Composer builder defaults to the root manifest with optional version.'
		);
		$this->assertSame(
			[
				'kind' => 'wordpress-plugin-header',
				'file' => 'plugin.php',
			],
			\WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ),
			'The plugin builder carries the main file path.'
		);
		$this->assertSame(
			[
				'kind' => 'wordpress-theme-header',
				'file' => 'style.css',
			],
			\WPElevator\PHP_Changesets\wordpress_theme_version( 'style.css' ),
			'The theme builder carries the stylesheet path.'
		);
		$this->assertSame(
			[
				'kind' => 'wordpress-stable-tag',
				'file' => 'readme.txt',
				'ifPresent' => true,
			],
			\WPElevator\PHP_Changesets\wordpress_stable_tag( 'readme.txt' ),
			'The stable tag builder keeps the readme path optional and skips missing files.'
		);
		$this->assertSame(
			[
				'kind' => 'git-tag',
				'pattern' => 'v{version}',
			],
			\WPElevator\PHP_Changesets\git_tag_version( 'v{version}' ),
			'The git tag builder carries the tag pattern.'
		);
		$this->assertSame(
			[
				'kind' => 'php-constant',
				'file' => 'src/version.php',
				'constant' => 'EXAMPLE_VERSION',
			],
			\WPElevator\PHP_Changesets\php_constant_version( 'src/version.php', 'EXAMPLE_VERSION' ),
			'The PHP constant builder carries the file and constant names.'
		);
	}

	public function test_define_applies_canonical_defaults(): void {
		$resolve = static fn( array $input ): string => '1.0.0';
		$format = static fn( array $input ): string => '- ' . $input['changeset']['summary'];

		$config = \WPElevator\PHP_Changesets\define_config(
			[
				'packages' => [
					[
						'glob' => 'plugins/*',
						'version' => [
							'from' => \WPElevator\PHP_Changesets\git_tag_version(),
							'to' => [],
						],
					],
				],
			]
		);

		$this->assertSame( '.changeset', $config['changesets'], 'Omitted changesets default to the changeset directory.' );
		$this->assertSame( '{path}/{version}', $config['tagFormat'], 'Omitted tag formats default to the wp-release path format.' );
		$this->assertNull( $config['root'], 'Omitted package roots resolve to the repository root.' );
		$this->assertSame( 'CHANGELOG.md', $config['changelog'], 'Omitted changelogs default to CHANGELOG.md.' );
		$this->assertNull( $config['nextVersion'], 'Omitted next-version hooks stay disabled.' );
		$this->assertNull( $config['changelogEntry'], 'Omitted changelog-entry hooks stay disabled.' );
		$this->assertSame( 'plugins/*', $config['packages'][0]['glob'], 'Groups keep their configured glob.' );
		$this->assertSame( [], $config['packages'][0]['exclude'], 'Omitted group exclusions stay empty.' );
		$this->assertSame( 'path', $config['packages'][0]['idFrom'], 'Omitted identifiers resolve from the package path.' );
		$this->assertNull( $config['packages'][0]['tagFormat'], 'Omitted group tag formats inherit the repository setting.' );
		$this->assertNull( $config['packages'][0]['id'], 'Omitted fixed identifiers leave naming to idFrom.' );

		$inherited = \WPElevator\PHP_Changesets\define_config(
			[
				'packages' => [
					[
						'glob' => 'plugins/*',
						'changelog' => null,
						'tagFormat' => null,
						'version' => [
							'from' => \WPElevator\PHP_Changesets\wordpress_plugin_version(),
							'to' => [ \WPElevator\PHP_Changesets\wordpress_plugin_version() ],
						],
					],
				],
				'nextVersion' => $resolve,
				'changelogEntry' => $format,
			]
		);

		$this->assertNull( $inherited['packages'][0]['changelog'], 'A null group changelog inherits the repository setting.' );
		$this->assertNull( $inherited['packages'][0]['tagFormat'], 'A null group tag format inherits the repository setting.' );
		$this->assertSame( $resolve, $inherited['nextVersion'], 'Configured next-version hooks are kept.' );
		$this->assertSame( $format, $inherited['changelogEntry'], 'Configured changelog-entry hooks are kept.' );

		$disabled = \WPElevator\PHP_Changesets\define_config(
			[
				'changelog' => '',
				'packages' => [
					[
						'glob' => 'plugins/*',
						'changelog' => '',
						'version' => [
							'from' => \WPElevator\PHP_Changesets\wordpress_plugin_version(),
							'to' => [ \WPElevator\PHP_Changesets\wordpress_plugin_version() ],
						],
					],
				],
			]
		);

		$this->assertSame( '', $disabled['changelog'], 'An empty repository changelog disables changelog generation.' );
		$this->assertSame( '', $disabled['packages'][0]['changelog'], 'An empty group changelog disables changelog generation.' );
	}

	public function test_define_rejects_unknown_top_level_keys(): void {
		try {
			\WPElevator\PHP_Changesets\define_config(
				[
					'dependents' => [ 'bump' => 'patch' ],
					'packages' => [
						[
							'glob' => 'plugins/*',
							'version' => [
								'from' => \WPElevator\PHP_Changesets\git_tag_version(),
								'to' => [],
							],
						],
					],
				]
			);
			$this->fail( 'Unknown top-level keys must be rejected.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'dependents', $error->getMessage(), 'The diagnostic names the unsupported key.' );
		}
	}

	public function test_define_requires_a_non_empty_packages_list(): void {
		foreach ( [
			null,
			[],
			[
				'glob' => 'x',
				'version' => [
					'from' => [ 'kind' => 'git-tag' ],
					'to' => [],
				],
			],
		] as $invalid ) {
			try {
				\WPElevator\PHP_Changesets\define_config( [ 'packages' => $invalid ] );
				$this->fail( 'Configuration requires a non-empty list of package groups.' );
			} catch ( Release_Exception $error ) {
				$this->assertStringContainsString( 'packages', $error->getMessage(), 'The diagnostic names the packages key.' );
			}
		}
	}

	public function test_define_rejects_group_errors(): void {
		$group = [
			'glob' => 'plugins/*',
			'releaseNotes' => static fn( array $input ): string => '',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\php_constant_version( 'src/version.php', 'EXAMPLE_VERSION' ),
				'to' => [],
			],
		];

		try {
			\WPElevator\PHP_Changesets\define_config( [ 'packages' => [ $group ] ] );
			$this->fail( 'Unknown group keys must be rejected.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'packages[0].releaseNotes', $error->getMessage(), 'The diagnostic locates the offending group key.' );
		}

		$group = [
			'glob' => 'plugins/*',
			'idFrom' => 'manifest.json#name',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\php_constant_version( 'src/version.php', 'EXAMPLE_VERSION' ),
				'to' => [],
			],
		];

		try {
			\WPElevator\PHP_Changesets\define_config( [ 'packages' => [ $group ] ] );
			$this->fail( 'Unsupported identifier sources must be rejected.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'idFrom', $error->getMessage(), 'The diagnostic names the identifier source.' );
		}

		$group = [
			'version' => [
				'from' => \WPElevator\PHP_Changesets\php_constant_version( 'src/version.php', 'EXAMPLE_VERSION' ),
				'to' => [],
			],
		];

		try {
			\WPElevator\PHP_Changesets\define_config( [ 'packages' => [ $group ] ] );
			$this->fail( 'Groups without a glob must be rejected.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'glob', $error->getMessage(), 'The diagnostic names the missing glob.' );
		}

		$group = [
			'glob' => 'plugins/alpha',
			'id' => '',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\php_constant_version( 'src/version.php', 'EXAMPLE_VERSION' ),
				'to' => [],
			],
		];

		try {
			\WPElevator\PHP_Changesets\define_config( [ 'packages' => [ $group ] ] );
			$this->fail( 'Empty fixed identifiers must be rejected.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'packages[0].id', $error->getMessage(), 'The diagnostic locates the empty identifier.' );
		}
	}

	public function test_define_rejects_invalid_version_shapes(): void {
		$group = [
			'glob' => 'plugins/*',
			'version' => [ 'from' => \WPElevator\PHP_Changesets\git_tag_version() ],
		];

		try {
			\WPElevator\PHP_Changesets\define_config( [ 'packages' => [ $group ] ] );
			$this->fail( 'A version without targets must be an explicit list.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'version', $error->getMessage(), 'The diagnostic names the version key.' );
		}

		$group = [
			'glob' => 'plugins/*',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\git_tag_version(),
				'to' => [ \WPElevator\PHP_Changesets\git_tag_version() ],
			],
		];

		try {
			\WPElevator\PHP_Changesets\define_config( [ 'packages' => [ $group ] ] );
			$this->fail( 'Git tags are source only descriptors.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'version.to[0]', $error->getMessage(), 'The diagnostic locates the git-tag target.' );
		}

		$group = [
			'glob' => 'plugins/*',
			'version' => [
				'from' => [ 'kind' => 'unknown-descriptor' ],
				'to' => [],
			],
		];

		try {
			\WPElevator\PHP_Changesets\define_config( [ 'packages' => [ $group ] ] );
			$this->fail( 'Unknown descriptor kinds must be rejected.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'version.from.kind', $error->getMessage(), 'The diagnostic locates the descriptor kind.' );
		}
	}

	public function test_define_validates_descriptor_fields(): void {
		$this->assert_descriptor_error(
			[ 'kind' => 'composer-json' ],
			'file',
			'A Composer descriptor requires a file path.'
		);
		$this->assert_descriptor_error(
			[
				'kind' => 'php-constant',
				'file' => 'src/version.php',
			],
			'constant',
			'A PHP constant descriptor requires a constant name.'
		);
		$this->assert_descriptor_error(
			[
				'kind' => 'callback',
				'file' => 'release.txt',
				'resolve' => 'not-callable',
			],
			'resolve',
			'A callback descriptor requires a resolve callable.'
		);
		$this->assert_descriptor_error(
			[
				'kind' => 'git-tag',
				'initialVersion' => '1.02.3',
			],
			'initialVersion',
			'Initial versions must be stable X.Y.Z versions.',
			'from'
		);
		$this->assert_descriptor_error(
			[
				'kind' => 'wordpress-plugin-header',
				'ifPresent' => true,
			],
			'ifPresent',
			'Descriptors that cannot skip missing files must not carry ifPresent.'
		);
	}

	private function assert_descriptor_error( array $descriptor, string $key, string $message, string $context = 'to' ): void {
		$version = 'from' === $context
			? [
				'from' => $descriptor,
				'to' => [],
			]
			: [
				'from' => \WPElevator\PHP_Changesets\git_tag_version(),
				'to' => [ $descriptor ],
			];

		try {
			\WPElevator\PHP_Changesets\define_config(
				[
					'packages' => [
						[
							'glob' => 'plugins/*',
							'version' => $version,
						],
					],
				]
			);
			$this->fail( $message );
		} catch ( Release_Exception $error ) {
			$locator = 'from' === $context ? 'version.from.' : 'version.to[0].';
			$this->assertStringContainsString( $locator . $key, $error->getMessage(), $message );
		}
	}

	public function test_define_rejects_broken_tag_formats(): void {
		foreach ( [ 'v{version}-{version}', 'v1.0.0', '{name}-{path}' ] as $tag_format ) {
			try {
				\WPElevator\PHP_Changesets\define_config(
					[
						'tagFormat' => $tag_format,
						'packages' => [
							[
								'glob' => 'plugins/*',
								'tagFormat' => $tag_format,
								'version' => [
									'from' => \WPElevator\PHP_Changesets\git_tag_version(),
									'to' => [],
								],
							],
						],
					]
				);
				$this->fail( "Tag formats must contain {version} exactly once: $tag_format." );
			} catch ( Release_Exception $error ) {
				$this->assertStringContainsString( 'tagFormat', $error->getMessage(), 'The diagnostic names the tagFormat setting.' );
			}
		}
	}

	public function test_define_rejects_broken_hook_or_path_settings(): void {
		try {
			\WPElevator\PHP_Changesets\define_config(
				[
					'nextVersion' => '1.0.0',
					'packages' => [
						[
							'glob' => 'plugins/*',
							'version' => [
								'from' => \WPElevator\PHP_Changesets\git_tag_version(),
								'to' => [],
							],
						],
					],
				]
			);
			$this->fail( 'Next-version hooks must be callable.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'nextVersion', $error->getMessage(), 'The diagnostic names the hook setting.' );
		}

		try {
			\WPElevator\PHP_Changesets\define_config(
				[
					'changelogEntry' => true,
					'packages' => [
						[
							'glob' => 'plugins/*',
							'version' => [
								'from' => \WPElevator\PHP_Changesets\git_tag_version(),
								'to' => [],
							],
						],
					],
				]
			);
			$this->fail( 'Changelog-entry hooks must be callable.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'changelogEntry', $error->getMessage(), 'The diagnostic names the changelog-entry hook setting.' );
		}

		try {
			\WPElevator\PHP_Changesets\define_config(
				[
					'changesets' => '/etc/changesets',
					'packages' => [
						[
							'glob' => 'plugins/*',
							'version' => [
								'from' => \WPElevator\PHP_Changesets\git_tag_version(),
								'to' => [],
							],
						],
					],
				]
			);
			$this->fail( 'Changeset paths must stay relative to the project root.' );
		} catch ( Release_Exception $error ) {
			$this->assertStringContainsString( 'changesets', $error->getMessage(), 'The diagnostic names the changeset path.' );
		}
	}
}
