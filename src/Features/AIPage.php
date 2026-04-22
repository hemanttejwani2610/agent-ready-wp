<?php

namespace AgentReady\Features;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and manages the /ai/ AI agent landing page.
 */
class AIPage {

	public function init(): void {
		// No front-end hooks — page is a standard WP page.
	}

	public function create(): array {
		$existing = get_page_by_path( 'ai' );
		$content  = $this->generate_content();
		$title    = get_bloginfo( 'name' ) . ' — AI Agent Guide';

		if ( $existing ) {
			$result = wp_update_post( [
				'ID'           => $existing->ID,
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => 'publish',
			], true );

			if ( is_wp_error( $result ) ) {
				return [ 'success' => false, 'message' => $result->get_error_message() ];
			}

			return [ 'success' => true, 'page_id' => $existing->ID, 'url' => get_permalink( $existing->ID ), 'message' => 'AI page updated.' ];
		}

		$page_id = wp_insert_post( [
			'post_title'     => $title,
			'post_content'   => $content,
			'post_status'    => 'publish',
			'post_type'      => 'page',
			'post_name'      => 'ai',
			'comment_status' => 'closed',
		], true );

		if ( is_wp_error( $page_id ) ) {
			return [ 'success' => false, 'message' => $page_id->get_error_message() ];
		}

		return [ 'success' => true, 'page_id' => $page_id, 'url' => get_permalink( $page_id ), 'message' => 'AI page created at /ai/.' ];
	}

	private function generate_content(): string {
		$name      = get_bloginfo( 'name' );
		$desc      = get_bloginfo( 'description' );
		$home      = home_url();

		$pages      = get_pages( [ 'post_status' => 'publish', 'number' => 8 ] );
		$page_links = '';
		foreach ( $pages as $page ) {
			if ( $page->post_name === 'ai' ) continue;
			$page_links .= '<li><a href="' . esc_url( get_permalink( $page ) ) . '">' . esc_html( get_the_title( $page ) ) . '</a></li>';
		}

		return <<<HTML
<!-- wp:heading {"level":1} --><h1>AI Agent Guide: {$name}</h1><!-- /wp:heading -->
<!-- wp:paragraph --><p>{$desc}</p><!-- /wp:paragraph -->
<!-- wp:heading --><h2>About This Site</h2><!-- /wp:heading -->
<!-- wp:paragraph --><p><strong>{$name}</strong> is optimized for AI agents via the following machine-readable resources:</p><!-- /wp:paragraph -->
<!-- wp:list --><ul>
<li><strong>Site Summary:</strong> <a href="{$home}/llms.txt">/llms.txt</a> — AI context for this site</li>
<li><strong>Robots Rules:</strong> <a href="{$home}/robots.txt">/robots.txt</a> — AI crawler permissions and content signals</li>
<li><strong>Structured Data:</strong> JSON-LD in page head — site metadata and schema</li>
<li><strong>Markdown Support:</strong> Supports <code>Accept: text/markdown</code> for AI content negotiation</li>
<li><strong>Sitemap:</strong> <a href="{$home}/wp-sitemap.xml">/wp-sitemap.xml</a> — discover all pages</li>
</ul><!-- /wp:list -->
<!-- wp:heading --><h2>Key Pages</h2><!-- /wp:heading -->
<!-- wp:list --><ul>{$page_links}</ul><!-- /wp:list -->
<!-- wp:paragraph --><p><em>Optimized by <a href="https://agentready.dev">Agent Ready</a> — making WordPress AI-agent friendly.</em></p><!-- /wp:paragraph -->
HTML;
	}
}
