<?php

declare(strict_types=1);

namespace WPElevator\PHP_Changesets;

/** Reads and proposes bounded edits to version sources and targets. */
final class Version_Files {

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Public API.
	public static function readCurrent( string $root, array $source ): string {
		if ( ( $source['kind'] ?? '' ) === 'git-tag' ) {
			$pattern = $source['pattern'] ?? 'v{version}';
			if ( ! is_string( $pattern ) || substr_count( $pattern, '{version}' ) !== 1 ) {
				throw new Release_Exception( 'A tag pattern must contain {version} exactly once.' );
			}
			$initial_version = $source['initialVersion'] ?? null;
			if ( array_key_exists( 'initialVersion', $source ) && ( ! is_string( $initial_version ) || ! preg_match( '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $initial_version ) ) ) {
				throw new Release_Exception( 'Initial version must be a stable X.Y.Z version.' );
			}
			$parts = explode( '{version}', $pattern );
			$expression = '/^' . preg_quote( $parts[0], '/' ) . '(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)' . preg_quote( $parts[1], '/' ) . '$/D';
			$versions = array();
			foreach ( self::tags( $root ) as $tag ) {
				if ( preg_match( $expression, $tag, $matches ) ) {
					$versions[] = $matches[1] . '.' . $matches[2] . '.' . $matches[3];
				}
			}
			usort( $versions, 'version_compare' );
			if ( count( $versions ) === 0 ) {
				if ( null !== $initial_version ) {
					return $initial_version;
				}
				throw new Release_Exception( 'No stable version tag matches ' . $pattern . '.' );
			}
			return $versions[ count( $versions ) - 1 ];
		}
		$file = self::file( $root, $source, false );
		$contents = self::contents( $file );
		$match = self::locate( $contents, $source );
		if ( null === $match ) {
			throw new Release_Exception( 'Version is missing from ' . $file . '.' );
		}
		return $match['value'];
	}

	// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Public API argument names.
	public static function propose( string $root, array $target, string $oldVersion, string $newVersion, ?string $contentsOverride = null ): ?array {
		$kind = $target['kind'] ?? '';
		$optional = ! empty( $target['ifPresent'] ) && in_array( $kind, array( 'composer-json', 'wordpress-stable-tag' ), true );
		$file = self::file( $root, $target, $optional && 'wordpress-stable-tag' === $kind );
		if ( null === $file ) {
			return null;
		}
		$contents = $contentsOverride ?? self::contents( $file );
		$match = self::locate( $contents, $target );
		if ( null === $match && $optional ) {
			return null;
		}
		if ( null === $match || $match['value'] !== $oldVersion ) {
			throw new Release_Exception( 'Version in ' . $file . ' does not match ' . $oldVersion . '.' );
		}
		if ( $oldVersion === $newVersion ) {
			return array(
				'path' => $file,
				'old' => $contents,
				'new' => $contents,
			);
		}
		if ( 'composer-json' === $kind ) {
			$replacement = json_encode( $newVersion, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		} else {
			$replacement = $newVersion;
		}
		$updated = substr_replace( $contents, $replacement, $match['offset'], $match['length'] );
		if ( 'callback' === $kind ) {
			if ( isset( $target['replace'] ) ) {
				if ( ! is_callable( $target['replace'] ) ) {
					throw new Release_Exception( 'Version replacement callback must be callable.' );
				}
				try {
					$updated = $target['replace']( $contents, $oldVersion, $newVersion );
				} catch ( \Throwable $error ) {
					throw new Release_Exception( 'Version replacement callback failed.', 0, $error );
				}
				if ( ! is_string( $updated ) ) {
					throw new Release_Exception( 'Version replacement callback must return file contents as a string.' );
				}
			}
			$resolved = self::locate( $updated, $target );
			if ( null === $resolved || $resolved['value'] !== $newVersion ) {
				throw new Release_Exception( 'Version resolver must find the new version after replacement.' );
			}
		}
		return array(
			'path' => $file,
			'old' => $contents,
			'new' => $updated,
		);
	}

	// phpcs:enable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Public API.
	public static function tagExists( string $root, string $tag ): bool {
		return in_array( $tag, self::tags( $root ), true );
	}

	private static function tags( string $root ): array {
		$process = proc_open(
			array( 'git', '-C', $root, 'tag', '--list' ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
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
		if ( proc_close( $process ) !== 0 ) {
			throw new Release_Exception( 'Unable to list git tags: ' . trim( $error ) );
		}
		return '' === $output ? array() : explode( "\n", rtrim( $output, "\n" ) );
	}

	private static function file( string $root, array $descriptor, bool $optional ): ?string {
		$base = realpath( $root );
		$name = $descriptor['file'] ?? null;
		if ( false === $base || ! is_string( $name ) || '' === $name || '/' === $name[0] || false !== strpos( $name, "\0" ) ) {
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

	private static function contents( string $file ): string {
		$contents = file_get_contents( $file );
		if ( false === $contents ) {
			throw new Release_Exception( 'Unable to read ' . $file . '.' );
		}
		return $contents;
	}

	/** @return array{value:string,offset:int,length:int}|null */
	private static function locate( string $contents, array $descriptor ): ?array {
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
			if ( ! is_array( $match ) || ! is_string( $match['value'] ?? null ) || '' === $match['value'] || ! is_int( $match['offset'] ?? null ) || ! is_int( $match['length'] ?? null ) || 0 > $match['offset'] || strlen( $match['value'] ) !== $match['length'] || substr( $contents, $match['offset'], $match['length'] ) !== $match['value'] ) {
				throw new Release_Exception( 'Version resolver must return an exact value, offset, and length inside the file.' );
			}
			return $match;
		}
		if ( 'composer-json' === $kind ) {
			return self::json_version( $contents );
		}
		if ( 'wordpress-plugin-header' === $kind || 'wordpress-stable-tag' === $kind ) {
			$label = 'wordpress-stable-tag' === $kind ? 'Stable tag' : 'Version';
			$expression = '/^[\t ]*(?:(?:\/\/|\/\*|\*)[\t ]*)?' . preg_quote( $label, '/' ) . ':[\t ]*\K[^\s*]+/mi';
		} elseif ( 'php-constant' === $kind ) {
			$constant = $descriptor['constant'] ?? '';
			if ( ! is_string( $constant ) || ! preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $constant ) ) {
				throw new Release_Exception( 'Invalid PHP constant name.' );
			}
			$name = preg_quote( $constant, '/' );
			$expression = '/(?:\bdefine\s*\(\s*([\'"])' . $name . '\1\s*,\s*|\bconst\s+' . $name . '\s*=\s*)([\'"])([^\'"\r\n]+)\2/i';
			$starts = array();
			$offset = 0;
			foreach ( token_get_all( $contents ) as $token ) {
				$text = is_array( $token ) ? $token[1] : $token;
				if ( is_array( $token ) && ( T_CONST === $token[0] || ( T_STRING === $token[0] && 'define' === strtolower( $text ) ) ) ) {
					$starts[ $offset ] = true;
				}
				$offset += strlen( $text );
			}
			preg_match_all( $expression, $contents, $matches, PREG_OFFSET_CAPTURE );
			$versions = array();
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
			return array(
				'value' => $versions[0][0],
				'offset' => $versions[0][1],
				'length' => strlen( $versions[0][0] ),
			);
		} else {
			throw new Release_Exception( 'Unsupported version descriptor: ' . $kind );
		}
		preg_match_all( $expression, $contents, $matches, PREG_OFFSET_CAPTURE );
		if ( count( $matches[0] ) > 1 ) {
			throw new Release_Exception( 'Ambiguous version target.' );
		}
		if ( count( $matches[0] ) === 0 ) {
			return null;
		}
		return array(
			'value' => $matches[0][0][0],
			'offset' => $matches[0][0][1],
			'length' => strlen( $matches[0][0][0] ),
		);
	}

	private static function json_version( string $contents ): ?array {
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
		$found = array();
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
				$found[] = array(
					'value' => json_decode( $value[0] ),
					'offset' => $value[1],
					'length' => strlen( $value[0] ),
				);
			}
		}
		if ( count( $found ) > 1 ) {
			throw new Release_Exception( 'Ambiguous composer version.' );
		}
		return $found[0] ?? null;
	}
}
