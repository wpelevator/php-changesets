<?php

namespace WPElevator\PHP_Changesets;

/**
 * Run git commands in one directory, like the wp-release git runner, so that
 * tests can replace the runner with a subclass.
 */
class Git {

	private string $directory;

	public function __construct( string $directory ) {
		$this->directory = $directory;
	}

	/**
	 * Run a git command and return its output.
	 *
	 * @param string[] $arguments  Arguments after `git`.
	 * @param int[]    $exit_codes Exit codes that count as success, like 1 for a `diff --no-index` with changes.
	 * @throws Git_Exception When git can't run or exits with another code.
	 */
	public function run( array $arguments, array $exit_codes = [ 0 ] ): string {
		$process = proc_open(
			array_merge( [ 'git' ], $arguments ),
			[
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes,
			$this->directory
		);

		if ( ! is_resource( $process ) ) {
			throw new Git_Exception( 'Unable to run git.' );
		}

		fclose( $pipes[0] );
		$output = (string) stream_get_contents( $pipes[1] );
		$error = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit = proc_close( $process );

		if ( ! in_array( $exit, $exit_codes, true ) ) {
			throw new Git_Exception( sprintf( 'git %s failed: %s', implode( ' ', $arguments ), trim( $error ) ) );
		}

		return $output;
	}
}
