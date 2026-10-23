<?php
namespace BeltoftPerformance\Assets;

use BeltoftPerformance\Plugin;
use BeltoftPerformance\Request\Classifier;
use BeltoftPerformance\Request\PageType;
use BeltoftPerformance\Rules\Rule;
use BeltoftPerformance\Rules\Store;

defined( 'ABSPATH' ) || exit;

/** Drops suppressed files: dequeue AND deregister (a dependency would pull a dequeued handle back in). */
final class Suppressor {
	public static function register(): void {
		if ( is_admin() || Plugin::disabled() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', static function () { self::apply( PageType::current(), PageType::host() ); }, PHP_INT_MAX );
		add_action( 'wp_print_footer_scripts', static function () { self::apply( PageType::current(), PageType::host() ); }, 1 );
	}

	/** Pure: select handles to drop per kind, page, host. Validates rules; skips protected cascades per-rule. */
	public static function select( Graph $graph, array $rules, string $kind, string $page, string $host, string $path = '', array $template_ids = array() ): array {
		$handles = array();
		if ( ! in_array( $page, Store::PAGE_TYPES, true ) ) {
			return $handles; // query-string pages, checkout/account endpoints, previews: every file stays
		}
		foreach ( $rules as $r ) {
			// Skip invalid rules (protects against task 8 trial rules that may not validate).
			if ( null !== Store::validate( $r ) ) {
				continue;
			}
			// Skip tier mismatch, page mismatch, host mismatch.
			if ( 'suppress' !== $r->tier() || ! self::scope_matches( $r->pages, $page, $path, $template_ids ) || ! in_array( $host, $r->hosts, true ) ) {
				continue;
			}
			// Collect targets for this rule only (not merged with others).
			$targets = array();
			if ( 'plugin' === $r->kind() ) {
				$targets = $graph->handles_of_plugin( $r->name() );
			} elseif ( $kind === $r->kind() ) {
				$targets = array( $r->name() );
			}
			if ( ! $targets ) {
				continue;
			}
			// Compute cascade for this rule; skip if any protected file would be dropped.
			$cascade = $graph->cascade( $targets );
			if ( $graph->protected_in( $cascade ) ) {
				continue;
			}
			// Union with results from previous rules.
			$handles = array_merge( $handles, $cascade );
		}
		// Sort and deduplicate.
		$handles = array_unique( $handles );
		sort( $handles );
		return $handles;
	}

	/** A rule applies when any scope is the page type, the request path (url:) or an active template id (template:). */
	private static function scope_matches( array $scopes, string $page, string $path, array $template_ids ): bool {
		$ids = array_map( 'intval', $template_ids );
		foreach ( $scopes as $scope ) {
			if ( $scope === $page ) {
				return true;
			}
			if ( 0 === strpos( $scope, 'url:' ) && '' !== $path && substr( $scope, 4 ) === $path ) {
				return true;
			}
			if ( 0 === strpos( $scope, 'template:' ) && in_array( (int) substr( $scope, 9 ), $ids, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param Rule[]|null $extra_rules Check trial: rules applied for this request only. */
	public static function apply( string $page, string $host, ?array $extra_rules = null ): array {
		$path         = Classifier::request_path( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ) );
		$template_ids = PageType::template_ids();
		static $stored = null;
		$stored ??= Store::all(); // apply() runs twice per page (enqueue + footer): read the rules once
		$rules   = array_merge( $stored, (array) $extra_rules );
		$dropped = array();
		foreach ( array( 'script' => wp_scripts(), 'style' => wp_styles() ) as $kind => $deps ) {
			$graph   = Graph::from_wp( $deps );
			$handles = self::select( $graph, $rules, $kind, $page, $host, $path, $template_ids );
			foreach ( $handles as $h ) {
				if ( 'script' === $kind ) { wp_dequeue_script( $h ); wp_deregister_script( $h ); } else { wp_dequeue_style( $h ); wp_deregister_style( $h ); }
				$dropped[] = "$kind:$h";
			}
		}
		return $dropped;
	}
}
