<?php

namespace WPElevator\PHP_Changesets_Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use WPElevator\PHP_Changesets\Project_Files;
use WPElevator\PHP_Changesets\Release_Exception;
use WPElevator\PHP_Changesets\Version_Files;

require_once __DIR__ . '/../../php/bootstrap.php';

final class Version_Files_Test extends TestCase {

	private string $directory;

	private Version_Files $versions;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/version-files-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->directory );
		$this->versions = new Version_Files( new Project_Files( $this->directory ) );
	}

	protected function tearDown(): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $entry ) {
			if ( $entry->isDir() && ! $entry->isLink() ) {
				rmdir( $entry->getPathname() );
			} else {
				unlink( $entry->getPathname() );
			}
		}

		rmdir( $this->directory );
	}

	public function test_propose_parameter_names_use_snake_case(): void {
		$method = new ReflectionMethod( Version_Files::class, 'propose' );
		$names = array_map(
			static fn( \ReflectionParameter $parameter ): string => $parameter->getName(),
			$method->getParameters()
		);

		$this->assertSame(
			[ 'target', 'old_version', 'new_version', 'contents_override' ],
			$names,
			'Version proposals use snake_case public parameter names.'
		);
	}

	public function test_composer_edit_preserves_formatting_and_nested_fields(): void {
		$old = "{\n  \"extra\": {\"version\": \"nested\"},\n  \"version\": \"1.2.3\"\n}\n";
		file_put_contents( $this->directory . '/composer.json', $old );

		$descriptor = [
			'kind' => 'composer-json',
			'file' => 'composer.json',
		];

		$this->assertSame( '1.2.3', $this->versions->read_current( $descriptor ), 'The top-level Composer version is the source.' );

		$proposal = $this->versions->propose( $descriptor, '1.2.3', '1.3.0' );

		$this->assertSame( str_replace( '"1.2.3"', '"1.3.0"', $old ), $proposal['new'], 'Only the version value changes.' );
		$this->assertSame( $old, file_get_contents( $proposal['path'] ), 'Proposing a change does not write the file.' );
	}

	public function test_callback_resolver_rejects_an_invalid_span(): void {
		$contents = "Version: 1.2.3\n";
		file_put_contents( $this->directory . '/version.txt', $contents );

		$descriptor = [
			'kind' => 'callback',
			'file' => 'version.txt',
			'resolve' => static fn( string $text ): array => [
				'value' => '1.2.3',
				'offset' => 0,
				'length' => 5,
			],
		];

		$this->expectException( Release_Exception::class );

		$this->versions->propose( $descriptor, '1.2.3', '1.2.4' );
	}

	public function test_header_and_constant_edits_preserve_surrounding_text(): void {
		file_put_contents(
			$this->directory . '/plugin.php',
			"<?php\n/*\n * Version: 1.2.3  \n */\n// const PLUGIN_VERSION = '0.0.1';\ndefine('PLUGIN_VERSION', '1.2.3');\n"
		);

		$descriptors = [
			[
				'kind' => 'wordpress-plugin-header',
				'file' => 'plugin.php',
			],
			[
				'kind' => 'php-constant',
				'file' => 'plugin.php',
				'constant' => 'PLUGIN_VERSION',
			],
		];

		foreach ( $descriptors as $descriptor ) {
			$proposal = $this->versions->propose( $descriptor, '1.2.3', '2.0.0' );

			$this->assertSame( 1, substr_count( $proposal['new'], '2.0.0' ), 'Exactly one version value is replaced.' );
			$this->assertStringContainsString( "// const PLUGIN_VERSION = '0.0.1';", $proposal['new'], 'Comments remain unchanged.' );
		}
	}

	public function test_wordpress_comment_header_forms(): void {
		$fixtures = [
			"// Version: 1.2.3\n",
			" * Version: 1.2.3*/\n",
			"/* Version: 1.2.3*/\n",
			"Stable tag: 1.2.3\n",
			"// Stable tag: 1.2.3*/\n",
		];

		foreach ( $fixtures as $contents ) {
			file_put_contents( $this->directory . '/version.txt', $contents );

			$descriptor = [
				'kind' => str_contains( $contents, 'Stable tag' ) ? 'wordpress-stable-tag' : 'wordpress-plugin-header',
				'file' => 'version.txt',
			];

			$this->assertSame( '1.2.3', $this->versions->read_current( $descriptor ), 'The version excludes comment delimiters.' );

			$proposal = $this->versions->propose( $descriptor, '1.2.3', '1.2.4' );

			$this->assertSame( str_replace( '1.2.3', '1.2.4', $contents ), $proposal['new'], 'The comment delimiters are preserved in the proposal.' );
		}
	}

	public function test_code_before_header_is_not_a_header(): void {
		file_put_contents( $this->directory . '/plugin.php', "<?php echo 'Version: 1.2.3';\n" );

		$this->expectException( Release_Exception::class );

		$this->versions->read_current(
			[
				'kind' => 'wordpress-plugin-header',
				'file' => 'plugin.php',
			]
		);
	}

	public function test_targets_in_one_file_can_be_composed(): void {
		$contents = "<?php\n/*\n * Version: 1.2.3\n */\nconst PLUGIN_VERSION = '1.2.3';\n";
		file_put_contents( $this->directory . '/plugin.php', $contents );

		$header = [
			'kind' => 'wordpress-plugin-header',
			'file' => 'plugin.php',
		];
		$constant = [
			'kind' => 'php-constant',
			'file' => 'plugin.php',
			'constant' => 'PLUGIN_VERSION',
		];

		$first = $this->versions->propose( $header, '1.2.3', '1.2.4' );
		$second = $this->versions->propose( $constant, '1.2.3', '1.2.4', $first['new'] );

		$this->assertSame( $first['new'], $second['old'], 'The second proposal uses the first proposed contents.' );
		$this->assertSame( str_replace( '1.2.3', '1.2.4', $contents ), $second['new'], 'Both version targets are included in the final proposal.' );
		$this->assertSame( $contents, file_get_contents( $first['path'] ), 'Composing proposals leaves the original file unchanged.' );
	}

	public function test_constant_like_text_inside_strings_is_ignored(): void {
		$contents = "<?php\n\$example = \"const PLUGIN_VERSION = '0.0.1';\";\nconst PLUGIN_VERSION = '1.2.3';\n";
		file_put_contents( $this->directory . '/plugin.php', $contents );

		$proposal = $this->versions->propose(
			[
				'kind' => 'php-constant',
				'file' => 'plugin.php',
				'constant' => 'PLUGIN_VERSION',
			],
			'1.2.3',
			'1.2.4'
		);

		$this->assertSame( str_replace( "'1.2.3'", "'1.2.4'", $contents ), $proposal['new'], 'Only the actual PHP declaration is changed.' );
	}

	public function test_optional_missing_targets(): void {
		file_put_contents( $this->directory . '/composer.json', '{}' );

		$this->assertNull(
			$this->versions->propose(
				[
					'kind' => 'composer-json',
					'file' => 'composer.json',
					'ifPresent' => true,
				],
				'1.0.0',
				'1.0.1'
			),
			'An optional absent Composer version is skipped.'
		);
		$this->assertNull(
			$this->versions->propose(
				[
					'kind' => 'wordpress-stable-tag',
					'file' => 'readme.txt',
					'ifPresent' => true,
				],
				'1.0.0',
				'1.0.1'
			),
			'An optional absent readme is skipped.'
		);
	}

	public function test_theme_header_reads_and_replaces_the_style_css_version(): void {
		$contents = "/*\nTheme Name: Example\nVersion: 1.2.3\n*/\n";
		file_put_contents( $this->directory . '/style.css', $contents );

		$this->assertSame( '1.2.3', $this->versions->read_current( [ 'kind' => 'wordpress-theme-header' ] ), 'The theme header defaults to the package stylesheet path.' );

		$proposal = $this->versions->propose(
			[
				'kind' => 'wordpress-theme-header',
				'file' => 'style.css',
			],
			'1.2.3',
			'1.3.0'
		);

		$this->assertSame( str_replace( '1.2.3', '1.3.0', $contents ), $proposal['new'], 'The theme header proposal replaces only the version value.' );
		$this->assertSame( $contents, file_get_contents( $this->directory . '/style.css' ), 'Proposing a theme header edit does not write the file.' );
	}

	/**
	 * Build an initialized repository with given tags without git metadata writes.
	 */
	private function init_repository(): void {
		$process = proc_open(
			[ 'git', 'init', '-q', $this->directory ],
			[
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes
		);

		foreach ( $pipes as $pipe ) {
			fclose( $pipe );
		}

		$this->assertSame( 0, proc_close( $process ), 'The temporary repository is initialized.' );
	}

	public function test_git_tag_patterns_expand_package_placeholders(): void {
		$this->init_repository();

		foreach ( [ 'example/lib-v1.0.0', 'packages/lib-v1.1.0', 'v1.2.0', 'packages/lib/1.3.0', '1.4.0' ] as $tag ) {
			$reference = $this->directory . '/.git/refs/tags/' . $tag;
			@mkdir( dirname( $reference ), 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Experimental subprocesses may already have created the directory.
			file_put_contents( $reference, str_repeat( 'a', 40 ) . "\n" );
		}

		$package = [
			'name' => 'example/lib',
			'path' => 'packages/lib',
		];

		$this->assertSame(
			'1.0.0',
			$this->versions->read_current(
				[
					'kind' => 'git-tag',
					'pattern' => '{name}-v{version}',
				],
				$package
			),
			'Package names expand into the tag pattern.'
		);
		$this->assertSame(
			'1.1.0',
			$this->versions->read_current(
				[
					'kind' => 'git-tag',
					'pattern' => '{path}-v{version}',
				],
				$package
			),
			'Package paths expand into the tag pattern.'
		);
		$this->assertSame(
			'1.2.0',
			$this->versions->read_current(
				[
					'kind' => 'git-tag',
					'pattern' => 'v{version}',
				],
				null
			),
			'Without package context a literal pattern matches as is.'
		);
		$this->assertSame(
			'1.3.0',
			$this->versions->read_current( [ 'kind' => 'git-tag' ], $package ),
			'Without a pattern the package reads the {path}/{version} default tags.'
		);
		$this->assertSame(
			'1.4.0',
			$this->versions->read_current( [ 'kind' => 'git-tag' ], null ),
			'Without package context the default expands like a package at the root.'
		);
		$this->assertSame(
			'1.1.0',
			$this->versions->read_current(
				[
					'kind' => 'git-tag',
					'pattern' => '{path}-v{version}',
				],
				[
					'name' => 'example/other',
					'path' => 'packages//lib',
				]
			),
			'Expanded separators collapse like other package templates.'
		);
	}

	public function test_duplicate_version_is_rejected(): void {
		file_put_contents( $this->directory . '/composer.json', '{"version":"1.0.0","version":"1.0.0"}' );

		$this->expectException( Release_Exception::class );

		$this->versions->propose(
			[
				'kind' => 'composer-json',
				'file' => 'composer.json',
			],
			'1.0.0',
			'1.0.1'
		);
	}

	public function test_escaping_symlink_is_rejected(): void {
		symlink( sys_get_temp_dir(), $this->directory . '/outside' );

		$this->expectException( Release_Exception::class );

		$this->versions->propose(
			[
				'kind' => 'wordpress-stable-tag',
				'file' => 'outside/missing.txt',
				'ifPresent' => true,
			],
			'1.0.0',
			'1.0.1'
		);
	}

	public function test_git_tag_initial_version_fallback_and_precedence(): void {
		$process = proc_open(
			[ 'git', 'init', '-q', $this->directory ],
			[
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes
		);

		foreach ( $pipes as $pipe ) {
			fclose( $pipe );
		}

		$this->assertSame( 0, proc_close( $process ), 'The temporary repository is initialized.' );

		$source = [
			'kind' => 'git-tag',
			'pattern' => 'v{version}',
			'initialVersion' => '1.0.0',
		];

		$this->assertSame( '1.0.0', $this->versions->read_current( $source ), 'The explicit initial version is used when no matching tag exists.' );

		file_put_contents( $this->directory . '/.git/refs/tags/v1.2.0', str_repeat( 'a', 40 ) . "\n" );

		$this->assertSame( '1.2.0', $this->versions->read_current( $source ), 'A matching stable tag takes precedence over the initial version.' );
	}

	public function test_invalid_git_tag_initial_version_fails(): void {
		$this->expectException( Release_Exception::class );

		$this->versions->read_current(
			[
				'kind' => 'git-tag',
				'initialVersion' => '01.0.0',
			]
		);
	}

	public function test_stable_tags_are_sorted_and_matched_exactly(): void {
		$process = proc_open(
			[ 'git', 'init', '-q', $this->directory ],
			[
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes
		);

		foreach ( $pipes as $pipe ) {
			fclose( $pipe );
		}

		$this->assertSame( 0, proc_close( $process ), 'The temporary repository is initialized.' );

		mkdir( $this->directory . '/.git/refs/tags/releases' );

		foreach ( [ 'v1.9.0', 'v1.10.0' ] as $tag ) {
			file_put_contents( $this->directory . '/.git/refs/tags/releases/' . $tag, str_repeat( 'a', 40 ) . "\n" );
		}

		$this->assertSame(
			'1.10.0',
			$this->versions->read_current(
				[
					'kind' => 'git-tag',
					'pattern' => 'releases/v{version}',
				]
			),
			'The latest stable semantic version wins.'
		);
		$this->assertTrue( $this->versions->tag_exists( 'releases/v1.9.0' ), 'An exact existing tag is found.' );
		$this->assertFalse( $this->versions->tag_exists( 'releases/*' ), 'A wildcard is not treated as an exact tag.' );
	}

	public function test_git_tag_sources_read_and_order_prerelease_versions(): void {
		$this->init_repository();

		foreach ( [ 'v1.9.0', 'v1.10.0', 'v2.0.0-rc.1', 'v2.0.0-rc.2', 'v2.0.0-x.11' ] as $tag ) {
			file_put_contents( $this->directory . '/.git/refs/tags/' . $tag, str_repeat( 'a', 40 ) . "\n" );
		}

		$this->assertSame(
			'2.0.0-x.11',
			$this->versions->read_current(
				[
					'kind' => 'git-tag',
					'pattern' => 'v{version}',
				]
			),
			'Pre-release identifiers compare lexically and their sequences numerically.'
		);

		file_put_contents( $this->directory . '/.git/refs/tags/v2.0.0', str_repeat( 'a', 40 ) . "\n" );

		$this->assertSame(
			'2.0.0',
			$this->versions->read_current(
				[
					'kind' => 'git-tag',
					'pattern' => 'v{version}',
				]
			),
			'A stable version outranks the pre-releases of the same base.'
		);
	}

	public function test_tagged_versions_list_the_spans_sharing_a_prefix(): void {
		$this->init_repository();

		foreach ( [ 'example/lib-v1.0.0', 'example/lib-v1.1.0-rc.1', 'other-v1.0.0' ] as $tag ) {
			$reference = $this->directory . '/.git/refs/tags/' . $tag;
			@mkdir( dirname( $reference ), 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Experimental subprocesses may already have created the directory.
			file_put_contents( $reference, str_repeat( 'a', 40 ) . "\n" );
		}

		$this->assertSame(
			[ '1.0.0', '1.1.0-rc.1' ],
			$this->versions->tagged_versions( 'example/lib-v' ),
			'Tagged version spans drop the package prefix and keep pre-release spans.'
		);
	}
}
