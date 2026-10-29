<?php
/**
 * Configuration helpers for changesets.config.php, the PHP analog of the
 * wp-release `defineConfig()` and version descriptor builders.
 */

namespace WPElevator\PHP_Changesets;

/**
 * Validate a workspace configuration and return its canonical shape.
 *
 * @return array<string, mixed>
 */
function define_config( array $config ): array {
	return ( new Config_Schema() )->define( $config );
}

/**
 * Return a Composer version descriptor for use as a source or target.
 *
 * @return array{kind: string, file: string, ifPresent: bool}
 */
function composer_json_version( ?string $file = null, bool $if_present = true ): array {
	return [
		'kind' => 'composer-json',
		'file' => $file ?? 'composer.json',
		'ifPresent' => $if_present,
	];
}

/**
 * Return a WordPress plugin header version descriptor.
 *
 * @return array{kind: string, file?: string}
 */
function wordpress_plugin_version( ?string $file = null ): array {
	return file_version_descriptor( 'wordpress-plugin-header', $file );
}

/**
 * Return a WordPress theme style header version descriptor, read from
 * style.css without a file.
 *
 * @return array{kind: string, file?: string}
 */
function wordpress_theme_version( ?string $file = null ): array {
	return file_version_descriptor( 'wordpress-theme-header', $file );
}

/**
 * Return a WordPress readme stable tag version descriptor, read from
 * readme.txt without a file.
 *
 * @return array{kind: string, file?: string, ifPresent: bool}
 */
function wordpress_stable_tag( ?string $file = null, bool $if_present = true ): array {
	$descriptor = file_version_descriptor( 'wordpress-stable-tag', $file );
	$descriptor['ifPresent'] = $if_present;

	return $descriptor;
}

/**
 * Return a git tag version source for packages without a version file. The
 * pattern defaults to the tag format of the package, and the initial version
 * is the current version until the first matching tag exists.
 *
 * @return array{kind: string, pattern?: string, initialVersion?: string}
 */
function git_tag_version( ?string $pattern = null, ?string $initial_version = null ): array {
	$descriptor = [ 'kind' => 'git-tag' ];

	if ( null !== $pattern ) {
		$descriptor['pattern'] = $pattern;
	}

	if ( null !== $initial_version ) {
		$descriptor['initialVersion'] = $initial_version;
	}

	return $descriptor;
}

/**
 * Return a PHP constant version descriptor for use as a source or target.
 *
 * @return array{kind: string, file: string, constant: string}
 */
function php_constant_version( string $file, string $constant ): array {
	return [
		'kind' => 'php-constant',
		'file' => $file,
		'constant' => $constant,
	];
}

/**
 * Return a callback descriptor that resolves and replaces a custom version span.
 *
 * @return array{kind: string, file: string, resolve: callable, replace?: callable}
 */
function callback_version( string $file, callable $resolve, ?callable $replace = null ): array {
	$descriptor = [
		'kind' => 'callback',
		'file' => $file,
		'resolve' => $resolve,
	];

	if ( null !== $replace ) {
		$descriptor['replace'] = $replace;
	}

	return $descriptor;
}

/**
 * Build a file-based descriptor that only carries the kind, and the file
 * when one is given.
 *
 * @return array{kind: string, file?: string}
 */
function file_version_descriptor( string $kind, ?string $file ): array {
	$descriptor = [ 'kind' => $kind ];

	if ( null !== $file ) {
		$descriptor['file'] = $file;
	}

	return $descriptor;
}
