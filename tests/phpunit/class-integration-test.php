<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class Integration_Test extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/php-changesets-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->root );
		$this->run_command( array( 'git', 'init', '-q', $this->root ) );
		$this->run_command( array( 'git', '-C', $this->root, '-c', 'user.name=Test', '-c', 'user.email=test@example.com', 'commit', '--allow-empty', '-qm', 'Initial' ) );
	}

	protected function tearDown(): void {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $item ) {
			$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $this->root );
	}

	public function test_tag_only_library_release_without_node(): void {
		$manifest = "{\n  \"name\": \"example/library\"\n}\n";
		file_put_contents( $this->root . '/composer.json', $manifest );
		$this->git_tag( 'v1.2.3' );
		$this->assert_cli_success( array( 'init' ) );
		$this->assert_cli_success( array( 'add', '--bump', 'patch', '--summary', 'Fix a defect.', '--name', 'fix' ) );
		$status = json_decode( $this->assert_cli_success( array( 'status', '--json' ) ), true );
		$this->assertSame( 'example/library', $status['project'], 'Status identifies the Composer project.' );
		$this->assertSame( '1.2.3', $status['currentVersion'], 'Status reads the latest stable Git tag.' );
		$this->assertSame( '1.2.4', $status['nextVersion'], 'A patch changeset increments the patch version.' );
		$this->assertSame( 'v1.2.4', $status['tag'], 'Status proposes the configured tag.' );
		$preview = json_decode( $this->assert_cli_success( array( 'version', '--dry-run', '--json', '--date', '2026-01-02' ) ), true );
		$this->assertStringContainsString( 'Fix a defect.', $preview['diff'], 'The dry run previews release notes.' );
		$this->assertFileDoesNotExist( $this->root . '/CHANGELOG.md', 'The dry run does not write the changelog.' );
		$this->assertFileExists( $this->root . '/.changeset/fix.md', 'The dry run preserves the changeset.' );
		$this->assert_cli_success( array( 'version', '--date', '2026-01-02' ) );
		$this->assertSame( $manifest, file_get_contents( $this->root . '/composer.json' ), 'Tag-only releases leave the manifest unchanged.' );
		$changelog = file_get_contents( $this->root . '/CHANGELOG.md' );
		$this->assertStringContainsString( '## 1.2.4 (2026-01-02)', $changelog, 'The release writes the expected changelog section.' );
		$this->assertFileDoesNotExist( $this->root . '/.changeset/fix.md', 'The release consumes the changeset.' );
		$this->assert_cli_success( array( 'version', '--date', '2026-01-02' ) );
		$this->assertSame( $changelog, file_get_contents( $this->root . '/CHANGELOG.md' ), 'A second run leaves the release unchanged.' );
	}

	public function test_composer_install_exposes_vendor_bin(): void {
		$package = realpath( dirname( __DIR__, 2 ) );
		$manifest = array(
			'name' => 'example/consumer',
			'minimum-stability' => 'dev',
			'repositories' => array(
				array(
					'type' => 'path',
					'url' => $package,
					'options' => array( 'symlink' => true ),
				),
			),
			'require-dev' => array( 'wpelevator/php-changesets' => '*' ),
		);
		file_put_contents( $this->root . '/composer.json', json_encode( $manifest ) );
		$installed = $this->run_command( array( 'composer', 'install', '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress' ), null, $this->root );
		$this->assertSame( 0, $installed[0], 'A consumer should install the local Composer package: ' . $installed[2] );
		$bin = $this->root . '/vendor/bin/changesets';
		$this->assertFileExists( $bin, 'Composer should expose the package executable in vendor/bin.' );
		$help = $this->run_command( array( PHP_BINARY, $bin, '--help' ) );
		$this->assertSame( 0, $help[0], 'The Composer proxy executable should run successfully: ' . $help[2] );
		$this->assertStringContainsString( 'changesets <command>', $help[1], 'The installed CLI should display its command help.' );
		$probe = 'require ' . var_export( $this->root . '/vendor/autoload.php', true ) . '; echo class_exists(' . var_export( 'WPElevator\\PHP_Changesets\\Release_Engine', true ) . ') ? "loaded" : "missing";';
		$autoload = $this->run_command( array( PHP_BINARY, '-r', $probe ) );
		$this->assertSame( 0, $autoload[0], 'Composer classmap autoloading should run without errors: ' . $autoload[2] );
		$this->assertSame( 'loaded', $autoload[1], 'Composer should autoload the package class from php/.' );
	}

	public function test_add_rejects_reserved_readme_name(): void {
		file_put_contents( $this->root . '/composer.json', "{\"name\":\"example/library\",\"version\":\"1.2.3\"}\n" );
		$error = $this->assert_cli_failure( array( 'add', '--bump', 'patch', '--summary', 'Fix.', '--name', 'README' ) );
		$this->assertStringContainsString( 'cannot be README', $error, 'The CLI should reject a filename that the reader would ignore.' );
		$this->assertFileDoesNotExist( $this->root . '/.changeset/README.md', 'The reserved filename must not be written.' );
	}

	public function test_git_tag_source_can_bootstrap_from_explicit_initial_version(): void {
		$manifest = "{\n  \"name\": \"example/library\"\n}\n";
		file_put_contents( $this->root . '/composer.json', $manifest );
		$config = array(
			'name' => 'example/library',
			'version' => array(
				'from' => array(
					'kind' => 'git-tag',
					'pattern' => 'v{version}',
					'initialVersion' => '0.1.0',
				),
				'to' => array(),
			),
		);
		file_put_contents( $this->root . '/.php-changesets.json', json_encode( $config ) );
		$this->assert_cli_success( array( 'add', '--bump', 'minor', '--summary', 'First feature.', '--name', 'first' ) );
		$status = json_decode( $this->assert_cli_success( array( 'status', '--json' ) ), true );
		$this->assertSame( '0.1.0', $status['currentVersion'], 'The configured initial version is used when no stable tag exists.' );
		$this->assertSame( '0.2.0', $status['nextVersion'], 'The bump is calculated from the explicit initial version.' );
		$this->assertSame( 'v0.2.0', $status['tag'], 'The first release proposes the configured tag format.' );
		$this->assert_cli_success( array( 'version', '--date', '2026-01-02' ) );
		$this->assertSame( $manifest, file_get_contents( $this->root . '/composer.json' ), 'A bootstrap release does not change the tag-only manifest.' );
		$this->assertStringContainsString( '## 0.2.0 (2026-01-02)', file_get_contents( $this->root . '/CHANGELOG.md' ), 'The first release writes its changelog section.' );
		$this->assertFileDoesNotExist( $this->root . '/.changeset/first.md', 'The first release consumes its changeset.' );
	}

	public function test_php_config_resolves_and_replaces_a_custom_version_span(): void {
		$version_file = "Package release = 1.2.3\nOther version = 9.9.9\n";
		file_put_contents( $this->root . '/release.txt', $version_file );
		mkdir( $this->root . '/.changeset' );
		file_put_contents(
			$this->root . '/.changeset/config.php',
			<<<'PHP'
<?php
$resolve = static function ( string $contents ): ?array {
	if ( ! preg_match( '/^Package release ?[=:] (\d+\.\d+\.\d+)$/m', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}
	return array(
		'value' => $matches[1][0],
		'offset' => $matches[1][1],
		'length' => strlen( $matches[1][0] ),
	);
};
$version = array(
	'kind' => 'callback',
	'file' => 'release.txt',
	'resolve' => $resolve,
	'replace' => static function ( string $contents, string $old, string $new ): string {
		return str_replace( "Package release = {$old}", "Package release: {$new}", $contents );
	},
);
return array(
	'name' => 'example/library',
	'version' => array( 'from' => $version, 'to' => array( $version ) ),
	'changelog' => '',
);
PHP
		);
		$this->assert_cli_success( array( 'init' ) );
		$this->assertFileDoesNotExist( $this->root . '/.php-changesets.json', 'Init should preserve a PHP config without creating a JSON config beside it.' );
		$this->assert_cli_success( array( 'add', '--bump', 'patch', '--summary', 'Fix.', '--name', 'fix' ) );
		$status = json_decode( $this->assert_cli_success( array( 'status', '--json' ) ), true );
		$this->assertSame( '1.2.3', $status['currentVersion'], 'The PHP resolver supplies the current version.' );
		$this->assertSame( '1.2.4', $status['nextVersion'], 'The PHP resolver supports normal version planning.' );
		$preview = json_decode( $this->assert_cli_success( array( 'version', '--dry-run', '--json' ) ), true );
		$this->assertStringContainsString( '+Package release: 1.2.4', $preview['diff'], 'The dry run previews the custom replacement callback result.' );
		$this->assertSame( $version_file, file_get_contents( $this->root . '/release.txt' ), 'The dry run does not write the custom target.' );
		$this->assert_cli_success( array( 'version' ) );
		$this->assertSame( "Package release: 1.2.4\nOther version = 9.9.9\n", file_get_contents( $this->root . '/release.txt' ), 'The release applies the custom replacement without changing unrelated text.' );
	}

	public function test_php_and_json_configs_cannot_be_combined(): void {
		mkdir( $this->root . '/.changeset' );
		file_put_contents( $this->root . '/.changeset/config.php', '<?php return array();' );
		file_put_contents( $this->root . '/.php-changesets.json', '{}' );
		$error = $this->assert_cli_failure( array( 'status' ) );
		$this->assertStringContainsString( 'only one', $error, 'A project must choose one authoritative config format.' );
	}

	public function test_framework_config_directory_is_discovered(): void {
		mkdir( $this->root . '/config' );
		file_put_contents( $this->root . '/config/changesets.php', '<?php return array( "name" => "example/library", "version" => array( "from" => array( "kind" => "git-tag", "initialVersion" => "0.1.0" ), "to" => array() ) );' );
		$this->assert_cli_success( array( 'init' ) );
		$this->assertFileDoesNotExist( $this->root . '/.php-changesets.json', 'Init should preserve a config/changesets.php file without writing a second config.' );
		$status = json_decode( $this->assert_cli_success( array( 'status', '--json' ) ), true );
		$this->assertSame( '0.1.0', $status['currentVersion'], 'The CLI should load the framework-style config file.' );
	}

	public function test_explicit_plugin_configuration_updates_only_declared_targets(): void {
		$header = "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n";
		$readme = "=== Example ===\nStable tag: 1.2.3\n";
		file_put_contents( $this->root . '/plugin.php', $header );
		file_put_contents( $this->root . '/readme.txt', $readme );
		$this->plugin_config();
		$this->assert_cli_success( array( 'add', '--bump', 'minor', '--summary', 'Add a feature.', '--name', 'feature' ) );
		$this->assert_cli_success( array( 'version', '--date', '2026-01-02' ) );
		$this->assertSame( str_replace( '1.2.3', '1.3.0', $header ), file_get_contents( $this->root . '/plugin.php' ), 'The plugin header receives the new version.' );
		$this->assertSame( str_replace( '1.2.3', '1.3.0', $readme ), file_get_contents( $this->root . '/readme.txt' ), 'The readme stable tag receives the new version.' );
	}

	public function test_header_and_constant_in_same_file_are_updated_together(): void {
		$plugin = "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\nconst EXAMPLE_VERSION = '1.2.3';\n";
		file_put_contents( $this->root . '/plugin.php', $plugin );
		file_put_contents( $this->root . '/readme.txt', "Stable tag: 1.2.3\n" );
		$this->plugin_config( true );
		$this->assert_cli_success( array( 'add', '--bump', 'patch', '--summary', 'Fix.', '--name', 'fix' ) );
		$this->assert_cli_success( array( 'version', '--date', '2026-01-02' ) );
		$this->assertSame( str_replace( '1.2.3', '1.2.4', $plugin ), file_get_contents( $this->root . '/plugin.php' ), 'Both configured version locations in the same file are updated.' );
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
		$status_error = $this->assert_cli_failure( array( 'status', '--json' ) );
		$this->assertStringContainsString( 'does not match', $status_error, 'Status should detect mismatched declared version targets even without changesets.' );
		$this->assert_cli_success( array( 'add', '--bump', 'patch', '--summary', 'Fix.', '--name', 'fix' ) );
		$changeset = file_get_contents( $this->root . '/.changeset/fix.md' );
		$config = file_get_contents( $this->root . '/.php-changesets.json' );
		$error = $this->assert_cli_failure( array( 'version', '--date', '2026-01-02' ) );
		$this->assertStringContainsString( 'does not match', $error, 'The error identifies the version mismatch.' );
		$this->assertSame( $plugin, file_get_contents( $this->root . '/plugin.php' ), 'The earlier valid target remains unchanged.' );
		$this->assertSame( $readme, file_get_contents( $this->root . '/readme.txt' ), 'The mismatched target remains unchanged.' );
		$this->assertSame( $changelog, file_get_contents( $this->root . '/CHANGELOG.md' ), 'The changelog remains unchanged when preflight fails.' );
		$this->assertSame( $changeset, file_get_contents( $this->root . '/.changeset/fix.md' ), 'The changeset remains intact when preflight fails.' );
		$this->assertSame( $config, file_get_contents( $this->root . '/.php-changesets.json' ), 'The project configuration remains unchanged.' );
	}

	public function test_invalid_changeset_and_ambiguous_target_fail_without_writes(): void {
		file_put_contents( $this->root . '/plugin.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n" );
		file_put_contents( $this->root . '/readme.txt', "Stable tag: 1.2.3\nStable tag: 1.2.3\n" );
		$this->plugin_config();
		mkdir( $this->root . '/.changeset' );
		$file = $this->root . '/.changeset/bad.md';
		file_put_contents( $file, "---\nunknown/package: patch\n---\n\nBad input.\n" );
		$this->assert_cli_failure( array( 'version' ) );
		$this->assertFileExists( $file, 'Invalid changesets are not consumed.' );
		file_put_contents( $file, "---\nexample/plugin: patch\n---\n\nValid input.\n" );
		$this->assert_cli_failure( array( 'version' ) );
		$this->assertSame( "Stable tag: 1.2.3\nStable tag: 1.2.3\n", file_get_contents( $this->root . '/readme.txt' ), 'Ambiguous targets remain unchanged.' );
		$this->assertFileExists( $file, 'A target error does not consume the changeset.' );
	}

	public function test_existing_proposed_tag_is_rejected_before_writing(): void {
		file_put_contents( $this->root . '/plugin.php', "<?php\n/*\nPlugin Name: Example\nVersion: 1.2.3\n*/\n" );
		file_put_contents( $this->root . '/readme.txt', "Stable tag: 1.2.3\n" );
		$this->plugin_config();
		$this->git_tag( 'v1.2.4' );
		$this->assert_cli_success( array( 'add', '--bump', 'patch', '--summary', 'Fix.', '--name', 'fix' ) );
		$error = $this->assert_cli_failure( array( 'version' ) );
		$this->assertStringContainsString( 'tag already exists', $error, 'The diagnostic identifies the conflicting tag.' );
		$this->assertFileExists( $this->root . '/.changeset/fix.md', 'A conflicting tag does not consume the changeset.' );
		$this->assertFileDoesNotExist( $this->root . '/CHANGELOG.md', 'A conflicting tag does not write a changelog.' );
	}

	public function test_changelog_sections_attach_to_the_front_of_the_document(): void {
		file_put_contents( $this->root . '/composer.json', "{\n  \"name\": \"example/library\"\n}\n" );
		file_put_contents( $this->root . '/CHANGELOG.md', "## 0.1.1 (2026-01-01)\n\n- Existing notes.\n" );
		$this->git_tag( 'v1.2.3' );
		$this->assert_cli_success( array( 'add', '--bump', 'patch', '--summary', 'Fix a defect.', '--name', 'fix' ) );
		$this->assert_cli_success( array( 'version', '--date', '2026-01-02' ) );
		$this->assertSame(
			"## 1.2.4 (2026-01-02)\n\n- Fix a defect.\n\n## 0.1.1 (2026-01-01)\n\n- Existing notes.\n",
			file_get_contents( $this->root . '/CHANGELOG.md' ),
			'A changelog without a leading title receives its new section at the very top.'
		);

		$this->setUp();
		file_put_contents( $this->root . '/CHANGELOG.md', "- Leading note beyond any heading.\n" );
		file_put_contents( $this->root . '/composer.json', "{\n  \"name\": \"example/library\"\n}\n" );
		$this->git_tag( 'v1.2.3' );
		$this->assert_cli_success( array( 'add', '--bump', 'patch', '--summary', 'Fix a defect.', '--name', 'fix' ) );
		$this->assert_cli_success( array( 'version', '--date', '2026-01-02' ) );
		$this->assertStringStartsWith( '## 1.2.4 (2026-01-02)', file_get_contents( $this->root . '/CHANGELOG.md' ), 'A changelog whose first line is not a heading receives its new section first.' );

		$this->setUp();
		file_put_contents( $this->root . '/composer.json', "{\n  \"name\": \"example/library\"\n}\n" );
		$this->git_tag( 'v1.2.3' );
		file_put_contents( $this->root . '/CHANGELOG.md', "# Changelog\n\n## 0.1.1 (2026-01-01)\n\n- Existing notes.\n" );
		$this->assert_cli_success( array( 'init' ) );
		$this->assert_cli_success( array( 'add', '--bump', 'patch', '--summary', 'Fix a defect.', '--name', 'fix' ) );
		$this->assert_cli_success( array( 'version', '--date', '2026-01-02' ) );
		$this->assertSame(
			"# Changelog\n\n## 1.2.4 (2026-01-02)\n\n- Fix a defect.\n\n## 0.1.1 (2026-01-01)\n\n- Existing notes.\n",
			file_get_contents( $this->root . '/CHANGELOG.md' ),
			'A changelog with a leading title inserts its new section below the title.'
		);
	}

	private function plugin_config( bool $include_constant = false ): void {
		$header = array(
			'kind' => 'wordpress-plugin-header',
			'file' => 'plugin.php',
		);
		$config = array(
			'name' => 'example/plugin',
			'version' => array(
				'from' => $header,
				'to' => array(
					$header,
					array(
						'kind' => 'wordpress-stable-tag',
						'file' => 'readme.txt',
					),
				),
			),
			'tagFormat' => 'v{version}',
			'changesets' => '.changeset',
			'changelog' => 'CHANGELOG.md',
		);
		if ( $include_constant ) {
			$config['version']['to'][] = array(
				'kind' => 'php-constant',
				'file' => 'plugin.php',
				'constant' => 'EXAMPLE_VERSION',
			);
		}
		file_put_contents( $this->root . '/.php-changesets.json', json_encode( $config ) );
	}

	private function git_tag( string $tag ): void {
		$this->run_command( array( 'git', '-C', $this->root, 'tag', $tag ) );
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

	private function cli( array $args ): array {
		// Restrict PATH to Git alone, so Node cannot be used by the CLI.
		$bin = $this->root . '/bin';
		if ( ! is_dir( $bin ) ) {
			mkdir( $bin );
			$git = trim( (string) shell_exec( 'command -v git' ) );
			symlink( $git, $bin . '/git' );
		}
		return $this->run_command( array_merge( array( PHP_BINARY, dirname( __DIR__, 2 ) . '/bin/changesets' ), $args, array( '--root', $this->root ) ), array( 'PATH' => $bin ) );
	}

	private function run_command( array $command, ?array $environment = null, ?string $cwd = null ): array {
		$process = proc_open( $command, array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes, $cwd, $environment );
		$this->assertIsResource( $process, 'The test process starts successfully.' );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( proc_close( $process ), $output, $error );
	}
}
