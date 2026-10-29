<?php

namespace WPElevator\PHP_Changesets;

/**
 * Read and format the supported multi-package changeset format, from a safe directory.
 *
 * Frontmatter is a mapping of package name to patch|minor|major bump; one
 * changeset may name several packages, and empty frontmatter records an
 * explicit no-release changeset that is consumed for every package.
 */
final class Changeset_Reader {

	/**
	 * Read changesets ordered by filename, validating package names against
	 * the discovered packages.
	 *
	 * @param string[] $package_names Discovered package names.
	 * @return array<int, array{file: string, name: string, packages: array<string, string>, summary: string}>
	 */
	public function read( string $directory, array $package_names ): array {
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
			// Every Markdown file is a changeset except the reserved README ones.
			if ( '.md' === substr( $name, -3 ) && 'README' !== substr( $name, 0, 6 ) ) {
				$path = $root . DIRECTORY_SEPARATOR . $name;

				if ( is_link( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
					throw new Release_Exception( "Invalid changeset file: $path" );
				}

				$contents = file_get_contents( $path );

				if ( false === $contents ) {
					throw new Release_Exception( "Cannot read changeset file: $path" );
				}

				$records[] = $this->parse( $contents, $path, $name, $package_names );
			}
		}

		return $records;
	}

	/**
	 * Format a changeset file, like the one that `changeset add` writes.
	 *
	 * @param array<string, string> $packages Package name to bump, empty for no release.
	 */
	public function format( array $packages, string $summary ): string {
		$lines = [];

		foreach ( $packages as $package => $bump ) {
			$lines[] = json_encode( (string) $package, JSON_UNESCAPED_SLASHES ) . ': ' . $bump . "\n";
		}

		return "---\n" . implode( '', $lines ) . "---\n\n" . trim( $summary ) . "\n";
	}

	/**
	 * @param string[] $package_names
	 * @return array{file: string, name: string, packages: array<string, string>, summary: string}
	 */
	private function parse( string $contents, string $path, string $name, array $package_names ): array {
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
		$packages = [];

		foreach ( array_slice( $lines, 1, $closing - 1 ) as $line ) {
			if ( '' !== trim( $line ) ) {
				if ( ! preg_match( '/^\s*(?:"((?:[^"\\\\]|\\\\.)+)"|\'([^\']+)\'|([A-Za-z0-9_@.\/-]+))\s*:\s*(patch|minor|major)\s*$/D', $line, $matches ) ) {
					throw new Release_Exception( "Invalid package bump in changeset $name." );
				}

				$key = '' !== $matches[1] ? str_replace( '\\"', '"', $matches[1] ) : ( '' !== $matches[2] ? $matches[2] : $matches[3] );

				if ( isset( $packages[ $key ] ) ) {
					throw new Release_Exception( "Duplicate package in changeset $name: $key" );
				}

				if ( ! in_array( $key, $package_names, true ) ) {
					throw new Release_Exception( "Unknown package in changeset $name: $key" );
				}

				$packages[ $key ] = $matches[4];
			}
		}

		$summary = trim( implode( "\n", array_slice( $lines, $closing + 1 ) ) );

		if ( [] !== $packages && '' === $summary ) {
			throw new Release_Exception( "Changeset $name requires a summary." );
		}

		return [
			'file' => $path,
			'name' => $name,
			'packages' => $packages,
			'summary' => $summary,
		];
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
}
