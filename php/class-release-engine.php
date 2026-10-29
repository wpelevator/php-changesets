<?php

namespace WPElevator\PHP_Changesets;

/**
 * Build one in-memory release plan and delegate its checked application.
 */
final class Release_Engine {

	private Project_Files $files;

	private Project_Config $config_loader;

	private Changeset_Reader $reader;

	private Version_Planner $planner;

	private Version_Files $versions;

	private Release_Transaction $transaction;

	public function __construct(
		Project_Files $files,
		?Project_Config $config_loader = null,
		?Changeset_Reader $reader = null,
		?Version_Planner $planner = null,
		?Version_Files $versions = null,
		?Release_Transaction $transaction = null
	) {
		$this->files = $files;
		$this->config_loader = $config_loader ?? new Project_Config( $files );
		$this->reader = $reader ?? new Changeset_Reader();
		$this->planner = $planner ?? new Version_Planner();
		$this->versions = $versions ?? new Version_Files( $files );
		$this->transaction = $transaction ?? new Release_Transaction( $files );
	}

	/**
	 * Plan all file changes before writing any of them.
	 *
	 * The public plan keys retain their camelCase JSON names. Each internal edit
	 * has an absolute path, the expected old contents or null, and the proposed
	 * new contents or null for a deletion.
	 */
	public function plan( ?string $date = null ): array {
		$root = $this->files->get_root();

		if ( $this->transaction->has_pending() ) {
			throw new Release_Exception( 'An interrupted release is pending; run changesets recover.' );
		}

		$config = $this->config_loader->load();
		$directory = $this->files->resolve( $config['changesets'] );
		$changesets = $this->reader->read( $directory, $config['name'] );
		$current = $this->versions->read_current( $config['version']['from'] );

		foreach ( $config['version']['to'] as $target ) {
			$this->versions->propose( $target, $current, $current );
		}

		$release = $this->planner->plan( $current, $changesets );
		$next = $release['nextVersion'];
		$tag = null;
		$edits = [];
		$planned_targets = [];

		if ( null !== $release['bump'] ) {
			$tag = str_replace( '{version}', $next, $config['tagFormat'] );

			if ( $this->versions->tag_exists( $tag ) ) {
				throw new Release_Exception( "Proposed tag already exists: {$tag}." );
			}

			foreach ( $config['version']['to'] as $target ) {
				$target_path = isset( $target['file'] ) ? realpath( $root . '/' . $target['file'] ) : false;
				$prior = false !== $target_path ? ( $planned_targets[ $target_path ] ?? null ) : null;
				$edit = $this->versions->propose( $target, $current, $next, $prior['new'] ?? null );

				if ( null !== $edit ) {
					$planned_targets[ $edit['path'] ] = $edit;
					$edits[] = $edit;
				}
			}

			if ( '' !== $config['changelog'] ) {
				$changelog_path = $this->files->resolve( $config['changelog'] );
				$prior = $planned_targets[ $changelog_path ] ?? null;
				$edits[] = $this->changelog_edit( $config['changelog'], $next, $release['notes'], $date ?? gmdate( 'Y-m-d' ), $prior['new'] ?? null );
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

	public function apply( array $plan ): void {
		$this->transaction->apply( $plan['edits'] );
	}

	private function changelog_edit( string $relative, string $version, array $notes, string $date, ?string $contents_override = null ): array {
		$path = $this->files->resolve( $relative );
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

	/**
	 * Compose edits to one path while rejecting disconnected intermediate states.
	 */
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
