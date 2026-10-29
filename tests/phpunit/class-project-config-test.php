<?php

namespace WPElevator\PHP_Changesets_Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WPElevator\PHP_Changesets\Project_Config;
use WPElevator\PHP_Changesets\Project_Files;
use WPElevator\PHP_Changesets\Release_Exception;

require_once __DIR__ . '/../../php/bootstrap.php';

final class Project_Config_Test extends TestCase {

	private string $root;

	private Project_Config $loader;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/php-changesets-config-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->root );
		$this->loader = new Project_Config( new Project_Files( $this->root ) );
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

	public function test_loads_the_primary_config_file(): void {
		$this->write_config(
			'changesets.config.php',
			<<<'PHP'
<?php

return \WPElevator\PHP_Changesets\define_config( [
	'packages' => [
		[
			'glob' => 'plugins/*',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\wordpress_plugin_version(),
				'to' => [ \WPElevator\PHP_Changesets\wordpress_plugin_version() ],
			],
		],
	],
] );
PHP
		);

		$config = $this->loader->load();

		$this->assertTrue( $this->loader->has_config(), 'A recognized config file is detected.' );
		$this->assertSame( 'plugins/*', $config['packages'][0]['glob'], 'The config returns the canonical package group.' );
		$this->assertSame( '.changeset', $config['changesets'], 'The loader returns defaults for omitted settings.' );
	}

	public function test_plain_array_configs_are_validated_too(): void {
		$this->write_config( 'changesets.config.php', '<?php return [ "packages" => [ [ "glob" => ".", "version" => [ "from" => [ "kind" => "git-tag" ], "to" => [] ] ] ] ];' );

		$config = $this->loader->load();

		$this->assertSame( '.', $config['packages'][0]['glob'], 'A plain array config returns the canonical package group.' );
		$this->assertSame( '{path}/{version}', $config['tagFormat'], 'The loader validates and fills defaults outside define_config as well.' );
	}

	/**
	 * @dataProvider unsupported_config_paths
	 */
	public function test_other_config_paths_are_rejected( string $path ): void {
		if ( ! is_dir( dirname( $this->root . '/' . $path ) ) ) {
			mkdir( dirname( $this->root . '/' . $path ) );
		}

		$this->write_config( $path, '<?php return [];' );

		$this->expectException( Release_Exception::class );
		$this->expectExceptionMessage( 'move the configuration to changesets.config.php' );

		$this->loader->load();
	}

	public static function unsupported_config_paths(): array {
		return [
			[ 'config/changesets.php' ],
			[ '.changeset/config.php' ],
			[ '.php-changesets.json' ],
		];
	}

	public function test_removed_configs_are_rejected_with_a_pointer(): void {
		$this->write_config( '.php-changesets.json', '{}' );

		$this->expectException( Release_Exception::class );
		$this->expectExceptionMessage( 'changesets.config.php' );

		$this->loader->load();
	}

	public function test_the_upstream_changeset_json_config_is_rejected(): void {
		mkdir( $this->root . '/.changeset' );
		$this->write_config( '.changeset/config.json', '{}' );

		$this->expectException( Release_Exception::class );
		$this->expectExceptionMessage( '.changeset/config.json is not supported' );

		$this->loader->load();
	}

	public function test_configs_must_return_an_array(): void {
		$this->write_config( 'changesets.config.php', '<?php return null;' );

		$this->expectException( Release_Exception::class );
		$this->expectExceptionMessage( 'must return an array' );

		$this->loader->load();
	}

	public function test_loader_double_checks_the_returned_shape(): void {
		$this->write_config( 'changesets.config.php', '<?php return [];' );

		$this->expectException( Release_Exception::class );
		$this->expectExceptionMessage( 'packages' );

		$this->loader->load();
	}

	public function test_symlinked_configs_are_rejected(): void {
		$target = $this->root . '/elsewhere.php';
		file_put_contents( $target, '<?php return [];' );
		symlink( $target, $this->root . '/changesets.config.php' );

		$this->expectException( Release_Exception::class );
		$this->expectExceptionMessage( 'regular file' );

		$this->loader->load();
	}

	public function test_inference_covers_a_composer_project(): void {
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/library\",\"version\":\"1.2.3\"}\n" );

		$config = $this->loader->load();

		$this->assertFalse( $this->loader->has_config(), 'Inference runs without a config file.' );
		$this->assertSame( '.', $config['packages'][0]['glob'], 'Inferred packages cover the repository root.' );
		$this->assertSame( 'composer.json#name', $config['packages'][0]['idFrom'], 'Inferred packages name themselves from the Composer manifest.' );
		$this->assertSame( 'composer-json', $config['packages'][0]['version']['from']['kind'], 'Inferred sources read the manifest version.' );
		$this->assertSame(
			[
				'kind' => 'composer-json',
				'file' => 'composer.json',
				'ifPresent' => true,
			],
			$config['packages'][0]['version']['to'][0],
			'Inferred targets update the manifest through the builder descriptor.'
		);
		$this->assertSame( 'v{version}', $config['packages'][0]['tagFormat'], 'An inferred root package tags v{version} like the wp-release single-project default.' );
	}

	public function test_inference_covers_a_plugin_project(): void {
		file_put_contents( $this->root . '/plugin.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n" );
		file_put_contents( $this->root . '/readme.txt', "Stable tag: 1.2.3\n" );

		$config = $this->loader->load();

		$this->assertSame( 'plugin', $config['packages'][0]['id'], 'Plugin projects without a Composer name use the main plugin file slug, not the checkout directory.' );
		$this->assertFalse( $config['readmeChangelog'], 'A readme without a changelog section gets no readme changelog.' );
		$this->assertSame( 'wordpress-plugin-header', $config['packages'][0]['version']['from']['kind'], 'Inferred sources read the plugin header.' );
		$this->assertSame(
			'wordpress-stable-tag',
			$config['packages'][0]['version']['to'][1]['kind'],
			'Inferred targets add the readme stable tag near a readme.'
		);
		$this->assertTrue( $config['packages'][0]['version']['to'][1]['ifPresent'], 'The inferred stable tag is skipped when the readme misses a tag.' );
	}

	public function test_inference_adds_to_a_readme_changelog_section(): void {
		file_put_contents( $this->root . '/example.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n" );
		file_put_contents( $this->root . '/readme.txt', "Stable tag: 1.2.3\n\n== Changelog ==\n" );

		$this->assertTrue( $this->loader->load()['readmeChangelog'], 'A readme with a changelog section gets the release sections.' );
	}

	public function test_inference_needs_a_name_for_a_composer_version(): void {
		file_put_contents( $this->root . '/composer.json', "{\"version\":\"1.2.3\"}\n" );

		$this->expectException( Release_Exception::class );
		$this->expectExceptionMessage( 'Add a name to composer.json' );

		$this->loader->load();
	}

	public function test_inference_picks_a_tag_only_source_without_manifest_version(): void {
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/library\"}\n" );

		$config = $this->loader->load();

		$this->assertSame( 'git-tag', $config['packages'][0]['version']['from']['kind'], 'Manifests without versions release by tag.' );
		$this->assertSame( [], $config['packages'][0]['version']['to'], 'Tag-only projects write no version files.' );
	}

	public function test_inference_adds_a_packages_group_for_a_uniform_monorepo(): void {
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/root\",\"version\":\"1.0.0\"}\n" );
		mkdir( $this->root . '/packages/alpha', 0777, true );
		mkdir( $this->root . '/packages/beta', 0777, true );
		file_put_contents( $this->root . '/packages/alpha/composer.json', "{\"name\":\"example/alpha\",\"version\":\"0.2.7\"}\n" );
		file_put_contents( $this->root . '/packages/beta/composer.json', "{\"name\":\"example/beta\",\"version\":\"1.0.0\"}\n" );

		$config = $this->loader->load();

		$this->assertCount( 2, $config['packages'], 'A uniform packages layout gains a second inferred group.' );
		$this->assertSame( 'packages/*', $config['packages'][1]['glob'], 'The inferred monorepo group covers packages subdirectories.' );
		$this->assertSame( 'composer.json#name', $config['packages'][1]['idFrom'], 'The inferred monorepo group names packages from their manifests.' );
		$this->assertSame( 'composer-json', $config['packages'][1]['version']['from']['kind'], 'The inferred monorepo group reads package manifest versions.' );
		$this->assertNull( $config['packages'][1]['tagFormat'], 'The inferred monorepo group inherits the {path}/{version} default.' );
	}

	public function test_inference_keeps_one_group_when_the_packages_layout_is_ambiguous(): void {
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/root\",\"version\":\"1.0.0\"}\n" );
		mkdir( $this->root . '/packages/alpha', 0777, true );
		mkdir( $this->root . '/packages/beta', 0777, true );
		file_put_contents( $this->root . '/packages/alpha/composer.json', "{\"name\":\"example/alpha\",\"version\":\"0.2.7\"}\n" );
		file_put_contents( $this->root . '/packages/beta/readme.txt', 'No manifest here.' );

		$config = $this->loader->load();

		$this->assertCount( 1, $config['packages'], 'Ambiguous packages layouts keep the single root group.' );
		$this->assertSame( '.', $config['packages'][0]['glob'], 'The remaining group covers the repository root.' );
	}

	public function test_inference_keeps_one_group_when_a_package_name_is_duplicated(): void {
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/root\",\"version\":\"1.0.0\"}\n" );
		mkdir( $this->root . '/packages/alpha', 0777, true );
		file_put_contents( $this->root . '/packages/alpha/composer.json', "{\"name\":\"example/root\",\"version\":\"0.2.7\"}\n" );

		$config = $this->loader->load();

		$this->assertCount( 1, $config['packages'], 'A package identifier duplicating the root stays out of inference.' );
	}

	public function test_inference_rejects_multiple_version_sources(): void {
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/library\",\"version\":\"1.2.3\"}\n" );
		file_put_contents( $this->root . '/plugin.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n" );

		$this->expectException( Release_Exception::class );
		$this->expectExceptionMessage( 'Multiple version sources' );

		$this->loader->load();
	}

	public function test_inference_rejects_multiple_plugin_headers(): void {
		file_put_contents( $this->root . '/a.php', "<?php\n/*\nPlugin Name: A\nVersion: 1.2.3\n*/\n" );
		file_put_contents( $this->root . '/b.php', "<?php\n/*\nPlugin Name: B\nVersion: 1.2.3\n*/\n" );

		$this->expectException( Release_Exception::class );
		$this->expectExceptionMessage( 'Multiple plugin headers' );

		$this->loader->load();
	}

	public function test_inference_without_a_version_source_fails(): void {
		$this->expectException( Release_Exception::class );
		$this->expectExceptionMessage( 'No version source found' );

		$this->loader->load();
	}

	private function write_config( string $path, string $contents ): void {
		file_put_contents( $this->root . '/' . $path, $contents );
	}
}
