<?php

declare(strict_types=1);

namespace WPElevator\PHP_Changesets;

final class Changeset_Reader {

	// Public parameter names are part of the API.
	// phpcs:disable WordPress.NamingConventions.ValidVariableName
	public function read( string $directory, string $projectName ): array {
		$project_name = $projectName;
		// phpcs:enable WordPress.NamingConventions.ValidVariableName
		$this->assert_safe_directory( $directory );
		if ( ! file_exists( $directory ) ) {
			return [];
		}
		$root = realpath( $directory );
		if ( false === $root || ! is_dir( $root ) || ! is_readable( $root ) ) {
			throw new Release_Exception( "Cannot read changeset directory: $directory" );
		}

		$names = scandir( $root );
		if ( false === $names ) {
			throw new Release_Exception( "Cannot list changeset directory: $directory" );
		}
		$records = [];
		foreach ( $names as $name ) {
			if ( 'README.md' !== $name && '.md' === substr( $name, -3 ) ) {
				$path = $root . DIRECTORY_SEPARATOR . $name;
				if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
					throw new Release_Exception( "Invalid changeset file: $path" );
				}
				$contents = file_get_contents( $path );
				if ( false === $contents ) {
					throw new Release_Exception( "Cannot read changeset file: $path" );
				}
				$records[] = $this->parse( $contents, $path, $name, $project_name );
			}
		}
		return $records;
	}

	private function assert_safe_directory( string $directory ): void {
		$path = 0 === strpos( $directory, DIRECTORY_SEPARATOR ) ? DIRECTORY_SEPARATOR : getcwd() . DIRECTORY_SEPARATOR;
		foreach ( explode( DIRECTORY_SEPARATOR, $directory ) as $part ) {
			if ( '' !== $part && '.' !== $part ) {
				$path .= $part . DIRECTORY_SEPARATOR;
				if ( is_link( rtrim( $path, DIRECTORY_SEPARATOR ) ) ) {
					throw new Release_Exception( "Changeset directory contains a symlink: $directory" );
				}
			}
		}
	}

	private function parse( string $contents, string $path, string $name, string $project_name ): array {
		$contents = preg_replace( '/^\xEF\xBB\xBF/', '', $contents );
		$lines = preg_split( '/\r\n|\n/', $contents );
		if ( false === $lines || '---' !== ( $lines[0] ?? '' ) ) {
			throw new Release_Exception( "Changeset $name must start with frontmatter." );
		}
		$closing = array_search( '---', array_slice( $lines, 1 ), true );
		if ( false === $closing ) {
			throw new Release_Exception( "Changeset $name is missing the closing frontmatter delimiter." );
		}
		++$closing;
		$bump = null;
		foreach ( array_slice( $lines, 1, $closing - 1 ) as $line ) {
			if ( '' !== trim( $line ) ) {
				if ( ! preg_match( '/^\s*(?:"((?:[^"\\\\]|\\\\")+)"|\'([^\']+)\'|([A-Za-z0-9_@.\/-]+))\s*:\s*(patch|minor|major)\s*$/D', $line, $matches ) ) {
					throw new Release_Exception( "Invalid package bump in changeset $name." );
				}
				$key = '' !== $matches[1] ? str_replace( '\\"', '"', $matches[1] ) : ( '' !== $matches[2] ? $matches[2] : $matches[3] );
				if ( $project_name !== $key || null !== $bump ) {
					throw new Release_Exception( "Unknown or duplicate package in changeset $name: $key" );
				}
				$bump = $matches[4];
			}
		}
		$summary = trim( implode( "\n", array_slice( $lines, $closing + 1 ) ) );
		if ( null !== $bump && '' === $summary ) {
			throw new Release_Exception( "Changeset $name requires a summary." );
		}
		return [
			'file' => $path,
			'name' => $name,
			'bump' => $bump,
			'summary' => $summary,
		];
	}
}
