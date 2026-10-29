<?php

declare(strict_types=1);

namespace WPElevator\PHP_Changesets;

final class Release_Engine {

	public function plan( string $root, ?string $date = null ): array {
		$resolved_root = realpath( $root );
		$root = false === $resolved_root ? '' : $resolved_root;
		if ( '' === $root || ! is_dir( $root ) ) {
			throw new Release_Exception( 'Project root does not exist.' );
		}
		if ( Release_Transaction::has_pending( $root ) ) {
			throw new Release_Exception( 'An interrupted release is pending; run changesets recover.' );
		}
		$config = Project_Config::load( $root );
		$directory = self::safe_path( $root, $config['changesets'] );
		$changesets = ( new Changeset_Reader() )->read( $directory, $config['name'] );
		$current = Version_Files::readCurrent( $root, $config['version']['from'] );
		foreach ( $config['version']['to'] as $target ) {
			Version_Files::propose( $root, $target, $current, $current );
		}
		$release = ( new Version_Planner() )->plan( $current, $changesets );
		$next = $release['nextVersion'];
		$tag = null;
		$edits = [];
		$planned_targets = [];
		if ( null !== $release['bump'] ) {
			$tag = str_replace( '{version}', $next, $config['tagFormat'] );
			if ( Version_Files::tagExists( $root, $tag ) ) {
				throw new Release_Exception( "Proposed tag already exists: {$tag}." );
			}
			foreach ( $config['version']['to'] as $target ) {
				$target_path = isset( $target['file'] ) ? realpath( $root . '/' . $target['file'] ) : false;
				$prior = false !== $target_path ? ( $planned_targets[ $target_path ] ?? null ) : null;
				$edit = Version_Files::propose( $root, $target, $current, $next, $prior['new'] ?? null );
				if ( null !== $edit ) {
					$planned_targets[ $edit['path'] ] = $edit;
					$edits[] = $edit;
				}
			}
			if ( '' !== $config['changelog'] ) {
				$changelog_path = self::safe_path( $root, $config['changelog'] );
				$prior = $planned_targets[ $changelog_path ] ?? null;
				$edits[] = $this->changelog_edit( $root, $config['changelog'], $next, $release['notes'], $date ?? gmdate( 'Y-m-d' ), $prior['new'] ?? null );
			}
		}
		foreach ( $changesets as $changeset ) {
			$edits[] = [
				'path' => $changeset['file'],
				'old' => (string) file_get_contents( $changeset['file'] ),
				'new' => null,
			];
		}
		$edits = $this->combine_edits( $edits );
		return [
			'project' => $config['name'],
			'currentVersion' => $current,
			'bump' => $release['bump'],
			'nextVersion' => $next,
			'tag' => $tag,
			'changesets' => array_map(
				static fn( array $item ): array => [
					'name' => $item['name'],
					'bump' => $item['bump'],
					'summary' => $item['summary'],
				],
				$changesets
			),
			'notes' => $release['notes'],
			'paths' => array_map( static fn( array $edit ): string => ltrim( substr( $edit['path'], strlen( $root ) ), '/' ), $edits ),
			'edits' => $edits,
		];
	}

	public function apply( array $plan, string $root ): void {
		Release_Transaction::apply( $plan['edits'], $root );
	}

	public static function safe_path( string $root, string $relative ): string {
		if ( '' === $relative || 0 === strpos( $relative, '/' ) || false !== strpos( $relative, "\0" ) ) {
			throw new Release_Exception( "Path must be relative to the project: {$relative}." );
		}
		$parts = explode( '/', str_replace( '\\', '/', $relative ) );
		if ( in_array( '..', $parts, true ) ) {
			throw new Release_Exception( "Path escapes the project: {$relative}." );
		}
		$path = $root . '/' . implode( '/', $parts );
		$ancestor = $path;
		while ( ! file_exists( $ancestor ) && ! is_link( $ancestor ) && $ancestor !== $root ) {
			$ancestor = dirname( $ancestor );
		}
		$resolved = realpath( $ancestor );
		if ( false === $resolved || ( $resolved !== $root && 0 !== strpos( $resolved, $root . '/' ) ) ) {
			throw new Release_Exception( "Path escapes the project: {$relative}." );
		}
		if ( is_link( $path ) ) {
			throw new Release_Exception( "Symlink target is not allowed: {$relative}." );
		}
		return $path;
	}

	private function changelog_edit( string $root, string $relative, string $version, array $notes, string $date, ?string $contents_override = null ): array {
		$path = self::safe_path( $root, $relative );
		$old = $contents_override ?? ( is_file( $path ) ? (string) file_get_contents( $path ) : '' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			throw new Release_Exception( 'Release date must use YYYY-MM-DD.' );
		}
		if ( preg_match( '/^##\s+(?:\[)?' . preg_quote( $version, '/' ) . '(?:\])?(?:\s|$)/m', $old ) ) {
			throw new Release_Exception( "Changelog already contains {$version}." );
		}
		$entries = array_map( static fn( array $note ): string => '- ' . str_replace( "\n", "\n  ", $note['summary'] ), $notes );
		// Match the wp-release section format: the version stays unbracketed and the date goes in parentheses.
		$section = '## ' . $version . ' (' . $date . ')' . "\n\n" . implode( "\n", $entries ) . "\n\n";
		if ( '' === $old ) {
			$new = "# Changelog\n\n" . $section;
		} else {
			// Insert before the first version heading, or before the leading title,
			// matching the wp-release changelog insertion precedence.
			if ( preg_match( '/^##[ \t]+v?[0-9]+(?:\.[0-9]+){1,2}(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?(?:[ \t]+.*)?$/m', $old, $matches, PREG_OFFSET_CAPTURE ) ) {
				$anchor = $matches[0][1];
			} elseif ( preg_match( '/^#[ \t]+[^\r\n]+[ \t]*$/m', $old, $matches, PREG_OFFSET_CAPTURE ) ) {
				$anchor = $matches[0][1];
			} else {
				$anchor = 0;
			}
			$before = rtrim( substr( $old, 0, $anchor ), "\r\n" );
			$prefix = '' !== $before ? $before . "\n\n" : '';
			$new = $prefix . $section . substr( $old, $anchor );
		}
		return [
			'path' => $path,
			'old' => null !== $contents_override || is_file( $path ) ? $old : null,
			'new' => $new,
		];
	}

	private function combine_edits( array $edits ): array {
		$by_path = [];
		foreach ( $edits as $edit ) {
			$path = $edit['path'];
			if ( isset( $by_path[ $path ] ) ) {
				$previous = $by_path[ $path ];
				if ( $previous['new'] !== $edit['old'] ) {
					throw new Release_Exception( "Conflicting planned edits for {$path}." );
				}
				$by_path[ $path ]['new'] = $edit['new'];
			} else {
				$by_path[ $path ] = $edit;
			}
		}
		return array_values( $by_path );
	}
}
