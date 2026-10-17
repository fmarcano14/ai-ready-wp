<?php
/**
 * One directory of generated files. This is the only copy of that output.
 *
 * @package AiReady
 */

namespace AiReady;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filesystem storage for llms.txt, llms-full.txt, and per-document Markdown.
 */
final class Store {

	private string $root;

	public function __construct( string $root ) {
		$this->root = rtrim( $root, '/\\' );
	}

	public static function from_uploads(): self {
		$base    = '';
		$uploads = wp_upload_dir( null, false );
		if ( is_array( $uploads ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			$base = (string) $uploads['basedir'];
		}
		if ( '' === $base && defined( 'WP_CONTENT_DIR' ) ) {
			$base = WP_CONTENT_DIR . '/uploads';
		}
		return new self( $base . '/ai-ready' );
	}

	/**
	 * Replace the current generation. Running it twice with the same input converges.
	 *
	 * @param Document[] $documents Documents to keep.
	 */
	public function replace( string $index, ?string $full, array $documents ): void {
		$this->mkdir( $this->root );
		$this->clean_leftovers();
		$token   = bin2hex( random_bytes( 8 ) );
		$staging = $this->root . '/staging-' . $token;
		$this->mkdir( $staging . '/docs' );
		$this->write( $staging . '/llms.txt', $index );
		if ( null !== $full ) {
			$this->write( $staging . '/llms-full.txt', $full );
		}
		foreach ( $documents as $document ) {
			if ( ! $document instanceof Document || $document->id() < 1 ) {
				continue;
			}
			$this->write( $staging . '/docs/' . $document->id() . '.md', $document->markdown() );
		}

		$current = $this->current_dir();
		$backup  = $this->root . '/backup-' . $token;
		if ( is_dir( $current ) ) {
			rename( $current, $backup );
		}
		rename( $staging, $current );
		if ( is_dir( $backup ) ) {
			$this->rmtree( $backup );
		}
		$this->protect();
	}

	public function read_index(): ?string {
		return $this->read_named( 'llms.txt' );
	}

	public function read_full(): ?string {
		return $this->read_named( 'llms-full.txt' );
	}

	public function read_document( int $id ): ?string {
		if ( $id < 1 ) {
			return null;
		}
		return $this->read_inside( $this->current_dir() . '/docs/' . $id . '.md' );
	}

	public function delete_document( int $id ): void {
		if ( $id < 1 ) {
			return;
		}
		$path = $this->current_dir() . '/docs/' . $id . '.md';
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}

	public function has_index(): bool {
		return is_file( $this->current_dir() . '/llms.txt' );
	}

	public function has_full(): bool {
		return is_file( $this->current_dir() . '/llms-full.txt' );
	}

	public function generated_at(): int {
		$path = $this->current_dir() . '/llms.txt';
		return is_file( $path ) ? (int) filemtime( $path ) : 0;
	}

	/**
	 * @return int[]
	 */
	public function document_ids(): array {
		$dir = $this->current_dir() . '/docs';
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$ids   = array();
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return array();
		}
		foreach ( $items as $item ) {
			if ( preg_match( '/\A(\d+)\.md\z/', $item, $match ) ) {
				$ids[] = (int) $match[1];
			}
		}
		sort( $ids );
		return $ids;
	}

	public function destroy(): void {
		if ( is_dir( $this->root ) ) {
			$this->rmtree( $this->root );
		}
	}

	private function read_named( string $name ): ?string {
		if ( 'llms.txt' !== $name && 'llms-full.txt' !== $name ) {
			return null;
		}
		return $this->read_inside( $this->current_dir() . '/' . $name );
	}

	private function read_inside( string $path ): ?string {
		if ( ! is_file( $path ) ) {
			return null;
		}
		$real = realpath( $path );
		$root = realpath( $this->current_dir() );
		if ( false === $real || false === $root ) {
			return null;
		}
		$prefix = $root . DIRECTORY_SEPARATOR;
		if ( 0 !== strpos( $real, $prefix ) && $real !== $root ) {
			return null;
		}
		$body = file_get_contents( $real );
		return is_string( $body ) ? $body : null;
	}

	private function current_dir(): string {
		return $this->root . '/current';
	}

	private function write( string $path, string $body ): void {
		file_put_contents( $path, $body, LOCK_EX );
	}

	private function mkdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
	}

	private function clean_leftovers(): void {
		if ( ! is_dir( $this->root ) ) {
			return;
		}
		$items = scandir( $this->root );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( 0 === strpos( $item, 'staging-' ) || 0 === strpos( $item, 'backup-' ) ) {
				$this->rmtree( $this->root . '/' . $item );
			}
		}
	}

	/**
	 * Block direct web access on Apache. PHP still reads the files.
	 */
	private function protect(): void {
		$rules = "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";
		$this->write( $this->root . '/.htaccess', $rules );
		$this->write( $this->root . '/index.php', "<?php\n// Silence is golden.\n" );
	}

	private function rmtree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->rmtree( $path );
				continue;
			}
			unlink( $path );
		}
		rmdir( $dir );
	}
}
