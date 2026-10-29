<?php

declare(strict_types=1);

namespace WPElevator\PHP_Changesets;

final class Project_Config {
	private const PHP_CONFIG_PATHS = [ '.changeset/config.php', 'config/changesets.php' ];

	public static function has_config( string $root ): bool {
		foreach ( self::PHP_CONFIG_PATHS as $path ) {
			$file = $root . '/' . $path;
			if ( file_exists( $file ) || is_link( $file ) ) {
				return true;
			}
		}
		return is_file( $root . '/.php-changesets.json' ) || is_link( $root . '/.php-changesets.json' );
	}

	public static function load( string $root ): array {
		$json_file = $root . '/.php-changesets.json';
		$php_files = array_values( array_filter( array_map( static fn( string $path ): string => $root . '/' . $path, self::PHP_CONFIG_PATHS ), static fn( string $file ): bool => file_exists( $file ) || is_link( $file ) ) );
		$php_file = $php_files[0] ?? null;
		if ( count( $php_files ) + ( ( is_file( $json_file ) || is_link( $json_file ) ) ? 1 : 0 ) > 1 ) {
			throw new Release_Exception( 'Use only one PHP Changesets config file.' );
		}
		if ( null !== $php_file ) {
			if ( is_link( $php_file ) || ! is_file( $php_file ) ) {
				throw new Release_Exception( 'The PHP Changesets config must be a regular file.' );
			}
			try {
				$config = ( static function ( string $file ) {
					return require $file;
				} )( $php_file );
			} catch ( \Throwable $error ) {
				throw new Release_Exception( 'Could not load PHP Changesets config.', 0, $error );
			}
			if ( ! is_array( $config ) ) {
				throw new Release_Exception( 'PHP Changesets config must return an array.' );
			}
		} elseif ( is_file( $json_file ) ) {
			$config = json_decode( (string) file_get_contents( $json_file ), true );
			if ( ! is_array( $config ) ) {
				throw new Release_Exception( 'Invalid .php-changesets.json.' );
			}
		} else {
			$config = self::infer( $root );
		}

		$name = $config['name'] ?? null;
		$version = $config['version'] ?? null;
		$source = is_array( $version ) ? ( $version['from'] ?? null ) : null;
		$targets = is_array( $version ) ? ( $version['to'] ?? null ) : null;
		if ( ! is_string( $name ) || '' === trim( $name ) || ! is_array( $source ) || ! is_array( $targets ) ) {
			throw new Release_Exception( 'Configuration requires name, version.from, and version.to.' );
		}
		if ( ! self::is_list( $targets ) ) {
			throw new Release_Exception( 'version.to must be a list of targets.' );
		}
		self::validate_descriptor( $source, null !== $php_file );
		foreach ( $targets as $target ) {
			if ( ! is_array( $target ) ) {
				throw new Release_Exception( 'Every version target must be a descriptor.' );
			}
			self::validate_descriptor( $target, null !== $php_file );
		}
		$tag_format = $config['tagFormat'] ?? 'v{version}';
		$changesets = $config['changesets'] ?? '.changeset';
		$changelog = $config['changelog'] ?? 'CHANGELOG.md';
		if ( ! is_string( $tag_format ) || 1 !== substr_count( $tag_format, '{version}' ) || ! is_string( $changesets ) || '' === $changesets || ! is_string( $changelog ) ) {
			throw new Release_Exception( 'Invalid tagFormat, changesets, or changelog setting.' );
		}
		return [
			'name' => $name,
			'version' => [
				'from' => $source,
				'to' => $targets,
			],
			'tagFormat' => $tag_format,
			'changesets' => $changesets,
			'changelog' => $changelog,
		];
	}

	private static function is_list( array $values ): bool {
		$next = 0;
		foreach ( array_keys( $values ) as $key ) {
			if ( $next !== $key ) {
				return false;
			}
			++$next;
		}
		return true;
	}

	private static function validate_descriptor( array $descriptor, bool $php_config ): void {
		if ( 'callback' === ( $descriptor['kind'] ?? null ) ) {
			if ( ! $php_config || ! is_string( $descriptor['file'] ?? null ) || ! is_callable( $descriptor['resolve'] ?? null ) ) {
				throw new Release_Exception( 'A callback version descriptor requires a PHP config, a file, and a resolve callable.' );
			}
			if ( isset( $descriptor['replace'] ) && ! is_callable( $descriptor['replace'] ) ) {
				throw new Release_Exception( 'A callback version descriptor replace value must be callable.' );
			}
		}
	}

	private static function infer( string $root ): array {
		$manifest = self::manifest( $root );
		$name = $manifest['name'] ?? basename( $root );
		if ( ! is_string( $name ) || '' === trim( $name ) ) {
			throw new Release_Exception( 'Could not infer the project name.' );
		}

		$plugin_files = [];
		$php_files = glob( $root . '/*.php' );
		foreach ( false === $php_files ? [] : $php_files as $file ) {
			$contents = (string) file_get_contents( $file, false, null, 0, 8192 );
			if ( preg_match( '/^\s*(?:(?:\/\/|\/\*|\*)\s*)?Plugin Name\s*:/mi', $contents ) ) {
				$plugin_files[] = basename( $file );
			}
		}
		if ( count( $plugin_files ) > 1 ) {
			throw new Release_Exception( 'Multiple plugin headers found; add a Changesets config.' );
		}

		$has_manifest_version = isset( $manifest['version'] ) && is_string( $manifest['version'] ) && '' !== $manifest['version'];
		if ( $plugin_files && $has_manifest_version ) {
			throw new Release_Exception( 'Multiple version sources found; add a Changesets config.' );
		}
		if ( $plugin_files ) {
			$descriptor = [
				'kind' => 'wordpress-plugin-header',
				'file' => $plugin_files[0],
			];
			$targets = [ $descriptor ];
			if ( is_file( $root . '/readme.txt' ) ) {
				$targets[] = [
					'kind' => 'wordpress-stable-tag',
					'file' => 'readme.txt',
					'ifPresent' => true,
				];
			}
			return [
				'name' => $name,
				'version' => [
					'from' => $descriptor,
					'to' => $targets,
				],
			];
		}
		if ( $has_manifest_version ) {
			$descriptor = [
				'kind' => 'composer-json',
				'file' => 'composer.json',
			];
			return [
				'name' => $name,
				'version' => [
					'from' => $descriptor,
					'to' => [ $descriptor ],
				],
			];
		}
		if ( $manifest ) {
			return [
				'name' => $name,
				'version' => [
					'from' => [
						'kind' => 'git-tag',
						'pattern' => 'v{version}',
					],
					'to' => [],
				],
			];
		}
		throw new Release_Exception( 'No version source found; add a Changesets config.' );
	}

	private static function manifest( string $root ): array {
		$file = $root . '/composer.json';
		if ( ! is_file( $file ) ) {
			return [];
		}
		$manifest = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $manifest ) ) {
			throw new Release_Exception( 'Invalid composer.json.' );
		}
		return $manifest;
	}
}
