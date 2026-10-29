<?php

namespace WPElevator\PHP_Changesets_Tests;

use PHPUnit\Framework\TestCase;
use WPElevator\PHP_Changesets\Logger;
use WPElevator\PHP_Changesets\Release_Exception;

require_once __DIR__ . '/../../php/bootstrap.php';

final class Logger_Test extends TestCase {

	/**
	 * @var resource
	 */
	private $stdout;

	/**
	 * @var resource
	 */
	private $stderr;

	private string $summary;

	protected function setUp(): void {
		$this->stdout = fopen( 'php://memory', 'w+' );
		$this->stderr = fopen( 'php://memory', 'w+' );
		$this->summary = (string) tempnam( sys_get_temp_dir(), 'php-changesets-summary-' );
	}

	protected function tearDown(): void {
		unlink( $this->summary );
	}

	public function test_github_errors_are_escaped_annotations(): void {
		$logger = new Logger( 'github', '/work', null, $this->stdout, $this->stderr );

		$logger->error(
			"100% done\nnext line",
			[
				'file' => '/work/plugins/a,b.php',
				'line' => 4,
				'title' => 'Version: mismatch',
			]
		);
		$logger->error( 'Outside.', [ 'file' => '/elsewhere/file.php' ] );

		$this->assertSame(
			"::error file=plugins/a%2Cb.php,line=4,title=Version%3A mismatch::100%25 done%0Anext line\n::error::Outside.\n",
			$this->read( $this->stderr ),
			'Errors escape their data and properties like wp-release, and files outside the workspace get no file.'
		);
	}

	public function test_text_errors_are_plain_and_write_no_summary(): void {
		$logger = new Logger( 'text', '/work', $this->summary, $this->stdout, $this->stderr );

		$logger->error( 'Plain.', [ 'file' => '/work/a.php' ] );
		$logger->summary( '### Ignored' );

		$this->assertSame( "Plain.\n", $this->read( $this->stderr ), 'Text errors are the message alone.' );
		$this->assertSame( '', file_get_contents( $this->summary ), 'Only the github format writes job summaries.' );
	}

	public function test_failures_list_their_problems_in_the_summary(): void {
		$logger = new Logger( 'github', '/work', $this->summary, $this->stdout, $this->stderr );
		$error = new Release_Exception(
			'Mismatch.',
			0,
			null,
			[
				[
					'message' => 'Version is 1.0.0, expected 1.1.0.',
					'file' => '/work/plugin.php',
					'line' => 3,
					'title' => 'Version mismatch',
				],
			]
		);

		$logger->failure( $error, 'changeset status failed' );

		$this->assertSame( "::error file=plugin.php,line=3,title=Version mismatch::Version is 1.0.0, expected 1.1.0.\n", $this->read( $this->stderr ), 'Each problem is its own annotation.' );
		$this->assertSame(
			"### changeset status failed\n\n| Problem | File |\n| --- | --- |\n| Version is 1.0.0, expected 1.1.0. | `plugin.php`:3 |\n\n",
			file_get_contents( $this->summary ),
			'The summary lists the problems with their files and lines.'
		);
	}

	/**
	 * @param resource $stream
	 */
	private function read( $stream ): string {
		rewind( $stream );

		return (string) stream_get_contents( $stream );
	}
}
