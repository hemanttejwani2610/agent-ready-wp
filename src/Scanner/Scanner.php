<?php

namespace AgentReady\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * AI Readiness Scanner — scores the site 0–100.
 *
 * Three pillars and their checks (total = 100):
 *
 *   Discoverability
 *     llms_txt          15  — /llms.txt served
 *     json_ld           20  — JSON-LD structured data injected
 *     heading_structure 10  — H1/H2 headings in content
 *     internal_linking   5  — internal links between pages
 *
 *   Content Accessibility
 *     markdown_negotiation 20  — Accept: text/markdown → text/markdown response
 *
 *   Bot Access Control
 *     robots_rules     15  — AI bot allow rules in robots.txt
 *     content_signals  15  — Content-Signal directive in robots.txt
 */
class Scanner {

	private const CACHE_KEY = 'agent_ready_scan_results';
	private const CACHE_TTL = HOUR_IN_SECONDS;

	public function run( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$checks  = $this->run_checks();
		$score   = $this->calculate_score( $checks );
		$results = [
			'score'      => $score,
			'grade'      => $this->grade( $score ),
			'checks'     => $checks,
			'issues'     => array_values( array_filter( array_column( $checks, 'issue' ) ) ),
			'fixes'      => array_values( array_filter( array_column( $checks, 'fix' ) ) ),
			'scanned_at' => current_time( 'mysql' ),
		];

		set_transient( self::CACHE_KEY, $results, self::CACHE_TTL );
		return $results;
	}

	public function invalidate(): void {
		delete_transient( self::CACHE_KEY );
	}

	// -------------------------------------------------------------------------
	// Checks
	// -------------------------------------------------------------------------

	private function run_checks(): array {
		return [
			// Discoverability
			'llms_txt'             => $this->check( 'llms.txt File',                 (bool) get_option( 'agent_ready_enable_llms_txt', false ),             15, 'No /llms.txt served.',                                            'Enable llms.txt in Agent Ready → llms.txt Editor.' ),
			'json_ld'              => $this->check( 'JSON-LD Structured Data',        (bool) get_option( 'agent_ready_enable_schema', false ),               20, 'No JSON-LD schema injected.',                                     'Enable Schema in Agent Ready → Schema Settings.' ),
			'heading_structure'    => $this->check_headings(),
			'internal_linking'     => $this->check_linking(),

			// Content Accessibility
			'markdown_negotiation' => $this->check( 'Markdown Negotiation',          (bool) get_option( 'agent_ready_enable_markdown_negotiation', true ),  20, 'Site does not serve Markdown on Accept: text/markdown.',          'Enable Markdown Negotiation in Agent Ready → Markdown.' ),

			// Bot Access Control
			'robots_rules'         => $this->check( 'AI Bot Rules in robots.txt',    (bool) get_option( 'agent_ready_enable_robots_rules', false ),         15, 'No AI bot allow rules in robots.txt.',                            'Enable AI Robots Rules in Agent Ready → Robots.' ),
			'content_signals'      => $this->check( 'Content Signals in robots.txt', (bool) get_option( 'agent_ready_enable_content_signals', false ),      15, 'No Content-Signal directive in robots.txt (contentsignals.org).', 'Enable Content Signals in Agent Ready → Robots.' ),
		];
	}

	private function check_headings(): array {
		$posts = get_posts( [ 'post_type' => [ 'post', 'page' ], 'posts_per_page' => 20, 'post_status' => 'publish' ] );
		$total = count( $posts );
		$good  = 0;

		foreach ( $posts as $post ) {
			if ( preg_match( '/<h[12][\s>]/i', $post->post_content ) ) {
				$good++;
			}
		}

		$pass = $total === 0 || ( $good / $total ) >= 0.7;
		return $this->check( 'Heading Structure (H1/H2)', $pass, 10, count( $posts ) - $good . ' post(s) lack H1/H2 headings.', 'Add H1/H2 headings to posts and pages.' );
	}

	private function check_linking(): array {
		$posts  = get_posts( [ 'post_type' => [ 'post', 'page' ], 'posts_per_page' => 20, 'post_status' => 'publish' ] );
		$total  = count( $posts );
		$linked = 0;
		$host   = preg_quote( wp_parse_url( home_url(), PHP_URL_HOST ), '/' );

		foreach ( $posts as $post ) {
			if ( preg_match( '/<a\s[^>]*href=["\']https?:\/\/' . $host . '/i', $post->post_content ) ) {
				$linked++;
			}
		}

		$pass = $total === 0 || ( $linked / $total ) >= 0.5;
		return $this->check( 'Internal Linking', $pass, 5, 'Less than 50% of pages have internal links.', 'Add internal links between related posts and pages.' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function check( string $label, bool $pass, int $weight, string $issue, string $fix ): array {
		return [
			'label'  => $label,
			'pass'   => $pass,
			'weight' => $weight,
			'issue'  => $pass ? '' : $issue,
			'fix'    => $pass ? '' : $fix,
		];
	}

	private function calculate_score( array $checks ): int {
		$total  = array_sum( array_column( $checks, 'weight' ) );
		$earned = array_sum( array_map( static fn( $c ) => $c['pass'] ? $c['weight'] : 0, $checks ) );
		return $total > 0 ? (int) round( $earned / $total * 100 ) : 0;
	}

	private function grade( int $score ): string {
		return match ( true ) {
			$score >= 90 => 'A',
			$score >= 75 => 'B',
			$score >= 60 => 'C',
			$score >= 45 => 'D',
			default      => 'F',
		};
	}
}
