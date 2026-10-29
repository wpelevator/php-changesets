<?php

namespace WPElevator\PHP_Changesets_Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class Integration_Test extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/php-changesets-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->root );
		$this->run_command( [ 'git', 'init', '-q', $this->root ] );
		$this->run_command( [ 'git', '-C', $this->root, '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '--allow-empty', '-qm', 'Initial' ] );
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

	public function test_tag_only_library_release_without_node(): void {
		$manifest = "{\n  \"name\": \"example/library\"\n}\n";
		file_put_contents( $this->root . '/composer.json', $manifest );
		$this->git_tag( 'v1.2.3' );
		$this->assert_cli_success( [ 'init' ] );
		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix a defect.', '--name', 'fix' ] );

		$status = json_decode( $this->assert_cli_success( [ 'status', '--json' ] ), true );

		$this->assertSame(
			[ 'example/library', '1.2.3', 'patch', '1.2.4', 'v1.2.4' ],
			[
				$status['releases'][0]['package'],
				$status['releases'][0]['currentVersion'],
				$status['releases'][0]['bump'],
				$status['releases'][0]['nextVersion'],
				$status['releases'][0]['tag'],
			],
			'Status identifies the package, reads the latest stable Git tag, and proposes the increment and tag.'
		);

		$preview = json_decode( $this->assert_cli_success( [ 'version', '--dry-run', '--json', '--date', '2026-01-02' ] ), true );

		$this->assertStringContainsString( 'Fix a defect.', $preview['diff'], 'The dry run previews release notes.' );
		$this->assertFileDoesNotExist( $this->root . '/CHANGELOG.md', 'The dry run does not write the changelog.' );
		$this->assertFileExists( $this->root . '/.changeset/fix.md', 'The dry run preserves the changeset.' );

		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertSame( $manifest, file_get_contents( $this->root . '/composer.json' ), 'Tag-only releases leave the manifest unchanged.' );

		$changelog = file_get_contents( $this->root . '/CHANGELOG.md' );

		$this->assertStringContainsString( '## 1.2.4 (2026-01-02)', $changelog, 'The release writes the expected changelog section.' );
		$this->assertFileDoesNotExist( $this->root . '/.changeset/fix.md', 'The release consumes the changeset.' );

		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertSame( $changelog, file_get_contents( $this->root . '/CHANGELOG.md' ), 'A second run leaves the release unchanged.' );
	}

	public function test_composer_install_exposes_vendor_bin(): void {
		$package = realpath( dirname( __DIR__, 2 ) );
		$manifest = [
			'name' => 'example/consumer',
			'minimum-stability' => 'dev',
			'repositories' => [
				[
					'type' => 'path',
					'url' => $package,
					'options' => [ 'symlink' => true ],
				],
			],
			'require-dev' => [ 'wpelevator/php-changesets' => '*' ],
		];
		file_put_contents( $this->root . '/composer.json', json_encode( $manifest ) );

		$installed = $this->run_command( [ 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress' ], null, $this->root );

		$this->assertSame( 0, $installed[0], 'A consumer should install the local Composer package: ' . $installed[2] );

		$bin = $this->root . '/vendor/bin/changesets';

		$this->assertFileExists( $bin, 'Composer should expose the package executable in vendor/bin.' );

		$help = $this->run_command( [ PHP_BINARY, $bin, '--help' ] );

		$this->assertSame( 0, $help[0], 'The Composer proxy executable should run successfully: ' . $help[2] );
		$this->assertStringContainsString( 'changesets <command>', $help[1], 'The installed CLI should display its command help.' );

		$probe = 'require ' . var_export( $this->root . '/vendor/autoload.php', true )
			. '; echo class_exists(' . var_export( 'WPElevator\\PHP_Changesets\\Release_Engine', true ) . ') && function_exists(' . var_export( 'WPElevator\\PHP_Changesets\\define_config', true ) . ') ? "loaded" : "missing";';
		$autoload = $this->run_command( [ PHP_BINARY, '-r', $probe ] );

		$this->assertSame( 0, $autoload[0], 'Composer classmap autoloading should run without errors: ' . $autoload[2] );
		$this->assertSame( 'loaded', $autoload[1], 'Composer should autoload the package classes and the config helper functions.' );
	}

	public function test_add_rejects_reserved_readme_name(): void {
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/library\",\"version\":\"1.2.3\"}\n" );

		$error = $this->assert_cli_failure( [ 'add', '--bump', 'patch', '--summary', 'Fix.', '--name', 'README' ] );

		$this->assertStringContainsString( 'cannot be README', $error, 'The CLI should reject a filename that the reader would ignore.' );
		$this->assertFileDoesNotExist( $this->root . '/.changeset/README.md', 'The reserved filename must not be written.' );
	}

	public function test_git_tag_source_can_bootstrap_from_explicit_initial_version(): void {
		$manifest = "{\n  \"name\": \"example/library\"\n}\n";
		file_put_contents( $this->root . '/composer.json', $manifest );

		file_put_contents(
			$this->root . '/changesets.config.php',
			<<<'PHP'
<?php

return \WPElevator\PHP_Changesets\define_config( [
	'packages' => [
		[
			'glob' => '.',
			'idFrom' => 'composer.json#name',
			'version' => [
				'from' => [ 'kind' => 'git-tag', 'pattern' => 'v{version}', 'initialVersion' => '0.1.0' ],
				'to' => [],
			],
		],
	],
] );
PHP
		);
		$this->assert_cli_success( [ 'add', '--bump', 'minor', '--summary', 'First feature.', '--name', 'first' ] );

		$status = json_decode( $this->assert_cli_success( [ 'status', '--json' ] ), true );

		$this->assertSame(
			[ '0.1.0', '0.2.0', '0.2.0' ],
			[
				$status['releases'][0]['currentVersion'],
				$status['releases'][0]['nextVersion'],
				$status['releases'][0]['tag'],
			],
			'The configured initial version bootstraps the first release.'
		);

		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertSame( $manifest, file_get_contents( $this->root . '/composer.json' ), 'A bootstrap release does not change the tag-only manifest.' );
		$this->assertStringContainsString(
			'## 0.2.0 (2026-01-02)',
			file_get_contents( $this->root . '/CHANGELOG.md' ),
			'The first release writes its changelog section.'
		);
		$this->assertFileDoesNotExist( $this->root . '/.changeset/first.md', 'The first release consumes the changeset.' );
	}

	public function test_php_config_resolves_and_replaces_a_custom_version_span(): void {
		$version_file = "Package release = 1.2.3\nOther version = 9.9.9\n";
		file_put_contents( $this->root . '/release.txt', $version_file );
		mkdir( $this->root . '/.changeset' );
		file_put_contents(
			$this->root . '/changesets.config.php',
			<<<'PHP'
<?php
$resolve = static function ( string $contents ): ?array {
	if ( ! preg_match( '/^Package release ?[=:] (\d+\.\d+\.\d+)$/m', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}

	return [
		'value' => $matches[1][0],
		'offset' => $matches[1][1],
		'length' => strlen( $matches[1][0] ),
	];
};
$version = \WPElevator\PHP_Changesets\callback_version(
	'release.txt',
	$resolve,
	static function ( string $contents, string $old, string $new ): string {
		return str_replace( "Package release = {$old}", "Package release: {$new}", $contents );
	},
);
return \WPElevator\PHP_Changesets\define_config( [
	'changelog' => '',
	'packages' => [
		[
			'glob' => '.',
			'id' => 'example',
			'version' => [ 'from' => $version, 'to' => [ $version ] ],
		],
	],
] );
PHP
		);
		$config = file_get_contents( $this->root . '/changesets.config.php' );
		$this->assert_cli_success( [ 'init' ] );

		$this->assertFileDoesNotExist( $this->root . '/.php-changesets.json', 'Init should preserve a PHP config without creating a JSON config beside it.' );
		$this->assertSame( $config, file_get_contents( $this->root . '/changesets.config.php' ), 'Init does not rewrite an existing config file.' );

		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix.', '--name', 'fix' ] );

		$status = json_decode( $this->assert_cli_success( [ 'status', '--json' ] ), true );

		$this->assertSame( [ '1.2.3', '1.2.4' ], [ $status['releases'][0]['currentVersion'], $status['releases'][0]['nextVersion'] ], 'The PHP resolver supplies the current version and supports normal planning.' );

		$preview = json_decode( $this->assert_cli_success( [ 'version', '--dry-run', '--json' ] ), true );

		$this->assertStringContainsString( '+Package release: 1.2.4', $preview['diff'], 'The dry run previews the custom replacement callback result.' );
		$this->assertSame( $version_file, file_get_contents( $this->root . '/release.txt' ), 'The dry run does not write the custom target.' );

		$this->assert_cli_success( [ 'version' ] );

		$this->assertSame(
			"Package release: 1.2.4\nOther version = 9.9.9\n",
			file_get_contents( $this->root . '/release.txt' ),
			'The release applies the custom replacement without changing unrelated text.'
		);
	}

	public function test_removed_config_paths_are_rejected(): void {
		mkdir( $this->root . '/.changeset' );
		file_put_contents( $this->root . '/.changeset/config.json', '{}' );
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/library\",\"version\":\"1.2.3\"}\n" );

		$error = $this->assert_cli_failure( [ 'status' ] );

		$this->assertStringContainsString( 'is not supported', $error, 'Removed or foreign config paths must be rejected with guidance.' );
		$this->assertStringContainsString( 'changesets.config.php', $error, 'The rejection message points at the recognized config paths.' );
	}

	public function test_explicit_plugin_configuration_updates_only_declared_targets(): void {
		$header = "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n";
		$readme = "=== Example ===\nStable tag: 1.2.3\n";
		file_put_contents( $this->root . '/plugin.php', $header );
		file_put_contents( $this->root . '/readme.txt', $readme );
		$this->plugin_config();
		$this->assert_cli_success( [ 'add', '--bump', 'minor', '--summary', 'Add a feature.', '--name', 'feature' ] );
		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertSame(
			str_replace( '1.2.3', '1.3.0', $header ),
			file_get_contents( $this->root . '/plugin.php' ),
			'The plugin header receives the new version.'
		);
		$this->assertSame(
			str_replace( '1.2.3', '1.3.0', $readme ),
			file_get_contents( $this->root . '/readme.txt' ),
			'The readme stable tag receives the new version.'
		);
	}

	public function test_header_and_constant_in_same_file_are_updated_together(): void {
		$plugin = "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\nconst EXAMPLE_VERSION = '1.2.3';\n";
		file_put_contents( $this->root . '/plugin.php', $plugin );
		file_put_contents( $this->root . '/readme.txt', "Stable tag: 1.2.3\n" );
		$this->plugin_config( true );
		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix.', '--name', 'fix' ] );
		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertSame(
			str_replace( '1.2.3', '1.2.4', $plugin ),
			file_get_contents( $this->root . '/plugin.php' ),
			'Both configured version locations in the same file are updated.'
		);
		$this->assertFileDoesNotExist( $this->root . '/.changeset/fix.md', 'A successful release consumes the changeset.' );
	}

	public function test_later_mismatched_target_fails_before_any_file_changes(): void {
		$plugin = "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n";
		$readme = "Stable tag: 1.2.2\n";
		$changelog = "# Changelog\n\nExisting notes.\n";
		file_put_contents( $this->root . '/plugin.php', $plugin );
		file_put_contents( $this->root . '/readme.txt', $readme );
		file_put_contents( $this->root . '/CHANGELOG.md', $changelog );
		$this->plugin_config();

		$status_error = $this->assert_cli_failure( [ 'status', '--json' ] );

		$this->assertStringContainsString( 'does not match', $status_error, 'Status should detect mismatched declared version targets even without changesets.' );

		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix.', '--name', 'fix' ] );

		$changeset = file_get_contents( $this->root . '/.changeset/fix.md' );
		$config = file_get_contents( $this->root . '/changesets.config.php' );
		$error = $this->assert_cli_failure( [ 'version', '--date', '2026-01-02' ] );

		$this->assertStringContainsString( 'does not match', $error, 'The error identifies the version mismatch.' );
		$this->assertSame( $plugin, file_get_contents( $this->root . '/plugin.php' ), 'The earlier valid target remains unchanged.' );
		$this->assertSame( $readme, file_get_contents( $this->root . '/readme.txt' ), 'The mismatched target remains unchanged.' );
		$this->assertSame( $changelog, file_get_contents( $this->root . '/CHANGELOG.md' ), 'The changelog remains unchanged when preflight fails.' );
		$this->assertSame( $changeset, file_get_contents( $this->root . '/.changeset/fix.md' ), 'The changeset remains intact when preflight fails.' );
		$this->assertSame( $config, file_get_contents( $this->root . '/changesets.config.php' ), 'The project configuration remains unchanged.' );
	}

	public function test_invalid_changeset_and_ambiguous_target_fail_without_writes(): void {
		file_put_contents( $this->root . '/plugin.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n" );
		file_put_contents( $this->root . '/readme.txt', "Stable tag: 1.2.3\nStable tag: 1.2.3\n" );
		$this->plugin_config();
		mkdir( $this->root . '/.changeset' );

		$file = $this->root . '/.changeset/bad.md';
		file_put_contents( $file, "---\nunknown/package: patch\n---\n\nBad input.\n" );
		$this->assert_cli_failure( [ 'version' ] );

		$this->assertFileExists( $file, 'Invalid changesets are not consumed.' );

		file_put_contents( $file, "---\nexample/plugin: patch\n---\n\nValid input.\n" );
		$this->assert_cli_failure( [ 'version' ] );

		$this->assertSame( "Stable tag: 1.2.3\nStable tag: 1.2.3\n", file_get_contents( $this->root . '/readme.txt' ), 'Ambiguous targets remain unchanged.' );
		$this->assertFileExists( $file, 'A target error does not consume the changeset.' );
	}

	public function test_existing_proposed_tag_is_rejected_before_writing(): void {
		file_put_contents( $this->root . '/plugin.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n" );
		file_put_contents( $this->root . '/readme.txt', "Stable tag: 1.2.3\n" );
		$this->plugin_config();
		$this->git_tag( 'example/plugin-v1.2.4' );
		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix.', '--name', 'fix' ] );

		$error = $this->assert_cli_failure( [ 'version' ] );

		$this->assertStringContainsString( 'tag already exists', $error, 'The diagnostic identifies the conflicting tag.' );
		$this->assertFileExists( $this->root . '/.changeset/fix.md', 'A conflicting tag does not consume the changeset.' );
		$this->assertFileDoesNotExist( $this->root . '/CHANGELOG.md', 'A conflicting tag does not write a changelog.' );
	}

	public function test_changelog_sections_attach_to_the_front_of_the_document(): void {
		file_put_contents( $this->root . '/composer.json', "{\n  \"name\": \"example/library\"\n}\n" );
		file_put_contents( $this->root . '/CHANGELOG.md', "## 0.1.1 (2026-01-01)\n\n- Existing notes.\n" );
		$this->git_tag( 'v1.2.3' );
		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix a defect.', '--name', 'fix' ] );
		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertSame(
			"## 1.2.4 (2026-01-02)\n\n- Fix a defect.\n\n## 0.1.1 (2026-01-01)\n\n- Existing notes.\n",
			file_get_contents( $this->root . '/CHANGELOG.md' ),
			'A changelog without a leading title receives its new section at the very top.'
		);

		$this->setUp();
		file_put_contents( $this->root . '/CHANGELOG.md', "- Leading note beyond any heading.\n" );
		file_put_contents( $this->root . '/composer.json', "{\n  \"name\": \"example/library\"\n}\n" );
		$this->git_tag( 'v1.2.3' );
		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix a defect.', '--name', 'fix' ] );
		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertStringStartsWith(
			'## 1.2.4 (2026-01-02)',
			file_get_contents( $this->root . '/CHANGELOG.md' ),
			'A changelog whose first line is not a heading receives its new section first.'
		);

		$this->setUp();
		file_put_contents( $this->root . '/composer.json', "{\n  \"name\": \"example/library\"\n}\n" );
		$this->git_tag( 'v1.2.3' );
		file_put_contents( $this->root . '/CHANGELOG.md', "# Changelog\n\n## 0.1.1 (2026-01-01)\n\n- Existing notes.\n" );
		$this->assert_cli_success( [ 'init' ] );
		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix a defect.', '--name', 'fix' ] );
		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertSame(
			"# Changelog\n\n## 1.2.4 (2026-01-02)\n\n- Fix a defect.\n\n## 0.1.1 (2026-01-01)\n\n- Existing notes.\n",
			file_get_contents( $this->root . '/CHANGELOG.md' ),
			'A changelog with a leading title inserts its new section below the title.'
		);
	}

	public function test_subdirectory_package_release_updates_package_files(): void {
		mkdir( $this->root . '/plugins/example', 0777, true );
		$header = "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n";
		file_put_contents( $this->root . '/plugins/example/example.php', $header );
		file_put_contents(
			$this->root . '/changesets.config.php',
			<<<'PHP'
<?php

return \WPElevator\PHP_Changesets\define_config( [
	'tagFormat' => '{path}-v{version}',
	'packages' => [
		[
			'glob' => 'plugins/*',
			'idFrom' => 'path',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\wordpress_plugin_version( 'example.php' ),
				'to' => [ \WPElevator\PHP_Changesets\wordpress_plugin_version( 'example.php' ) ],
			],
		],
	],
] );
PHP
		);
		$this->assert_cli_success( [ 'add', '--bump', 'minor', '--summary', 'Add a feature.', '--name', 'feature' ] );

		$status = json_decode( $this->assert_cli_success( [ 'status', '--json' ] ), true );

		$this->assertSame(
			[ 'plugins/example', '1.2.3', 'plugins/example-v1.3.0' ],
			[
				$status['releases'][0]['package'],
				$status['releases'][0]['currentVersion'],
				$status['releases'][0]['tag'],
			],
			'Discovered path identifiers and the {path} tag placeholder name subdirectory packages.'
		);

		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertSame(
			str_replace( '1.2.3', '1.3.0', $header ),
			file_get_contents( $this->root . '/plugins/example/example.php' ),
			'The package plugin header receives the new version.'
		);
		$this->assertFileDoesNotExist( $this->root . '/CHANGELOG.md', 'The repo root changelog stays untouched for package-bound changelogs.' );
		$this->assertStringContainsString(
			'## 1.3.0 (2026-01-02)',
			(string) file_get_contents( $this->root . '/plugins/example/CHANGELOG.md' ),
			'The changelog writes to the package directory.'
		);
		$this->assertFileDoesNotExist( $this->root . '/.changeset/feature.md', 'A successful release consumes the changeset.' );
	}

	public function test_multi_group_configs_plan_one_release_per_package(): void {
		mkdir( $this->root . '/a' );
		mkdir( $this->root . '/b' );
		file_put_contents( $this->root . '/a/plugin.php', "<?php\n/*\nPlugin Name: A\nVersion: 1.2.3\n*/\n" );
		file_put_contents( $this->root . '/b/plugin.php', "<?php\n/*\nPlugin Name: B\nVersion: 2.2.3\n*/\n" );
		file_put_contents(
			$this->root . '/changesets.config.php',
			<<<'PHP'
<?php

return \WPElevator\PHP_Changesets\define_config( [
	'packages' => [
		[
			'glob' => 'a',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ),
				'to' => [ \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ) ],
			],
		],
		[
			'glob' => 'b',
			'changelog' => '',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ),
				'to' => [ \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ) ],
			],
		],
	],
] );
PHP
		);
		$this->assert_cli_success( [ 'add', '--package', 'a', '--bump', 'minor', '--summary', 'A feature.', '--name', 'feature' ] );
		$this->assert_cli_success( [ 'add', '--package', 'b', '--bump', 'patch', '--summary', 'A fix.', '--name', 'fix' ] );

		$status = json_decode( $this->assert_cli_success( [ 'status', '--json' ] ), true );

		$this->assertSame( [ 'a', 'b' ], array_column( $status['releases'], 'package' ), 'Releases stay in configured group order.' );
		$this->assertSame( [ 'minor', 'patch' ], array_column( $status['releases'], 'bump' ), 'Each package carries its own highest bump.' );
		$this->assertSame( [ '1.3.0', '2.2.4' ], array_column( $status['releases'], 'nextVersion' ), 'Each package carries its own next version.' );
		$this->assertSame( [ 'a/1.3.0', 'b/2.2.4' ], array_column( $status['releases'], 'tag' ), 'Each package carries its own tag with the {path}/{version} default.' );

		$preview = json_decode( $this->assert_cli_success( [ 'version', '--dry-run', '--json', '--date', '2026-01-02' ] ), true );

		$this->assertStringContainsString( '### a', $preview['diff'], 'The preview diff organizes the planned edits per release.' );
		$this->assertStringContainsString( 'a/plugin.php', $preview['diff'], 'The preview diff shows the package file labels.' );

		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertSame(
			str_replace( '1.2.3', '1.3.0', "<?php\n/*\nPlugin Name: A\nVersion: 1.2.3\n*/\n" ),
			file_get_contents( $this->root . '/a/plugin.php' ),
			'The first package releases its own version.'
		);
		$this->assertSame(
			str_replace( '2.2.3', '2.2.4', "<?php\n/*\nPlugin Name: B\nVersion: 2.2.3\n*/\n" ),
			file_get_contents( $this->root . '/b/plugin.php' ),
			'The second package releases its own version.'
		);
		$this->assertStringContainsString( "## 1.3.0 (2026-01-02)\n\n- A feature.", (string) file_get_contents( $this->root . '/a/CHANGELOG.md' ), 'The first package writes its changelog.' );
		$this->assertFileDoesNotExist( $this->root . '/b/CHANGELOG.md', 'An empty group changelog disables changelog generation for that package.' );
		$this->assertFileDoesNotExist( $this->root . '/.changeset/feature.md', 'The release consumes the changesets of every package.' );
		$this->assertFileDoesNotExist( $this->root . '/.changeset/fix.md', 'The release consumes the changesets of every package.' );
	}

	public function test_add_requires_package_selection_for_multi_package_configs(): void {
		mkdir( $this->root . '/a' );
		mkdir( $this->root . '/b' );
		file_put_contents( $this->root . '/a/plugin.php', "<?php\n/*\nPlugin Name: A\nVersion: 1.2.3\n*/\n" );
		file_put_contents( $this->root . '/b/plugin.php', "<?php\n/*\nPlugin Name: B\nVersion: 2.2.3\n*/\n" );
		file_put_contents(
			$this->root . '/changesets.config.php',
			<<<'PHP'
<?php

return \WPElevator\PHP_Changesets\define_config( [
	'packages' => [
		[
			'glob' => 'a',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ),
				'to' => [ \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ) ],
			],
		],
		[
			'glob' => 'b',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ),
				'to' => [ \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ) ],
			],
		],
	],
] );
PHP
		);

		$error = $this->assert_cli_failure( [ 'add', '--bump', 'patch', '--summary', 'Fix.' ] );

		$this->assertStringContainsString( '--package', $error, 'Multi-package configurations require an explicit package selection.' );

		$error = $this->assert_cli_failure( [ 'add', '--package', 'unknown', '--bump', 'patch', '--summary', 'Fix.' ] );

		$this->assertStringContainsString( 'Unknown package', $error, 'Add rejects unknown package names.' );
	}


	public function test_init_scaffold_shows_how_to_extend_single_group_configs(): void {
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/library\",\"version\":\"1.2.3\"}\n" );
		$this->git_tag( 'v0.1.0' );
		$this->assert_cli_success( [ 'init' ] );

		$scaffold = (string) file_get_contents( $this->root . '/changesets.config.php' );

		$this->assertStringContainsString( "'glob' => '.'", $scaffold, 'The scaffold covers the inferred root package.' );
		$this->assertStringContainsString( 'Add further package groups by repeating this template:', $scaffold, 'A single-group scaffold explains how to add groups.' );
		$this->assertStringContainsString( "// \t'glob' => 'packages/*'", $scaffold, 'The extension template shows the packages glob.' );
		$this->assertStringContainsString( 'use function WPElevator\\PHP_Changesets\\define_config;', $scaffold, 'The scaffold imports the config helper functions.' );
		$this->assertStringContainsString( "'from' => composer_json_version()", $scaffold, 'The scaffold calls the imported descriptor builders.' );
		$this->assertStringContainsString( "'tagFormat' => 'v{version}'", $scaffold, 'The inferred root package keeps the v{version} tags.' );

		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix a defect.', '--name', 'fix' ] );

		$status = json_decode( $this->assert_cli_success( [ 'status', '--json' ] ), true );

		$this->assertCount( 1, $status['releases'], 'The commented template does not change the planned packages.' );
	}

	public function test_init_inferences_two_groups_for_a_uniform_monorepo(): void {
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/root\",\"version\":\"1.0.0\"}\n" );
		mkdir( $this->root . '/packages/alpha', 0777, true );
		mkdir( $this->root . '/packages/beta', 0777, true );
		file_put_contents( $this->root . '/packages/alpha/composer.json', "{\"name\":\"example/alpha\",\"version\":\"0.2.7\"}\n" );
		file_put_contents( $this->root . '/packages/beta/composer.json', "{\"name\":\"example/beta\",\"version\":\"1.0.0\"}\n" );
		$this->git_tag( 'v0.1.0' );
		$this->assert_cli_success( [ 'init' ] );

		$scaffold = (string) file_get_contents( $this->root . '/changesets.config.php' );

		$this->assertStringContainsString( "'glob' => 'packages/*'", $scaffold, 'The scaffold carries the inferred packages group.' );
		$this->assertStringNotContainsString( 'Add further package groups by repeating this template:', $scaffold, 'A multi-group scaffold needs no extension template.' );

		$this->assert_cli_success( [ 'add', '--package', 'example/alpha', '--bump', 'minor', '--summary', 'Add a feature.', '--name', 'feature' ] );

		$status = json_decode( $this->assert_cli_success( [ 'status', '--json' ] ), true );

		$this->assertSame(
			[ 'example/root', 'example/alpha', 'example/beta' ],
			array_column( $status['releases'], 'package' ),
			'The scaffolded monorepo config discovers the root and the packages in group order.'
		);
	}

	public function test_empty_changeset_applies_to_every_package(): void {
		$this->two_package_config();

		$this->assert_cli_success( [ 'add', '--empty', '--name', 'no-op' ] );

		$this->assertSame(
			"---\n---\n\n\n",
			(string) file_get_contents( $this->root . '/.changeset/no-op.md' ),
			'An empty changeset records empty frontmatter without a package selection.'
		);

		$status = json_decode( $this->assert_cli_success( [ 'status', '--json' ] ), true );

		$this->assertSame(
			[
				[
					'file' => 'no-op.md',
					'packages' => [],
					'summary' => '',
				],
			],
			$status['changesets'],
			'Status reports the empty changeset as a no-op for all packages.'
		);
	}

	public function test_repeated_package_options_select_several_packages(): void {
		$this->two_package_config();
		$this->assert_cli_success( [ 'add', '--package', 'a', '--package', 'b', '--bump', 'minor', '--summary', 'Update both.', '--name', 'both' ] );

		$changeset = (string) file_get_contents( $this->root . '/.changeset/both.md' );

		$this->assertStringContainsString( "\"a\": minor\n\"b\": minor", $changeset, 'The frontmatter lists both selected package names.' );
	}

	public function test_changelog_entry_hook_formats_each_changeset_line(): void {
		file_put_contents( $this->root . '/plugin.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n" );
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/plugin\"}\n" );
		mkdir( $this->root . '/.changeset' );
		file_put_contents(
			$this->root . '/changesets.config.php',
			<<<'PHP'
<?php
$format = static function ( array $input ): string {
	return '- [' . $input['changeset']['file'] . '] ' . $input['changeset']['summary'];
};
return \WPElevator\PHP_Changesets\define_config( [
	'changelogEntry' => $format,
	'packages' => [
		[
			'glob' => '.',
			'idFrom' => 'composer.json#name',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ),
				'to' => [ \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ) ],
			],
		],
	],
] );
PHP
		);
		file_put_contents( $this->root . '/.changeset/a-fix.md', "---\nexample/plugin: patch\n---\n\nFix a defect.\n" );
		file_put_contents( $this->root . '/.changeset/z-feature.md', "---\nexample/plugin: minor\n---\n\nAdd a feature.\n" );
		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$changelog = (string) file_get_contents( $this->root . '/CHANGELOG.md' );

		$this->assertStringContainsString( "- [a-fix.md] Fix a defect.\n- [z-feature.md] Add a feature.", $changelog, 'The changelogEntry hook formats one line per changeset in filename order.' );
	}

	public function test_changelog_entry_hook_failures_stop_the_release(): void {
		file_put_contents( $this->root . '/plugin.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n" );
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/plugin\"}\n" );
		mkdir( $this->root . '/.changeset' );
		file_put_contents(
			$this->root . '/changesets.config.php',
			<<<'PHP'
<?php
return \WPElevator\PHP_Changesets\define_config( [
	'changelogEntry' => static function ( array $input ): int {
		return 1;
	},
	'packages' => [
		[
			'glob' => '.',
			'idFrom' => 'composer.json#name',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ),
				'to' => [ \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ) ],
			],
		],
	],
] );
PHP
		);
		file_put_contents( $this->root . '/.changeset/fix.md', "---\nexample/plugin: patch\n---\n\nFix a defect.\n" );

		$error = $this->assert_cli_failure( [ 'status', '--json' ] );

		$this->assertStringContainsString( 'changelogEntry hook', $error, 'The diagnostic names the failing changelog-entry hook.' );
		$this->assertFileExists( $this->root . '/.changeset/fix.md', 'An invalid changelog entry does not consume the changeset.' );
	}

	public function test_prerelease_flag_numbers_versions_from_the_package_tags(): void {
		$manifest = "{\"name\":\"example/lib\",\"version\":\"1.2.4\"}\n";
		file_put_contents( $this->root . '/composer.json', $manifest );
		$this->git_tag( 'v1.2.4-rc.1' );
		$this->git_tag( 'v1.2.5-rc.1' );
		$this->assert_cli_success( [ 'init' ] );
		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix a defect.', '--name', 'fix' ] );

		$preview = json_decode( $this->assert_cli_success( [ 'version', '--dry-run', '--json', '--prerelease', 'rc', '--date', '2026-01-02' ] ), true );

		$this->assertSame(
			[ 'example/lib', '1.2.4', 'patch', '1.2.5-rc.2', 'v1.2.5-rc.2' ],
			[
				$preview['releases'][0]['package'],
				$preview['releases'][0]['currentVersion'],
				$preview['releases'][0]['bump'],
				$preview['releases'][0]['nextVersion'],
				$preview['releases'][0]['tag'],
			],
			'A bare prerelease identifier numbers one above the package tags for the planned stable version.'
		);
		$this->assertArrayNotHasKey( 'pre', $preview, 'Prerelease plans stay inside the ordinary release-plan shape.' );

		$this->assert_cli_success( [ 'version', '--prerelease', 'rc', '--date', '2026-01-02' ] );

		$this->assertSame( str_replace( '1.2.4', '1.2.5-rc.2', $manifest ), file_get_contents( $this->root . '/composer.json' ), 'The prerelease version lands in the version target.' );
		$this->assertStringContainsString( '## 1.2.5-rc.2 (2026-01-02)', (string) file_get_contents( $this->root . '/CHANGELOG.md' ), 'The changelog carries the prerelease section.' );
		$this->assertFileDoesNotExist( $this->root . '/.changeset/fix.md', 'A prerelease run consumes its changesets like any release.' );
	}

	public function test_explicit_prerelease_numbers_are_used_as_is_and_must_be_new(): void {
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/library\",\"version\":\"1.2.4\"}\n" );
		$this->git_tag( 'v1.2.5-rc.2' );
		$this->assert_cli_success( [ 'init' ] );
		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix a defect.', '--name', 'fix' ] );

		$error = $this->assert_cli_failure( [ 'version', '--prerelease', 'rc.2' ] );

		$this->assertStringContainsString( '1.2.5-rc.2 already exists', $error, 'An explicit prerelease number must be new for that stable version.' );
		$this->assertSame( "{\"name\":\"example/library\",\"version\":\"1.2.4\"}\n", file_get_contents( $this->root . '/composer.json' ), 'A rejected prerelease number does not write version files.' );

		$this->assert_cli_success( [ 'version', '--prerelease', 'rc.7' ] );

		$this->assertSame( "{\"name\":\"example/library\",\"version\":\"1.2.5-rc.7\"}\n", file_get_contents( $this->root . '/composer.json' ), 'An explicit prerelease number is used exactly as given.' );
	}

	public function test_prerelease_runs_keep_the_stable_tag(): void {
		$header = "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n";
		$readme = "=== Example ===\nStable tag: 1.2.3\n";
		file_put_contents( $this->root . '/plugin.php', $header );
		file_put_contents( $this->root . '/readme.txt', $readme );
		$this->plugin_config();
		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix a defect.', '--name', 'fix' ] );

		$this->assert_cli_success( [ 'version', '--prerelease', 'rc', '--date', '2026-01-02' ] );

		$this->assertSame( str_replace( 'Version: 1.2.3', 'Version: 1.2.4-rc.1', $header ), file_get_contents( $this->root . '/plugin.php' ), 'The plugin header receives the prerelease version.' );
		$this->assertSame( $readme, file_get_contents( $this->root . '/readme.txt' ), 'The readme stable tag keeps the last stable version for a pre-release.' );

		$status = json_decode( $this->assert_cli_success( [ 'status', '--json' ] ), true );

		$this->assertSame( '1.2.4-rc.1', $status['releases'][0]['currentVersion'], 'The prerelease current version is reported.' );
	}

	public function test_a_stale_readme_stable_tag_is_verified_over_prerelease_current_versions(): void {
		file_put_contents( $this->root . '/plugin.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.3.0-rc.1\n*/\n" );
		file_put_contents( $this->root . '/readme.txt', "Stable tag: 1.2.3\n" );
		$this->plugin_config();

		$status = json_decode( $this->assert_cli_success( [ 'status', '--json' ] ), true );

		$this->assertSame( '1.3.0-rc.1', $status['releases'][0]['currentVersion'], 'The readme stable tag stays unverified over a prerelease current version.' );

		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix.', '--name', 'fix' ] );
		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertStringContainsString( "Version: 1.3.0\n", (string) file_get_contents( $this->root . '/plugin.php' ), 'A patch of the prerelease releases its stable base like semver inc.' );
		$this->assertSame( "Stable tag: 1.3.0\n", file_get_contents( $this->root . '/readme.txt' ), 'A stable release updates the readme stable tag that the prerelease run kept.' );
	}

	public function test_root_config_resolves_package_paths_against_the_configured_root(): void {
		mkdir( $this->root . '/work/alpha', 0777, true );
		file_put_contents( $this->root . '/work/alpha/composer.json', "{\"name\":\"example/lib\",\"version\":\"1.2.4\"}\n" );
		mkdir( $this->root . '/.changeset' );
		file_put_contents( $this->root . '/.changeset/fix.md', "---\nexample/lib: patch\n---\n\nFix a defect.\n" );
		file_put_contents(
			$this->root . '/changesets.config.php',
			<<<'PHP'
<?php

return \WPElevator\PHP_Changesets\define_config( [
	'root' => 'work',
	'packages' => [
		[
			'glob' => '*',
			'idFrom' => 'composer.json#name',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\composer_json_version(),
				'to' => [ \WPElevator\PHP_Changesets\composer_json_version() ],
			],
		],
	],
] );
PHP
		);

		$status = json_decode( $this->assert_cli_success( [ 'status', '--json' ] ), true );

		$this->assertSame(
			[ 'example/lib', '1.2.4', 'patch', '1.2.5' ],
			[
				$status['releases'][0]['package'],
				$status['releases'][0]['currentVersion'],
				$status['releases'][0]['bump'],
				$status['releases'][0]['nextVersion'],
			],
			'Package selectors resolve against the configured root, not the repository root.'
		);

		$this->assert_cli_success( [ 'version', '--dry-run', '--date', '2026-01-02' ] );

		$this->assertSame( "{\"name\":\"example/lib\",\"version\":\"1.2.4\"}\n", (string) file_get_contents( $this->root . '/work/alpha/composer.json' ), 'The dry run does not write the package under the configured root.' );

		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertSame( "{\"name\":\"example/lib\",\"version\":\"1.2.5\"}\n", (string) file_get_contents( $this->root . '/work/alpha/composer.json' ), 'The release writes the version target of the package under the configured root.' );
		$this->assertStringContainsString( '## 1.2.5 (2026-01-02)', (string) file_get_contents( $this->root . '/work/alpha/CHANGELOG.md' ), 'The changelog writes inside the package under the configured root.' );
	}

	public function test_an_absent_optional_readme_is_skipped_by_a_stable_release_of_a_prerelease_package(): void {
		file_put_contents( $this->root . '/plugin.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.3.0-rc.1\n*/\n" );
		$this->plugin_config();
		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix.', '--name', 'fix' ] );

		$preview = json_decode( $this->assert_cli_success( [ 'version', '--dry-run', '--json', '--date', '2026-01-02' ] ), true );

		$this->assertStringContainsString( "+Version: 1.3.0\n", $preview['diff'], 'A patch of the prerelease releases its stable base like semver inc.' );
		$this->assertFileDoesNotExist( $this->root . '/readme.txt', 'The dry run neither fails on nor creates an absent optional readme.' );

		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertStringContainsString( "Version: 1.3.0\n", (string) file_get_contents( $this->root . '/plugin.php' ), 'The plugin header releases the stable version.' );
		$this->assertFileDoesNotExist( $this->root . '/readme.txt', 'A stable release skips an absent optional readme target without planning an edit.' );
	}

	public function test_prerelease_flag_applies_to_hooked_next_versions(): void {
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/lib\",\"version\":\"1.2.4\"}\n" );
		file_put_contents(
			$this->root . '/changesets.config.php',
			<<<'PHP'
<?php

return \WPElevator\PHP_Changesets\define_config( [
	'nextVersion' => static fn( array $input ): string => '2.0.0',
	'packages' => [
		[
			'glob' => '.',
			'idFrom' => 'composer.json#name',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\composer_json_version(),
				'to' => [ \WPElevator\PHP_Changesets\composer_json_version() ],
			],
		],
	],
] );
PHP
		);
		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix a defect.', '--name', 'fix' ] );

		$preview = json_decode( $this->assert_cli_success( [ 'version', '--dry-run', '--json', '--prerelease', 'rc' ] ), true );

		$this->assertSame( [ '2.0.0-rc.1', '2.0.0-rc.1' ], [ $preview['releases'][0]['nextVersion'], $preview['releases'][0]['tag'] ], 'Like wp-release, the pre-release transform applies to the stable version that the nextVersion hook returns.' );
	}

	public function test_add_pairs_bumps_with_packages_named_by_identifier_or_path(): void {
		$this->two_package_config();

		$this->assert_cli_success( [ 'add', '--package', 'a', '--bump', 'minor', '--package', './b/', '--bump', 'patch', '--summary', 'Both.', '--name', 'both' ] );

		$this->assertSame( "---\n\"a\": minor\n\"b\": patch\n---\n\nBoth.\n", file_get_contents( $this->root . '/.changeset/both.md' ), 'Each --bump pairs with the --package before it, and packages resolve by path too.' );

		$this->assert_cli_success( [ 'add', '--package', 'a', '--package', 'b', '--bump', 'major', '--summary', 'All.', '--name', 'all' ] );

		$this->assertSame( "---\n\"a\": major\n\"b\": major\n---\n\nAll.\n", file_get_contents( $this->root . '/.changeset/all.md' ), 'A single --bump applies to every selected package.' );

		$error = $this->assert_cli_failure( [ 'add', '--package', 'a', '--package', 'b', '--bump', 'minor', '--bump', 'patch', '--bump', 'major', '--summary', 'Odd.' ] );

		$this->assertStringContainsString( 'one --bump after each --package', $error, 'Unpaired bumps are rejected.' );

		$error = $this->assert_cli_failure( [ 'add', '--package', 'a', '--package', 'a', '--bump', 'patch', '--summary', 'Twice.' ] );

		$this->assertStringContainsString( 'more than once', $error, 'A package selected twice is rejected.' );
	}

	public function test_group_ids_name_a_single_matched_directory(): void {
		mkdir( $this->root . '/plugin' );
		file_put_contents( $this->root . '/plugin/plugin.php', "<?php\n/*\nPlugin Name: Example\nVersion: 2.1\n*/\n" );
		file_put_contents(
			$this->root . '/changesets.config.php',
			<<<'PHP'
<?php

use function WPElevator\PHP_Changesets\define_config;
use function WPElevator\PHP_Changesets\wordpress_plugin_version;

return define_config( [
	'packages' => [
		[
			'glob' => 'plugin',
			'id' => 'example-plugin',
			'version' => [
				'from' => wordpress_plugin_version( 'plugin.php' ),
				'to' => [ wordpress_plugin_version( 'plugin.php' ) ],
			],
		],
	],
] );
PHP
		);
		$this->assert_cli_success( [ 'add', '--bump', 'minor', '--summary', 'Add a feature.', '--name', 'feature' ] );

		$status = json_decode( $this->assert_cli_success( [ 'status', '--json' ] ), true );

		$this->assertSame(
			[ 'example-plugin', '2.1', '2.2', 'plugin/2.2' ],
			[
				$status['releases'][0]['package'],
				$status['releases'][0]['currentVersion'],
				$status['releases'][0]['nextVersion'],
				$status['releases'][0]['tag'],
			],
			'A fixed group id names the package, and a two-part WordPress version keeps its shape.'
		);

		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertStringContainsString( "Version: 2.2\n", (string) file_get_contents( $this->root . '/plugin/plugin.php' ), 'The two-part version is written back.' );
	}

	public function test_inferred_plugins_keep_their_name_in_any_checkout_directory(): void {
		file_put_contents( $this->root . '/demo.php', "<?php\n/*\nPlugin Name: Demo\nVersion: 1.0.0\n*/\n" );
		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix.', '--name', 'fix' ] );

		$this->assertSame( "---\n\"demo\": patch\n---\n\nFix.\n", file_get_contents( $this->root . '/.changeset/fix.md' ), 'The changeset names the plugin by its main file slug.' );
		$this->assertStringNotContainsString( basename( $this->root ), (string) file_get_contents( $this->root . '/.changeset/fix.md' ), 'The temporary checkout directory never names the package.' );
	}

	public function test_releases_add_to_the_readme_changelog_section(): void {
		$readme = "=== Example ===\nStable tag: 1.2.3\n\n== Changelog ==\n\n= 1.2.3 =\n\n* Initial.\n";
		file_put_contents( $this->root . '/example.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n" );
		file_put_contents( $this->root . '/readme.txt', $readme );
		$this->assert_cli_success( [ 'init' ] );

		$this->assertStringContainsString( "'readmeChangelog' => true,", (string) file_get_contents( $this->root . '/changesets.config.php' ), 'The scaffold keeps the inferred readme changelog setting.' );

		$this->assert_cli_success( [ 'add', '--bump', 'minor', '--summary', "Add a feature.\nWith details.", '--name', 'feature' ] );
		$preview = json_decode( $this->assert_cli_success( [ 'version', '--dry-run', '--json', '--date', '2026-01-02' ] ), true );

		$this->assertSame( 1, substr_count( $preview['diff'], '+++ b/readme.txt' ), 'The preview combines the stable tag and changelog edits of one file into one diff.' );

		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertSame(
			"=== Example ===\nStable tag: 1.3.0\n\n== Changelog ==\n\n= 1.3.0 =\n\n* Add a feature.\n  With details.\n\n= 1.2.3 =\n\n* Initial.\n",
			file_get_contents( $this->root . '/readme.txt' ),
			'The readme gets the stable tag and a changelog section after its heading, like wp-release readmeChangelog.'
		);
	}

	public function test_tags_lists_the_tags_that_released_versions_still_need(): void {
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/library\",\"version\":\"1.2.3\"}\n" );
		$this->git_tag( 'v1.2.3' );

		$this->assertSame( '', $this->assert_cli_success( [ 'tags' ] ), 'A tagged current version needs no tag.' );

		$this->assert_cli_success( [ 'add', '--bump', 'minor', '--summary', 'Add a feature.', '--name', 'feature' ] );
		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertSame( "v1.3.0\n", $this->assert_cli_success( [ 'tags' ] ), 'The released version prints its missing tag, one per line.' );

		$output = $this->root . '/github-output';
		$result = $this->cli( [ 'tags', '--github-output' ], [ 'GITHUB_OUTPUT' => $output ] );

		$this->assertSame( 0, $result[0], 'The tags command writes GitHub Actions outputs: ' . $result[2] );
		$this->assertSame(
			'result={"tags":[{"package":"example/library","version":"1.3.0","tag":"v1.3.0","prerelease":false,"notes":"- Add a feature."}]}' . "\n",
			file_get_contents( $output ),
			'The result output carries each tag with its changelog notes, for a step that creates the tag and GitHub release.'
		);

		$this->git_tag( 'v1.3.0' );

		$this->assertSame( '', $this->assert_cli_success( [ 'tags' ] ), 'Nothing is listed once the tag exists.' );
	}

	public function test_tags_read_tag_only_releases_from_the_changelog(): void {
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/library\"}\n" );

		$this->assertStringContainsString( 'git tag v0.1.0', $this->assert_cli_failure( [ 'status' ] ), 'A first run without tags explains how to start.' );

		$this->git_tag( 'v0.3.0' );
		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix.', '--name', 'fix' ] );
		$this->assert_cli_success( [ 'version', '--date', '2026-01-02' ] );

		$this->assertSame( "v0.3.1\n", $this->assert_cli_success( [ 'tags' ] ), 'A tag-only package releases the changelog version above its latest tag.' );
	}

	public function test_github_output_requires_github_actions(): void {
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/library\",\"version\":\"1.2.3\"}\n" );

		$this->assertStringContainsString( 'GITHUB_OUTPUT', $this->assert_cli_failure( [ 'status', '--github-output' ] ), 'The option fails outside GitHub Actions.' );

		$this->assert_cli_success( [ 'add', '--bump', 'patch', '--summary', 'Fix.', '--name', 'fix' ] );
		$output = $this->root . '/github-output';
		$result = $this->cli( [ 'version', '--dry-run', '--github-output' ], [ 'GITHUB_OUTPUT' => $output ] );

		$this->assertSame( 0, $result[0], 'The version command writes GitHub Actions outputs: ' . $result[2] );

		$lines = explode( "\n", (string) file_get_contents( $output ) );
		$plan = json_decode( substr( $lines[0], strlen( 'result=' ) ), true );

		$this->assertSame( '1.2.4', $plan['releases'][0]['nextVersion'], 'The result output is the compact release plan.' );
		$this->assertMatchesRegularExpression( '/^diff<<ghadelimiter_[0-9a-f]{32}$/', $lines[1], 'Top-level strings like the dry-run diff are their own outputs with a random delimiter.' );
	}

	private function two_package_config(): void {
		mkdir( $this->root . '/a' );
		mkdir( $this->root . '/b' );
		file_put_contents( $this->root . '/a/plugin.php', "<?php\n/*\nPlugin Name: A\nVersion: 1.2.3\n*/\n" );
		file_put_contents( $this->root . '/b/plugin.php', "<?php\n/*\nPlugin Name: B\nVersion: 2.2.3\n*/\n" );
		file_put_contents(
			$this->root . '/changesets.config.php',
			<<<'PHP'
<?php

return \WPElevator\PHP_Changesets\define_config( [
	'packages' => [
		[
			'glob' => 'a',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ),
				'to' => [ \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ) ],
			],
		],
		[
			'glob' => 'b',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ),
				'to' => [ \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ) ],
			],
		],
	],
] );
PHP
		);
	}

	private function plugin_config( bool $include_constant = false ): void {
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/plugin\"}\n" );
		$config = <<<'PHP'
<?php

return \WPElevator\PHP_Changesets\define_config( [
	'changesets' => '.changeset',
	'tagFormat' => '{name}-v{version}',
	'packages' => [
		[
			'glob' => '.',
			'idFrom' => 'composer.json#name',
			'version' => [
				'from' => \WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ),
				'to' => [
					\WPElevator\PHP_Changesets\wordpress_plugin_version( 'plugin.php' ),
					\WPElevator\PHP_Changesets\wordpress_stable_tag( 'readme.txt' ),
PHP;

		if ( $include_constant ) {
			$config .= "\t\t\t\t\t\\WPElevator\\PHP_Changesets\\php_constant_version( 'plugin.php', 'EXAMPLE_VERSION' ),\n";
		}

		$config .= <<<'PHP'
				],
			],
		],
	],
] );
PHP;

		file_put_contents( $this->root . '/changesets.config.php', $config );
	}

	private function git_tag( string $tag ): void {
		$this->run_command( [ 'git', '-C', $this->root, 'tag', $tag ] );
	}

	private function assert_cli_success( array $args ): string {
		$result = $this->cli( $args );

		$this->assertSame( 0, $result[0], 'CLI command succeeds: ' . implode( ' ', $args ) . ' ' . $result[2] );

		return $result[1];
	}

	private function assert_cli_failure( array $args ): string {
		$result = $this->cli( $args );

		$this->assertNotSame( 0, $result[0], 'CLI rejects invalid input: ' . implode( ' ', $args ) );

		return $result[2];
	}

	private function cli( array $args, array $environment = [] ): array {
		// Restrict PATH to Git alone, so Node cannot be used by the CLI.
		$bin = $this->root . '/bin';

		if ( ! is_dir( $bin ) ) {
			mkdir( $bin );

			$git = trim( (string) shell_exec( 'command -v git' ) );
			symlink( $git, $bin . '/git' );
		}

		$command = array_merge(
			[ PHP_BINARY, dirname( __DIR__, 2 ) . '/bin/changesets' ],
			$args,
			[ '--root', $this->root ]
		);

		return $this->run_command( $command, array_merge( [ 'PATH' => $bin ], $environment ) );
	}

	private function run_command( array $command, ?array $environment = null, ?string $cwd = null ): array {
		$process = proc_open(
			$command,
			[
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes,
			$cwd,
			$environment
		);

		$this->assertIsResource( $process, 'The test process starts successfully.' );

		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return [ proc_close( $process ), $output, $error ];
	}
}
