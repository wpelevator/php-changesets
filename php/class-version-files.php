<?php

namespace WPElevator\PHP_Changesets;

/**
 * Read versions and propose bounded edits to version sources and targets.
 */
final class Version_Files {

	private Project_Files $files;

	private Semver $semver;

	private Tag_Format $tag_format;

	public function __construct( Project_Files $files ) {
		$this->files = $files;
		$this->semver = new Semver();
		$this->tag_format = new Tag_Format();
	}

	public function read_current( array $source, ?array $package = null ): string {
		if ( ( $source['kind'] ?? '' ) === 'git-tag' ) {
			$pattern = $this->package_pattern( $source['pattern'] ?? Tag_Format::DEFAULT, $package );

			if ( 1 !== substr_count( $pattern, '{version}' ) ) {
				throw new Release_Exception( 'A tag pattern must contain {version} exactly once.' );
			}

			$initial_version = $source['initialVersion'] ?? null;

			if (
				array_key_exists( 'initialVersion', $source )
				&& ( ! is_string( $initial_version ) || ! $this->semver->is_valid( $initial_version ) || $this->semver->is_pre_release( $initial_version ) )
			) {
				throw new Release_Exception( 'Initial version must be a stable version.' );
			}

			$parts = explode( '{version}', $pattern );
			// The version span may carry a semver pre-release; build metadata never counts.
			$expression = '/^' . preg_quote( $parts[0], '/' ) . '((?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*)){1,3}(?:-(?:[0-9A-Za-z-]+)(?:\.[0-9A-Za-z-]+)*)?)' . preg_quote( $parts[1], '/' ) . '$/D';
			$versions = [];

			foreach ( $this->tags() as $tag ) {
				if ( preg_match( $expression, $tag, $matches ) ) {
					$versions[] = $matches[1];
				}
			}

			usort( $versions, fn( string $a, string $b ): int => $this->semver->compare( $a, $b ) );

			if ( 0 === count( $versions ) ) {
				if ( null !== $initial_version ) {
					return $initial_version;
				}

				throw new Release_Exception(
					sprintf(
						'No version tag matches %1$s. Tag the current release, like git tag %2$s, or set the version before the first release with git_tag_version( null, \'0.1.0\' ).',
						$pattern,
						str_replace( '{version}', '0.1.0', $pattern )
					)
				);
			}

			return $versions[ count( $versions ) - 1 ];
		}

		$file = $this->file( $source, false );
		$contents = $this->contents( $file );
		$match = $this->locate( $contents, $source );

		if ( null === $match ) {
			throw new Release_Exception( 'Version is missing from ' . $file . '.' );
		}

		return $match['value'];
	}

	/**
	 * Read a target's current version like the preflight reads optional
	 * targets: an absent file or a missing optional value on an `ifPresent`
	 * `wordpress-stable-tag` target reads null, so a stable release of a
	 * pre-release current version skips its planned edit instead of failing
	 * on the optional readme.
	 *
	 * @return string|null The current version, or null for an absent optional target.
	 */
	public function read_optional_current( array $source, ?array $package = null ): ?string {
		if ( ( $source['kind'] ?? '' ) === 'git-tag' ) {
			return $this->read_current( $source, $package );
		}

		$optional = ! empty( $source['ifPresent'] ) && 'wordpress-stable-tag' === ( $source['kind'] ?? '' );
		$file = $this->file( $source, $optional );

		if ( null === $file ) {
			return null;
		}

		$contents = $this->contents( $file );
		$match = $this->locate( $contents, $source );

		if ( null === $match ) {
			if ( $optional ) {
				return null;
			}

			throw new Release_Exception( 'Version is missing from ' . $file . '.' );
		}

		return $match['value'];
	}

	/**
	 * Propose a version edit without writing it.
	 *
	 * A contents override composes another descriptor's proposal for the same
	 * file. Null coalescing is intentional so an empty-string override remains
	 * valid input.
	 *
	 * @return array{path: string, old: string, new: string}|null
	 */
	public function propose( array $target, string $old_version, string $new_version, ?string $contents_override = null ): ?array {
		$kind = $target['kind'] ?? '';
		$optional = ! empty( $target['ifPresent'] ) && in_array( $kind, [ 'composer-json', 'wordpress-stable-tag' ], true );
		$file = $this->file( $target, $optional && 'wordpress-stable-tag' === $kind );

		if ( null === $file ) {
			return null;
		}

		$contents = $contents_override ?? $this->contents( $file );
		$match = $this->locate( $contents, $target );

		if ( null === $match && $optional ) {
			return null;
		}

		if ( null === $match || $match['value'] !== $old_version ) {
			throw new Release_Exception( 'Version in ' . $file . ' does not match ' . $old_version . '.' );
		}

		if ( $old_version === $new_version ) {
			return [
				'path' => $file,
				'old' => $contents,
				'new' => $contents,
			];
		}

		if ( 'composer-json' === $kind ) {
			$replacement = json_encode( $new_version, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} else {
			$replacement = $new_version;
		}

		$updated = substr_replace( $contents, $replacement, $match['offset'], $match['length'] );

		if ( 'callback' === $kind ) {
			if ( isset( $target['replace'] ) ) {
				if ( ! is_callable( $target['replace'] ) ) {
					throw new Release_Exception( 'Version replacement callback must be callable.' );
				}

				try {
					$updated = $target['replace']( $contents, $old_version, $new_version );
				} catch ( \Throwable $error ) {
					throw new Release_Exception( 'Version replacement callback failed.', 0, $error );
				}

				if ( ! is_string( $updated ) ) {
					throw new Release_Exception( 'Version replacement callback must return file contents as a string.' );
				}
			}

			$resolved = $this->locate( $updated, $target );

			if ( null === $resolved || $resolved['value'] !== $new_version ) {
				throw new Release_Exception( 'Version resolver must find the new version after replacement.' );
			}
		}

		return [
			'path' => $file,
			'old' => $contents,
			'new' => $updated,
		];
	}

	public function tag_exists( string $tag ): bool {
		return in_array( $tag, $this->tags(), true );
	}

	/**
	 * The version spans of the tags sharing a prefix, like the tags of one
	 * package under its expanded tag format.
	 *
	 * @return string[]
	 */
	public function tagged_versions( string $prefix ): array {
		$versions = [];

		foreach ( $this->tags() as $tag ) {
			if ( 0 === strpos( $tag, $prefix ) ) {
				$versions[] = substr( $tag, strlen( $prefix ) );
			}
		}

		return $versions;
	}

	/**
	 * Expand the {name} and {path} placeholders of a git tag pattern with the
	 * package you're reading the version for, keeping {version} for the regex.
	 * Without a package, the pattern expands like a package at the root.
	 */
	private function package_pattern( $pattern, ?array $package ): string {
		if ( ! is_string( $pattern ) ) {
			throw new Release_Exception( 'A tag pattern must be a string.' );
		}

		$package = $package ?? [
			'name' => '',
			'path' => '.',
		];

		return $this->tag_format->format( $pattern, $package, '{version}' );
	}

	/**
	 * @return string[] The git tags of the canonical project root.
	 */
	private function tags(): array {
		$root = $this->files->get_root();
		$process = proc_open(
			[ 'git', '-C', $root, 'tag', '--list' ],
			[
				0 => [ 'pipe', 'r' ],
				1 => [ 'pipe', 'w' ],
				2 => [ 'pipe', 'w' ],
			],
			$pipes
		);

		if ( ! is_resource( $process ) ) {
			throw new Release_Exception( 'Unable to run git.' );
		}

		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		if ( 0 !== proc_close( $process ) ) {
			throw new Release_Exception( 'Unable to list git tags: ' . trim( $error ) );
		}

		return '' === $output ? [] : explode( "\n", rtrim( $output, "\n" ) );
	}

	private function file( array $descriptor, bool $optional ): ?string {
		$base = $this->files->get_root();
		$name = $descriptor['file'] ?? null;

		// The theme stylesheet header resolves to style.css without a file setting,
		// like the readme Stable tag resolves to readme.txt.
		if ( 'wordpress-theme-header' === ( $descriptor['kind'] ?? '' ) && null === $name ) {
			$name = 'style.css';
		}

		if ( ! is_string( $name ) || '' === $name || '/' === $name[0] || false !== strpos( $name, "\0" ) ) {
			throw new Release_Exception( 'Invalid version file path.' );
		}

		$path = realpath( $base . '/' . $name );

		if ( false === $path && $optional && ! file_exists( $base . '/' . $name ) && ! is_link( $base . '/' . $name ) ) {
			// Check the nearest existing ancestor to reject missing paths through symlinks.
			$ancestor = dirname( $base . '/' . $name );

			while ( ! file_exists( $ancestor ) && ! is_link( $ancestor ) && dirname( $ancestor ) !== $ancestor ) {
				$ancestor = dirname( $ancestor );
			}

			$path = realpath( $ancestor );

			if ( false !== $path && ( $path === $base || 0 === strpos( $path, $base . DIRECTORY_SEPARATOR ) ) ) {
				return null;
			}
		}

		if ( false === $path || 0 !== strpos( $path, $base . DIRECTORY_SEPARATOR ) || ! is_file( $path ) ) {
			throw new Release_Exception( 'Version file is missing or outside the project root: ' . $name );
		}

		return $path;
	}

	private function contents( string $file ): string {
		$contents = file_get_contents( $file );

		if ( false === $contents ) {
			throw new Release_Exception( 'Unable to read ' . $file . '.' );
		}

		return $contents;
	}

	/**
	 * Locate the exact byte span of a version in a descriptor's contents.
	 *
	 * @return array{value: string, offset: int, length: int}|null
	 */
	private function locate( string $contents, array $descriptor ): ?array {
		$kind = $descriptor['kind'] ?? '';

		if ( 'callback' === $kind ) {
			$resolver = $descriptor['resolve'] ?? null;

			if ( ! is_callable( $resolver ) ) {
				throw new Release_Exception( 'A callback version descriptor requires a resolve callable.' );
			}

			try {
				$match = $resolver( $contents );
			} catch ( \Throwable $error ) {
				throw new Release_Exception( 'Version resolver failed.', 0, $error );
			}

			if ( null === $match ) {
				return null;
			}

			if (
				! is_array( $match )
				|| ! is_string( $match['value'] ?? null )
				|| '' === $match['value']
				|| ! is_int( $match['offset'] ?? null )
				|| ! is_int( $match['length'] ?? null )
				|| 0 > $match['offset']
				|| strlen( $match['value'] ) !== $match['length']
				|| substr( $contents, $match['offset'], $match['length'] ) !== $match['value']
			) {
				throw new Release_Exception( 'Version resolver must return an exact value, offset, and length inside the file.' );
			}

			return $match;
		}

		if ( 'composer-json' === $kind ) {
			return $this->json_version( $contents );
		}

		if ( in_array( $kind, [ 'wordpress-plugin-header', 'wordpress-theme-header', 'wordpress-stable-tag' ], true ) ) {
			$label = 'wordpress-stable-tag' === $kind ? 'Stable tag' : 'Version';
			$expression = '/^[\t ]*(?:(?:\/\/|\/\*|\*)[\t ]*)?' . preg_quote( $label, '/' ) . ':[\t ]*\K[^\s*]+/mi';
		} elseif ( 'php-constant' === $kind ) {
			$constant = $descriptor['constant'] ?? '';

			if ( ! is_string( $constant ) || ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $constant ) ) {
				throw new Release_Exception( 'Invalid PHP constant name.' );
			}

			$name = preg_quote( $constant, '/' );
			$expression = '/(?:\bdefine\s*\(\s*([\'"])' . $name . '\1\s*,\s*|\bconst\s+' . $name . '\s*=\s*)([\'"])([^\'"\r\n]+)\2/i';
			$starts = [];
			$offset = 0;

			foreach ( token_get_all( $contents ) as $token ) {
				$text = is_array( $token ) ? $token[1] : $token;

				if ( is_array( $token ) && ( T_CONST === $token[0] || ( T_STRING === $token[0] && 'define' === strtolower( $text ) ) ) ) {
					$starts[ $offset ] = true;
				}

				$offset += strlen( $text );
			}

			preg_match_all( $expression, $contents, $matches, PREG_OFFSET_CAPTURE );

			$versions = [];

			foreach ( $matches[0] as $index => $match ) {
				if ( isset( $starts[ $match[1] ] ) ) {
					$versions[] = $matches[3][ $index ];
				}
			}

			if ( count( $versions ) > 1 ) {
				throw new Release_Exception( 'Ambiguous version target.' );
			}

			if ( 0 === count( $versions ) ) {
				return null;
			}

			return [
				'value' => $versions[0][0],
				'offset' => $versions[0][1],
				'length' => strlen( $versions[0][0] ),
			];
		} else {
			throw new Release_Exception( 'Unsupported version descriptor: ' . $kind );
		}

		preg_match_all( $expression, $contents, $matches, PREG_OFFSET_CAPTURE );

		if ( count( $matches[0] ) > 1 ) {
			throw new Release_Exception( 'Ambiguous version target.' );
		}

		if ( 0 === count( $matches[0] ) ) {
			return null;
		}

		return [
			'value' => $matches[0][0][0],
			'offset' => $matches[0][0][1],
			'length' => strlen( $matches[0][0][0] ),
		];
	}

	/**
	 * Locate only the top-level Composer version while preserving its JSON span.
	 *
	 * @return array{value: string, offset: int, length: int}|null
	 */
	private function json_version( string $contents ): ?array {
		try {
			$object = json_decode( $contents, false, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			throw new Release_Exception( 'Invalid composer JSON.', 0, $error );
		}

		if ( ! is_object( $object ) ) {
			throw new Release_Exception( 'Composer JSON must be an object.' );
		}

		preg_match_all( '/"(?:\\\\.|[^"\\\\])*"|[{}\[\]:,]|[^\s{}\[\]:,]+/', $contents, $tokens, PREG_OFFSET_CAPTURE );

		$depth = 0;
		$found = [];

		foreach ( $tokens[0] as $index => $token ) {
			if ( '{' === $token[0] || '[' === $token[0] ) {
				++$depth;
			} elseif ( '}' === $token[0] || ']' === $token[0] ) {
				--$depth;
			} elseif ( 1 === $depth && '"' === $token[0][0] && 'version' === json_decode( $token[0] ) && ':' === ( $tokens[0][ $index + 1 ][0] ?? '' ) ) {
				$value = $tokens[0][ $index + 2 ] ?? null;

				if ( null === $value || '"' !== $value[0][0] || ! is_string( json_decode( $value[0] ) ) ) {
					throw new Release_Exception( 'Composer version must be a string.' );
				}

				$found[] = [
					'value' => json_decode( $value[0] ),
					'offset' => $value[1],
					'length' => strlen( $value[0] ),
				];
			}
		}

		if ( count( $found ) > 1 ) {
			throw new Release_Exception( 'Ambiguous composer version.' );
		}

		return $found[0] ?? null;
	}
}
