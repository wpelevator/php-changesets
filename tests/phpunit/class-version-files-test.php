<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WPElevator\PHP_Changesets\Release_Exception;
use WPElevator\PHP_Changesets\Version_Files;

require_once __DIR__ . '/../../php/bootstrap.php';

final class Version_Files_Test extends TestCase {

	private string $directory;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/version-files-' . bin2hex( random_bytes( 8 ) );
		mkdir( $this->directory );
	}

	protected function tearDown(): void {
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->directory, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $iterator as $entry ) {
			if ( $entry->isDir() && ! $entry->isLink() ) {
				rmdir( $entry->getPathname() );
			} else {
				unlink( $entry->getPathname() );
			}
		}
		rmdir( $this->directory );
	}

	public function testComposerEditPreservesFormattingAndNestedFields(): void {
		$old = "{\n  \"extra\": {\"version\": \"nested\"},\n  \"version\": \"1.2.3\"\n}\n";
		file_put_contents( $this->directory . '/composer.json', $old );
		$descriptor = array(
			'kind' => 'composer-json',
			'file' => 'composer.json',
		);
		$this->assertSame( '1.2.3', Version_Files::readCurrent( $this->directory, $descriptor ), 'The top-level Composer version is the source.' );
		$proposal = Version_Files::propose( $this->directory, $descriptor, '1.2.3', '1.3.0' );
		$this->assertSame( str_replace( '"1.2.3"', '"1.3.0"', $old ), $proposal['new'], 'Only the version value changes.' );
		$this->assertSame( $old, file_get_contents( $proposal['path'] ), 'Proposing a change does not write the file.' );
	}

	public function test_callback_resolver_rejects_an_invalid_span(): void {
		$contents = "Version: 1.2.3\n";
		file_put_contents( $this->directory . '/version.txt', $contents );
		$descriptor = array(
			'kind' => 'callback',
			'file' => 'version.txt',
			'resolve' => static fn( string $text ): array => array(
				'value' => '1.2.3',
				'offset' => 0,
				'length' => 5,
			),
		);
		$this->expectException( Release_Exception::class );
		Version_Files::propose( $this->directory, $descriptor, '1.2.3', '1.2.4' );
	}

	public function testHeaderAndConstantEditsPreserveSurroundingText(): void {
		file_put_contents( $this->directory . '/plugin.php', "<?php\n/*\n * Version: 1.2.3  \n */\n// const PLUGIN_VERSION = '0.0.1';\ndefine('PLUGIN_VERSION', '1.2.3');\n" );
		foreach ( array(
			array(
				'kind' => 'wordpress-plugin-header',
				'file' => 'plugin.php',
			),
			array(
				'kind' => 'php-constant',
				'file' => 'plugin.php',
				'constant' => 'PLUGIN_VERSION',
			),
		) as $descriptor ) {
			$proposal = Version_Files::propose( $this->directory, $descriptor, '1.2.3', '2.0.0' );
			$this->assertSame( 1, substr_count( $proposal['new'], '2.0.0' ), 'Exactly one version value is replaced.' );
			$this->assertStringContainsString( "// const PLUGIN_VERSION = '0.0.1';", $proposal['new'], 'Comments remain unchanged.' );
		}
	}

	public function testWordPressCommentHeaderForms(): void {
		foreach ( array( "// Version: 1.2.3\n", " * Version: 1.2.3*/\n", "/* Version: 1.2.3*/\n", "Stable tag: 1.2.3\n", "// Stable tag: 1.2.3*/\n" ) as $contents ) {
			file_put_contents( $this->directory . '/version.txt', $contents );
			$descriptor = array(
				'kind' => str_contains( $contents, 'Stable tag' ) ? 'wordpress-stable-tag' : 'wordpress-plugin-header',
				'file' => 'version.txt',
			);
			$this->assertSame( '1.2.3', Version_Files::readCurrent( $this->directory, $descriptor ), 'The version excludes comment delimiters.' );
			$proposal = Version_Files::propose( $this->directory, $descriptor, '1.2.3', '1.2.4' );
			$this->assertSame( str_replace( '1.2.3', '1.2.4', $contents ), $proposal['new'], 'The comment delimiters are preserved in the proposal.' );
		}
	}

	public function testCodeBeforeHeaderIsNotAHeader(): void {
		file_put_contents( $this->directory . '/plugin.php', "<?php echo 'Version: 1.2.3';\n" );
		$this->expectException( Release_Exception::class );
		Version_Files::readCurrent(
			$this->directory,
			array(
				'kind' => 'wordpress-plugin-header',
				'file' => 'plugin.php',
			)
		);
	}

	public function testTargetsInOneFileCanBeComposed(): void {
		$contents = "<?php\n/*\n * Version: 1.2.3\n */\nconst PLUGIN_VERSION = '1.2.3';\n";
		file_put_contents( $this->directory . '/plugin.php', $contents );
		$header = array(
			'kind' => 'wordpress-plugin-header',
			'file' => 'plugin.php',
		);
		$constant = array(
			'kind' => 'php-constant',
			'file' => 'plugin.php',
			'constant' => 'PLUGIN_VERSION',
		);
		$first = Version_Files::propose( $this->directory, $header, '1.2.3', '1.2.4' );
		$second = Version_Files::propose( $this->directory, $constant, '1.2.3', '1.2.4', $first['new'] );
		$this->assertSame( $first['new'], $second['old'], 'The second proposal uses the first proposed contents.' );
		$this->assertSame( str_replace( '1.2.3', '1.2.4', $contents ), $second['new'], 'Both version targets are included in the final proposal.' );
		$this->assertSame( $contents, file_get_contents( $first['path'] ), 'Composing proposals leaves the original file unchanged.' );
	}

	public function testConstantLikeTextInsideStringsIsIgnored(): void {
		$contents = "<?php\n\$example = \"const PLUGIN_VERSION = '0.0.1';\";\nconst PLUGIN_VERSION = '1.2.3';\n";
		file_put_contents( $this->directory . '/plugin.php', $contents );
		$proposal = Version_Files::propose(
			$this->directory,
			array(
				'kind' => 'php-constant',
				'file' => 'plugin.php',
				'constant' => 'PLUGIN_VERSION',
			),
			'1.2.3',
			'1.2.4'
		);
		$this->assertSame( str_replace( "'1.2.3'", "'1.2.4'", $contents ), $proposal['new'], 'Only the actual PHP declaration is changed.' );
	}

	public function testOptionalMissingTargets(): void {
		file_put_contents( $this->directory . '/composer.json', '{}' );
		$this->assertNull(
			Version_Files::propose(
				$this->directory,
				array(
					'kind' => 'composer-json',
					'file' => 'composer.json',
					'ifPresent' => true,
				),
				'1.0.0',
				'1.0.1'
			),
			'An optional absent Composer version is skipped.'
		);
		$this->assertNull(
			Version_Files::propose(
				$this->directory,
				array(
					'kind' => 'wordpress-stable-tag',
					'file' => 'readme.txt',
					'ifPresent' => true,
				),
				'1.0.0',
				'1.0.1'
			),
			'An optional absent readme is skipped.'
		);
	}

	public function testDuplicateVersionIsRejected(): void {
		file_put_contents( $this->directory . '/composer.json', '{"version":"1.0.0","version":"1.0.0"}' );
		$this->expectException( Release_Exception::class );
		Version_Files::propose(
			$this->directory,
			array(
				'kind' => 'composer-json',
				'file' => 'composer.json',
			),
			'1.0.0',
			'1.0.1'
		);
	}

	public function testEscapingSymlinkIsRejected(): void {
		symlink( sys_get_temp_dir(), $this->directory . '/outside' );
		$this->expectException( Release_Exception::class );
		Version_Files::propose(
			$this->directory,
			array(
				'kind' => 'wordpress-stable-tag',
				'file' => 'outside/missing.txt',
				'ifPresent' => true,
			),
			'1.0.0',
			'1.0.1'
		);
	}

	public function testGitTagInitialVersionFallbackAndPrecedence(): void {
		$process = proc_open( array( 'git', 'init', '-q', $this->directory ), array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		foreach ( $pipes as $pipe ) {
			fclose( $pipe );
		}
		$this->assertSame( 0, proc_close( $process ), 'The temporary repository is initialized.' );
		$source = array(
			'kind' => 'git-tag',
			'initialVersion' => '1.0.0',
		);
		$this->assertSame( '1.0.0', Version_Files::readCurrent( $this->directory, $source ), 'The explicit initial version is used when no matching tag exists.' );
		file_put_contents( $this->directory . '/.git/refs/tags/v1.2.0', str_repeat( 'a', 40 ) . "\n" );
		$this->assertSame( '1.2.0', Version_Files::readCurrent( $this->directory, $source ), 'A matching stable tag takes precedence over the initial version.' );
	}

	public function testInvalidGitTagInitialVersionFails(): void {
		$this->expectException( Release_Exception::class );
		Version_Files::readCurrent(
			$this->directory,
			array(
				'kind' => 'git-tag',
				'initialVersion' => '01.0.0',
			)
		);
	}

	public function testStableTagsAreSortedAndMatchedExactly(): void {
		$process = proc_open(
			array( 'git', 'init', '-q', $this->directory ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		foreach ( $pipes as $pipe ) {
			fclose( $pipe );
		}
		$this->assertSame( 0, proc_close( $process ), 'The temporary repository is initialized.' );
		mkdir( $this->directory . '/.git/refs/tags/releases' );
		foreach ( array( 'v1.9.0', 'v1.10.0', 'v2.0.0-rc.1' ) as $tag ) {
			file_put_contents( $this->directory . '/.git/refs/tags/releases/' . $tag, str_repeat( 'a', 40 ) . "\n" );
		}
		$this->assertSame(
			'1.10.0',
			Version_Files::readCurrent(
				$this->directory,
				array(
					'kind' => 'git-tag',
					'pattern' => 'releases/v{version}',
				)
			),
			'The latest stable semantic version wins.'
		);
		$this->assertTrue( Version_Files::tagExists( $this->directory, 'releases/v1.9.0' ), 'An exact existing tag is found.' );
		$this->assertFalse( Version_Files::tagExists( $this->directory, 'releases/*' ), 'A wildcard is not treated as an exact tag.' );
	}
}
