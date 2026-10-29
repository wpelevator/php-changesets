<?php

namespace WPElevator\PHP_Changesets;

/**
 * Log messages as plain text or, like the wp-release `GitHubActionsWriter`,
 * as GitHub Actions workflow commands with job summaries.
 *
 * Errors with a file become annotations on that file and line. Summaries are
 * appended to the job summary file in the github format only.
 */
final class Logger {

	public const FORMATS = [ 'text', 'github' ];

	private string $format;

	private string $workspace;

	private ?string $summary_path;

	/**
	 * @var resource
	 */
	private $stdout;

	/**
	 * @var resource
	 */
	private $stderr;

	/**
	 * @param string      $format       Log format, text or github.
	 * @param string      $workspace    Directory that annotation files are relative to, like GITHUB_WORKSPACE.
	 * @param string|null $summary_path Job summary file, like GITHUB_STEP_SUMMARY.
	 * @param resource    $stdout       Stream of the informational messages.
	 * @param resource    $stderr       Stream of the errors.
	 */
	public function __construct( string $format = 'text', string $workspace = '', ?string $summary_path = null, $stdout = STDOUT, $stderr = STDERR ) {
		$this->format = $format;
		$this->workspace = $workspace;
		$this->summary_path = $summary_path;
		$this->stdout = $stdout;
		$this->stderr = $stderr;
	}

	public function info( string $message ): void {
		fwrite( $this->stdout, $message . "\n" );
	}

	/**
	 * Log an error, as an annotation on its file and line in the github format.
	 *
	 * @param array{file?: string, line?: int, title?: string} $annotation
	 */
	public function error( string $message, array $annotation = [] ): void {
		fwrite( $this->stderr, 'github' === $this->format ? $this->command( 'error', $message, $annotation ) : $message . "\n" );
	}

	/**
	 * Append Markdown to the job summary, with a blank line that keeps
	 * separate summaries apart.
	 */
	public function summary( string $markdown ): void {
		if ( 'github' === $this->format && null !== $this->summary_path && '' !== $this->summary_path ) {
			file_put_contents( $this->summary_path, rtrim( $markdown ) . "\n\n", FILE_APPEND | LOCK_EX );
		}
	}

	/**
	 * Log a failed command: each problem of a validation error on its file and
	 * line, or the message, and a summary of them, listing up to 100.
	 */
	public function failure( \Throwable $error, string $title ): void {
		$problems = $error instanceof Release_Exception ? $error->problems() : [];
		$markdown = "### {$title}\n\n";

		if ( [] === $problems ) {
			$this->error( $error->getMessage() );
			$markdown .= $error->getMessage();
		} else {
			$markdown .= "| Problem | File |\n| --- | --- |\n";

			foreach ( $problems as $index => $problem ) {
				$this->error( $problem['message'], $problem );
				$markdown .= $index < 100 ? $this->problem_row( $problem ) : '';
			}

			$markdown .= 100 < count( $problems ) ? sprintf( "\nAnd %d more.\n", count( $problems ) - 100 ) : '';
		}

		$this->summary( $markdown );
	}

	/**
	 * A path relative to the workspace with forward slashes, or null for a
	 * file outside it, which GitHub can't link.
	 */
	public function workspace_path( string $file ): ?string {
		$workspace = rtrim( str_replace( '\\', '/', $this->workspace ), '/' ) . '/';
		$path = str_replace( '\\', '/', $file );
		$relative = null;

		if ( '/' !== substr( $path, 0, 1 ) ) {
			$relative = $path;
		} elseif ( 0 === strpos( $path, $workspace ) ) {
			$relative = substr( $path, strlen( $workspace ) );
		}

		return $relative;
	}

	/**
	 * @param array{message: string, file?: string, line?: int} $problem
	 */
	private function problem_row( array $problem ): string {
		$file = isset( $problem['file'] ) ? $this->workspace_path( $problem['file'] ) : null;
		$location = null === $file ? '' : '`' . $file . '`' . ( isset( $problem['line'] ) ? ':' . $problem['line'] : '' );

		return '| ' . $this->cell( $problem['message'] ) . ' | ' . $location . " |\n";
	}

	private function cell( string $text ): string {
		return str_replace( [ '|', "\r\n", "\n" ], [ '\\|', '<br>', '<br>' ], $text );
	}

	/**
	 * Format a workflow command like
	 * `::error file=my-plugin.php,line=3,title=Version mismatch::message`.
	 *
	 * @param array{file?: string, line?: int, title?: string} $annotation
	 */
	private function command( string $name, string $message, array $annotation ): string {
		$file = isset( $annotation['file'] ) ? $this->workspace_path( $annotation['file'] ) : null;
		$properties = array_filter(
			[
				null === $file ? '' : 'file=' . $this->escape_property( $file ),
				null !== $file && isset( $annotation['line'] ) ? 'line=' . $annotation['line'] : '',
				isset( $annotation['title'] ) ? 'title=' . $this->escape_property( $annotation['title'] ) : '',
			],
			'strlen'
		);

		return '::' . $name . ( [] === $properties ? '' : ' ' . implode( ',', $properties ) ) . '::' . $this->escape_data( $message ) . "\n";
	}

	private function escape_data( string $text ): string {
		return str_replace( [ '%', "\r", "\n" ], [ '%25', '%0D', '%0A' ], $text );
	}

	private function escape_property( string $text ): string {
		return str_replace( [ ':', ',' ], [ '%3A', '%2C' ], $this->escape_data( $text ) );
	}
}
