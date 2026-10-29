<?php

namespace WPElevator\PHP_Changesets;

/**
 * Locate files within one canonical project root.
 */
final class Project_Files {

	private string $root;

	public function __construct( string $root ) {
		$resolved = realpath( $root );

		if ( false === $resolved || ! is_dir( $resolved ) ) {
			throw new Release_Exception( 'Project root does not exist.' );
		}

		$this->root = $resolved;
	}

	public function get_root(): string {
		return $this->root;
	}

	/**
	 * Resolve a relative path without allowing traversal or symlink escapes.
	 */
	public function resolve( string $relative ): string {
		if ( '' === $relative || 0 === strpos( $relative, '/' ) || false !== strpos( $relative, "\0" ) ) {
			throw new Release_Exception( "Path must be relative to the project: {$relative}." );
		}

		$parts = explode( '/', str_replace( '\\', '/', $relative ) );

		if ( in_array( '..', $parts, true ) ) {
			throw new Release_Exception( "Path escapes the project: {$relative}." );
		}

		$path = $this->root . '/' . implode( '/', $parts );
		$ancestor = $path;

		while ( ! file_exists( $ancestor ) && ! is_link( $ancestor ) && $ancestor !== $this->root ) {
			$ancestor = dirname( $ancestor );
		}

		$resolved = realpath( $ancestor );

		if ( false === $resolved || ( $resolved !== $this->root && 0 !== strpos( $resolved, $this->root . '/' ) ) ) {
			throw new Release_Exception( "Path escapes the project: {$relative}." );
		}

		if ( is_link( $path ) ) {
			throw new Release_Exception( "Symlink target is not allowed: {$relative}." );
		}

		return $path;
	}
}
