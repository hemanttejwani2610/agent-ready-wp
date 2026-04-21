<?php

namespace AgentReady\Features;

defined( 'ABSPATH' ) || exit;

/**
 * Markdown content negotiation — per Cloudflare / isitagentready.com spec.
 *
 * Spec requirements:
 *   1. Requests with `Accept: text/markdown` must receive `Content-Type: text/markdown`
 *   2. `x-markdown-tokens` header with estimated token count (REQUIRED by isitagentready check)
 *   3. `Vary: Accept` header so caches know the response varies by Accept
 *   4. `Content-Signal: ai-train=yes, search=yes, ai-input=yes` response header
 *   5. HTML stays the default — only triggered by Accept header or /index.md URL
 *   6. Works for ALL page types (homepage, singular, archive, search, etc.)
 *
 * Two triggers:
 *   a) Accept: text/markdown request header → serve current page as Markdown
 *   b) /index.md URL suffix → e.g. /about/index.md serves /about/ as Markdown
 */
class MarkdownNegotiation {

	public function init(): void {
		if ( ! (bool) get_option( 'agent_ready_enable_markdown_negotiation', true ) ) {
			return;
		}

		add_action( 'init',               [ $this, 'add_rewrite_rule' ] );
		add_filter( 'query_vars',         [ $this, 'add_query_var' ] );

		// Priority 1 — run before any theme output, caching plugins, etc.
		add_action( 'template_redirect',  [ $this, 'maybe_serve_markdown' ], 1 );
	}

	public function add_rewrite_rule(): void {
		add_rewrite_rule( '^(.+)/index\.md$', 'index.php?agent_ready_index_md=$matches[1]', 'top' );
		add_rewrite_rule( '^index\.md$',      'index.php?agent_ready_index_md=__home__',    'top' );
	}

	public function add_query_var( array $vars ): array {
		$vars[] = 'agent_ready_index_md';
		return $vars;
	}

	public function maybe_serve_markdown(): void {
		// Trigger 1: /index.md URL suffix.
		$index_md_path = get_query_var( 'agent_ready_index_md' );
		if ( $index_md_path ) {
			$this->serve_for_path( $index_md_path );
			return;
		}

		// Trigger 2: Accept: text/markdown request header → redirect to /index.md equivalent.
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) $_SERVER['HTTP_ACCEPT'] : '';
		if ( $this->accepts_markdown( $accept ) && ! str_ends_with( $_SERVER['REQUEST_URI'] ?? '', '/index.md' ) ) {
			$path = ltrim( parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ), '/' );
			$path = trim( $path, '/' );
			$redirect_to = home_url( ( $path ? '/' . $path : '' ) . '/index.md' );
			header( 'Content-Type: text/markdown; charset=UTF-8' );
			header( 'Vary: Accept' );
			// Fetch and output the markdown for this path.
			$response = wp_remote_get( $redirect_to );
			if ( ! is_wp_error( $response ) ) {
				$markdown = wp_remote_retrieve_body( $response );
				$token_estimate = $this->estimate_tokens( $markdown );
				header( 'x-markdown-tokens: ' . $token_estimate );
				header( 'Content-Signal: ai-train=yes, search=yes, ai-input=yes' );
				echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput
				exit;
			}
		}
	}

	// -------------------------------------------------------------------------
	// Routing
	// -------------------------------------------------------------------------

	private function serve_current_page(): void {
		global $post;

		if ( is_singular() && $post ) {
			// Single post, page, or CPT.
			$this->output( $this->post_to_markdown( $post ) );

		} elseif ( is_front_page() || is_home() ) {
			// Homepage (static or blog index).
			$this->output( $this->homepage_to_markdown() );

		} elseif ( is_category() || is_tag() || is_tax() ) {
			// Taxonomy archive.
			$this->output( $this->archive_to_markdown( get_queried_object() ) );

		} elseif ( is_search() ) {
			// Search results page.
			$this->output( $this->search_to_markdown( get_search_query() ) );

		} else {
			// Fallback: homepage summary.
			$this->output( $this->homepage_to_markdown() );
		}
	}

	private function serve_for_path( string $path ): void {
		if ( $path === '__home__' ) {
			$this->output( $this->homepage_to_markdown() );
			return;
		}

		// Try page by full path first, then by basename slug.
		$post = get_page_by_path( $path, OBJECT, [ 'page', 'post' ] );

		if ( ! $post ) {
			$posts = get_posts( [
				'name'           => basename( $path ),
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			] );
			$post = $posts[0] ?? null;
		}

		if ( $post ) {
			setup_postdata( $post );
			$this->output( $this->post_to_markdown( $post ) );
			wp_reset_postdata();
		} else {
			status_header( 404 );
			exit;
		}
	}

	// -------------------------------------------------------------------------
	// Output
	// -------------------------------------------------------------------------

	private function output( string $markdown ): void {
		$token_estimate = $this->estimate_tokens( $markdown );

		header( 'Content-Type: text/markdown; charset=UTF-8' );
		header( 'Vary: Accept' );
		header( 'x-markdown-tokens: ' . $token_estimate );
		header( 'Content-Signal: ai-train=yes, search=yes, ai-input=yes' );
		header( 'Cache-Control: public, max-age=3600' );

		echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Estimate token count — roughly 1 token per 4 characters (GPT-style approximation).
	 */
	private function estimate_tokens( string $text ): int {
		return (int) ceil( mb_strlen( $text ) / 4 );
	}

	private function accepts_markdown( string $accept ): bool {
		return str_contains( $accept, 'text/markdown' )
			|| str_contains( $accept, 'text/x-markdown' );
	}

	// -------------------------------------------------------------------------
	// Content Generators
	// -------------------------------------------------------------------------

	private function homepage_to_markdown(): string {
		$name  = get_bloginfo( 'name' );
		$desc  = get_bloginfo( 'description' );
		$home  = home_url();
		$lines = [ "# {$name}", '' ];

		if ( $desc ) {
			$lines[] = "> {$desc}";
			$lines[] = '';
		}

		$lines[] = '## Recent Posts';
		$lines[] = '';
		$posts = get_posts( [ 'posts_per_page' => 10, 'post_status' => 'publish' ] );
		foreach ( $posts as $post ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 20 );
			$lines[] = '### [' . get_the_title( $post ) . '](' . get_permalink( $post ) . ')';
			if ( $excerpt ) {
				$lines[] = $excerpt;
			}
			$lines[] = '';
		}

		$lines[] = '## Site Navigation';
		$lines[] = '';
		foreach ( get_pages( [ 'post_status' => 'publish', 'number' => 8 ] ) as $page ) {
			$lines[] = '- [' . get_the_title( $page ) . '](' . get_permalink( $page ) . ')';
		}

		$lines[] = '';
		$lines[] = '## AI Agent Resources';
		$lines[] = '';
		$lines[] = "- [llms.txt]({$home}/llms.txt)";
		$lines[] = "- [MCP Server Card]({$home}/.well-known/mcp/server-card.json)";
		$lines[] = "- [Agent Skills]({$home}/.well-known/agent-skills/index.json)";
		$lines[] = "- [API Summary](" . rest_url( 'agent-ready/v1/summary' ) . ')';
		$lines[] = "- [Sitemap]({$home}/wp-sitemap.xml)";

		return implode( "\n", $lines );
	}

	private function post_to_markdown( \WP_Post $post ): string {
		$title   = get_the_title( $post );
		$date    = get_the_date( 'Y-m-d', $post );
		$author  = get_the_author_meta( 'display_name', $post->post_author );
		$url     = get_permalink( $post );
		$content = $this->html_to_markdown( apply_filters( 'the_content', $post->post_content ) );
		$cats    = wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] );
		$tags    = wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] );

		$lines   = [ "# {$title}", '' ];
		$meta    = [ "**URL:** [{$url}]({$url})", "**Date:** {$date}", "**Author:** {$author}" ];

		if ( $cats ) {
			$meta[] = '**Categories:** ' . implode( ', ', $cats );
		}
		if ( $tags ) {
			$meta[] = '**Tags:** ' . implode( ', ', $tags );
		}

		$lines[] = implode(' | ', $meta );
		$lines[] = '';
		$lines[] = '---';
		$lines[] = '';
		$lines[] = $content;

		return implode( "\n", $lines );
	}

	private function archive_to_markdown( object $term ): string {
		$lines   = [ '# ' . esc_html( $term->name ), '' ];
		if ( $term->description ) {
			$lines[] = '> ' . esc_html( $term->description );
			$lines[] = '';
		}

		$posts = get_posts( [
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'tax_query'      => [ [ 'taxonomy' => $term->taxonomy, 'field' => 'term_id', 'terms' => $term->term_id ] ],
		] );

		foreach ( $posts as $post ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 15 );
			$lines[] = '### [' . get_the_title( $post ) . '](' . get_permalink( $post ) . ')';
			if ( $excerpt ) {
				$lines[] = $excerpt;
			}
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	private function search_to_markdown( string $query ): string {
		$lines = [ '# Search Results: ' . esc_html( $query ), '' ];

		$posts = get_posts( [
			's'              => $query,
			'post_status'    => 'publish',
			'posts_per_page' => 10,
		] );

		if ( empty( $posts ) ) {
			$lines[] = '_No results found._';
			return implode( "\n", $lines );
		}

		foreach ( $posts as $post ) {
			$lines[] = '- [' . get_the_title( $post ) . '](' . get_permalink( $post ) . ')';
		}

		return implode( "\n", $lines );
	}

	// -------------------------------------------------------------------------
	// HTML → Markdown converter
	// -------------------------------------------------------------------------

	private function html_to_markdown( string $html ): string {
		// Strip scripts, styles, and HTML comments.
		$html = preg_replace( [ '/<(script|style)[^>]*>.*?<\/\1>/is', '/<!--.*?-->/s' ], '', $html );

		$rules = [
			'/<h1[^>]*>(.*?)<\/h1>/is'                                          => "\n# $1\n",
			'/<h2[^>]*>(.*?)<\/h2>/is'                                          => "\n## $1\n",
			'/<h3[^>]*>(.*?)<\/h3>/is'                                          => "\n### $1\n",
			'/<h4[^>]*>(.*?)<\/h4>/is'                                          => "\n#### $1\n",
			'/<h5[^>]*>(.*?)<\/h5>/is'                                          => "\n##### $1\n",
			'/<strong[^>]*>(.*?)<\/strong>/is'                                  => '**$1**',
			'/<b[^>]*>(.*?)<\/b>/is'                                            => '**$1**',
			'/<em[^>]*>(.*?)<\/em>/is'                                          => '_$1_',
			'/<i[^>]*>(.*?)<\/i>/is'                                            => '_$1_',
			'/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is'               => '[$2]($1)',
			'/<img[^>]*alt=["\']([^"\']*)["\'][^>]*src=["\']([^"\']+)["\'][^>]*\/?>/is' => '![$1]($2)',
			'/<img[^>]*src=["\']([^"\']+)["\'][^>]*\/?>/is'                     => '![]($1)',
			'/<li[^>]*>(.*?)<\/li>/is'                                          => "- $1\n",
			'/<blockquote[^>]*>(.*?)<\/blockquote>/is'                          => "> $1",
			'/<code[^>]*>(.*?)<\/code>/is'                                      => '`$1`',
			'/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/is'                     => "\n```\n$1\n```\n",
			'/<pre[^>]*>(.*?)<\/pre>/is'                                        => "\n```\n$1\n```\n",
			'/<hr[^>]*\/?>/i'                                                   => "\n---\n",
			'/<br[^>]*\/?>/i'                                                   => "  \n",
			'/<p[^>]*>(.*?)<\/p>/is'                                            => "$1\n\n",
			'/<div[^>]*>(.*?)<\/div>/is'                                        => "$1\n",
		];

		foreach ( $rules as $pattern => $replacement ) {
			$html = preg_replace( $pattern, $replacement, $html );
		}

		$html = wp_strip_all_tags( $html );
		$html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$html = preg_replace( '/\n{3,}/', "\n\n", $html );

		return trim( $html );
	}
}
