<?php

namespace WPElevator\PHP_Changesets;

/**
 * A validation error, like invalid options or a failed release check, that
 * exits with code 2. It can list problems with the file and line they're on,
 * like the wp-release `ValidationError`, for annotations.
 */
final class Release_Exception extends \RuntimeException {

	/**
	 * @var array<int, array{message: string, file?: string, line?: int, title?: string}>
	 */
	private array $problems;

	/**
	 * @param array<int, array{message: string, file?: string, line?: int, title?: string}> $problems
	 */
	public function __construct( string $message = '', int $code = 0, ?\Throwable $previous = null, array $problems = [] ) {
		parent::__construct( $message, $code, $previous );
		$this->problems = $problems;
	}

	/**
	 * @return array<int, array{message: string, file?: string, line?: int, title?: string}>
	 */
	public function problems(): array {
		return $this->problems;
	}
}
