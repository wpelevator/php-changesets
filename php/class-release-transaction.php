<?php

declare(strict_types=1);

namespace WPElevator\PHP_Changesets;

final class Release_Transaction {

	private const DIRECTORY = '.php-changesets-transaction';

	public static function has_pending( string $root ): bool {
		$directory = $root . '/' . self::DIRECTORY;
		if ( is_link( $directory ) ) {
			throw new Release_Exception( 'Transaction path is a symlink.' );
		}
		return is_dir( $directory );
	}

	public static function apply( array $edits, string $root ): void {
		$root = realpath( $root );
		if ( false === $root ) {
			throw new Release_Exception( 'Project root does not exist.' );
		}
		if ( self::has_pending( $root ) ) {
			throw new Release_Exception( 'An interrupted release is pending; run changesets recover.' );
		}
		$entries = self::preflight( $edits, $root );
		if ( ! $entries ) {
			return;
		}
		$directory = $root . '/' . self::DIRECTORY;
		if ( ! mkdir( $directory, 0700 ) ) {
			throw new Release_Exception( 'Could not create the release transaction directory.' );
		}
		try {
			self::write_exact( $directory . '/owner', 'php-changesets-v1', 0600 );
			$journal = json_encode( [ 'entries' => $entries ], JSON_THROW_ON_ERROR );
			self::write_exact( $directory . '/journal.tmp', $journal, 0600 );
			if ( ! rename( $directory . '/journal.tmp', $directory . '/journal.json' ) ) {
				throw new Release_Exception( 'Could not commit the transaction journal.' );
			}
			foreach ( $edits as $index => $edit ) {
				if ( null !== $edit['new'] ) {
					$mode = null === $entries[ $index ]['mode'] ? 0644 : $entries[ $index ]['mode'];
					self::write_exact( $directory . '/staged-' . $index, $edit['new'], $mode );
				}
			}
			foreach ( $edits as $index => $edit ) {
				$path = self::path( $root, $entries[ $index ]['path'] );
				$actual = is_file( $path ) ? file_get_contents( $path ) : null;
				if ( $actual !== $edit['old'] ) {
					throw new Release_Exception( "Release target changed during writing: {$path}." );
				}
				$success = null === $edit['new'] ? unlink( $path ) : rename( $directory . '/staged-' . $index, $path );
				if ( ! $success ) {
					throw new Release_Exception( "Could not write release target: {$path}." );
				}
			}
			self::write_exact( $directory . '/committed', 'complete', 0600 );
		} catch ( \Throwable $error ) {
			try {
				self::recover( $root );
			} catch ( \Throwable $recovery_error ) {
				throw new Release_Exception( 'Release failed and recovery needs attention: ' . $recovery_error->getMessage(), 0, $error );
			}
			throw new Release_Exception( 'Release failed; original files restored: ' . $error->getMessage(), 0, $error );
		}
		self::cleanup( $directory );
	}

	public static function recover( string $root ): bool {
		$root = realpath( $root );
		if ( false === $root ) {
			throw new Release_Exception( 'Project root does not exist.' );
		}
		if ( ! self::has_pending( $root ) ) {
			return false;
		}
		$directory = $root . '/' . self::DIRECTORY;
		if ( ! is_file( $directory . '/owner' ) || 'php-changesets-v1' !== file_get_contents( $directory . '/owner' ) ) {
			throw new Release_Exception( 'Transaction directory is not owned by PHP Changesets; manual review is required.' );
		}
		if ( ! is_file( $directory . '/committed' ) && is_file( $directory . '/journal.json' ) ) {
			$journal = json_decode( (string) file_get_contents( $directory . '/journal.json' ), true );
			if ( ! is_array( $journal ) || ! isset( $journal['entries'] ) || ! is_array( $journal['entries'] ) ) {
				throw new Release_Exception( 'Transaction journal is invalid; manual recovery is required.' );
			}
			$failures = [];
			foreach ( $journal['entries'] as $index => $entry ) {
				try {
					self::restore_entry( $root, $directory, $index, $entry );
				} catch ( \Throwable $error ) {
					$failures[] = ( $entry['path'] ?? "entry {$index}" ) . ': ' . $error->getMessage();
				}
			}
			if ( $failures ) {
				throw new Release_Exception( 'Could not restore: ' . implode( '; ', $failures ) );
			}
		}
		self::cleanup( $directory );
		return true;
	}

	private static function preflight( array $edits, string $root ): array {
		$entries = [];
		$seen = [];
		foreach ( $edits as $edit ) {
			$path = $edit['path'] ?? null;
			if ( ! is_string( $path ) || 0 !== strpos( $path, $root . '/' ) || isset( $seen[ $path ] ) || is_link( $path ) || is_dir( $path ) ) {
				throw new Release_Exception( 'Invalid, duplicate, or unsafe release target.' );
			}
			$relative = substr( $path, strlen( $root ) + 1 );
			self::path( $root, $relative );
			$seen[ $path ] = true;
			$actual = is_file( $path ) ? file_get_contents( $path ) : null;
			$old = $edit['old'] ?? null;
			$new = $edit['new'] ?? null;
			if ( $actual !== $old || ( null === $old && null === $new ) || ! is_dir( dirname( $path ) ) ) {
				throw new Release_Exception( "Release target changed since planning: {$path}." );
			}
			$entries[] = [
				'path' => $relative,
				'old' => null === $old ? null : base64_encode( $old ),
				'newHash' => null === $new ? null : hash( 'sha256', $new ),
				'mode' => is_file( $path ) ? ( fileperms( $path ) & 0777 ) : null,
			];
		}
		return $entries;
	}

	private static function restore_entry( string $root, string $directory, int $index, array $entry ): void {
		if ( ! isset( $entry['path'] ) || ! is_string( $entry['path'] ) || ! array_key_exists( 'old', $entry ) || ! array_key_exists( 'newHash', $entry ) ) {
			throw new Release_Exception( 'Journal entry is invalid.' );
		}
		$path = self::path( $root, $entry['path'] );
		$old = null === $entry['old'] ? null : base64_decode( $entry['old'], true );
		if ( false === $old ) {
			throw new Release_Exception( 'Journal backup is invalid.' );
		}
		$actual = is_file( $path ) ? file_get_contents( $path ) : null;
		if ( $actual === $old ) {
			return;
		}
		$expected_new = null === $entry['newHash'] ? null === $actual : ( is_string( $actual ) && hash_equals( $entry['newHash'], hash( 'sha256', $actual ) ) );
		if ( ! $expected_new ) {
			throw new Release_Exception( 'Target differs from both the original and planned content.' );
		}
		if ( null === $old ) {
			if ( ! unlink( $path ) ) {
				throw new Release_Exception( 'Could not remove the new file.' );
			}
		} else {
			$mode = isset( $entry['mode'] ) && is_int( $entry['mode'] ) ? $entry['mode'] : 0644;
			$backup = $directory . '/restore-' . $index . '-' . bin2hex( random_bytes( 8 ) );
			self::write_exact( $backup, $old, $mode );
			if ( ! rename( $backup, $path ) ) {
				throw new Release_Exception( 'Could not restore the original file.' );
			}
		}
	}

	private static function path( string $root, string $relative ): string {
		return Release_Engine::safe_path( $root, $relative );
	}

	private static function write_exact( string $file, string $contents, int $mode ): void {
		$handle = fopen( $file, 'x' );
		if ( false === $handle ) {
			throw new Release_Exception( "Could not create transaction file: {$file}." );
		}
		try {
			$written = 0;
			$length = strlen( $contents );
			while ( $written < $length ) {
				$count = fwrite( $handle, substr( $contents, $written ) );
				if ( false === $count || 0 === $count ) {
					throw new Release_Exception( "Short write in transaction file: {$file}." );
				}
				$written += $count;
			}
			// phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.fsyncFound -- Guarded with function_exists() for PHP 7.4 support; fsync() is only available since PHP 8.1.
			if ( ! fflush( $handle ) || ( function_exists( 'fsync' ) && ! fsync( $handle ) ) ) {
				throw new Release_Exception( "Could not flush transaction file: {$file}." );
			}
		} finally {
			fclose( $handle );
		}
		if ( ! chmod( $file, $mode ) ) {
			throw new Release_Exception( "Could not set transaction file permissions: {$file}." );
		}
	}

	private static function cleanup( string $directory ): void {
		$files = scandir( $directory );
		if ( false === $files ) {
			throw new Release_Exception( 'Could not list the release transaction directory.' );
		}
		foreach ( $files as $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$path = $directory . '/' . $name;
				if ( is_dir( $path ) && ! is_link( $path ) || ! unlink( $path ) ) {
					throw new Release_Exception( "Could not remove transaction file: {$path}." );
				}
			}
		}
		if ( ! rmdir( $directory ) ) {
			throw new Release_Exception( 'Could not remove the release transaction directory.' );
		}
	}
}
