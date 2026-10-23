<?php
namespace BeltoftPerformance\Rules;

defined( 'ABSPATH' ) || exit;

final class Rule {
	public function __construct(
		public readonly string $target,
		public readonly array $pages,
		public readonly array $hosts = array( 'retail' ),
		public readonly string $reason = '',
		public readonly string $approved_by = '',
		public readonly string $approved_at = '',
		public readonly array $manual_pages = array(),
		public readonly bool $owner_override = false
	) {}

	/** True when any scope was added by hand. */
	public function is_manual(): bool {
		return (bool) $this->manual_pages;
	}

	public function tier(): string {
		return str_starts_with( $this->target, 'unload:' ) ? 'unload' : 'suppress';
	}

	public function kind(): string {
		return (string) strstr( $this->target, ':', true );
	}

	public function name(): string {
		return (string) substr( (string) strstr( $this->target, ':' ), 1 );
	}

	public function key(): string {
		$p = $this->pages; sort( $p ); $h = $this->hosts; sort( $h );
		return $this->target . '|' . implode( ',', $p ) . '|' . implode( ',', $h );
	}

	public function to_array(): array {
		return array( 'target' => $this->target, 'pages' => array_values( $this->pages ), 'hosts' => array_values( $this->hosts ), 'reason' => $this->reason, 'approved_by' => $this->approved_by, 'approved_at' => $this->approved_at, 'manual_pages' => array_values( $this->manual_pages ), 'owner_override' => $this->owner_override );
	}

	public static function from_array( array $a ): self {
		return new self( (string) ( $a['target'] ?? '' ), (array) ( $a['pages'] ?? array() ), (array) ( $a['hosts'] ?? array( 'retail' ) ), (string) ( $a['reason'] ?? '' ), (string) ( $a['approved_by'] ?? '' ), (string) ( $a['approved_at'] ?? '' ), array_values( array_filter( (array) ( $a['manual_pages'] ?? array() ), 'is_string' ) ), ! empty( $a['owner_override'] ) );
	}
}
