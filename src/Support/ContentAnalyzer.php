<?php

namespace AgentReady\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Scans posts/pages for AI-unfriendly content patterns.
 */
class ContentAnalyzer {

	private const CACHE_KEY = 'agent_ready_content_analysis';
	private const CACHE_TTL = 2 * HOUR_IN_SECONDS;

	public function analyze( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) return $cached;
		}

		$posts   = get_posts( [
			'post_type'      => [ 'post', 'page' ],
			'posts_per_page' => 100,
			'post_status'    => 'publish',
			'orderby'        => 'modified',
			'order'          => 'DESC',
		] );

		$items        = [];
		$total_issues = 0;

		foreach ( $posts as $post ) {
			$issues = $this->analyze_post( $post );
			if ( $issues ) {
				$total_issues += count( $issues );
				$items[]       = [
					'id'     => $post->ID,
					'title'  => get_the_title( $post ),
					'url'    => get_permalink( $post ),
					'type'   => $post->post_type,
					'issues' => $issues,
				];
			}
		}

		$report = [
			'total_posts'       => count( $posts ),
			'posts_with_issues' => count( $items ),
			'total_issues'      => $total_issues,
			'items'             => $items,
			'analyzed_at'       => current_time( 'mysql' ),
		];

		set_transient( self::CACHE_KEY, $report, self::CACHE_TTL );
		return $report;
	}

	public function analyze_post( \WP_Post $post ): array {
		$content = $post->post_content;
		$issues  = [];

		if ( ! preg_match( '/<h[12][\s>]/i', $content ) ) {
			$issues[] = [ 'type' => 'missing_headings', 'severity' => 'high',   'message' => 'No H1/H2 found — AI agents struggle to understand content structure.' ];
		}

		if ( preg_match( '/<h3[\s>]/i', $content ) && ! preg_match( '/<h2[\s>]/i', $content ) ) {
			$issues[] = [ 'type' => 'broken_hierarchy', 'severity' => 'medium', 'message' => 'H3 found without H2 — broken heading hierarchy.' ];
		}

		$words = str_word_count( wp_strip_all_tags( $content ) );
		if ( $words < 50 && ! empty( trim( $content ) ) ) {
			$issues[] = [ 'type' => 'thin_content',     'severity' => 'medium', 'message' => "Very thin content ({$words} words) — AI agents benefit from descriptive text." ];
		}

		if ( preg_match( '/<div[^>]*class=["\'][^"\']*(?:react-root|vue-app|angular-root|app-root)[^"\']*["\'][^>]*>\s*<\/div>/i', $content ) ) {
			$issues[] = [ 'type' => 'js_only_content',  'severity' => 'high',   'message' => 'JS-only content block detected — AI agents cannot parse JavaScript.' ];
		}

		preg_match_all( '/<img[^>]+>/i', $content, $imgs );
		$no_alt = count( array_filter( $imgs[0], static fn( $t ) => ! preg_match( '/alt=["\'][^"\']+["\']/i', $t ) ) );
		if ( $no_alt ) {
			$issues[] = [ 'type' => 'missing_alt',      'severity' => 'low',    'message' => "{$no_alt} image(s) missing alt text." ];
		}

		return $issues;
	}

	public function invalidate(): void {
		delete_transient( self::CACHE_KEY );
	}
}
