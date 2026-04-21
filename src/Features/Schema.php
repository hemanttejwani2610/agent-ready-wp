<?php

namespace AgentReady\Features;

defined( 'ABSPATH' ) || exit;

/**
 * Injects JSON-LD structured data into wp_head.
 */
class Schema {

	public function init(): void {
		add_action( 'wp_head', [ $this, 'inject' ], 5 );
	}

	public function inject(): void {
		if ( ! (bool) get_option( 'agent_ready_enable_schema', false ) ) {
			return;
		}

		$schema = $this->build();
		if ( empty( $schema ) ) {
			return;
		}

		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		printf( "<script type=\"application/ld+json\">\n%s\n</script>\n", $json ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function build(): array {
		$custom = (string) get_option( 'agent_ready_schema_custom_json', '' );
		if ( ! empty( trim( $custom ) ) ) {
			$decoded = json_decode( $custom, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		if ( is_singular( 'post' ) ) {
			return $this->article_schema();
		}

		if ( is_singular( 'page' ) && $this->is_faq_page() ) {
			return $this->faq_schema();
		}

		return $this->site_schema();
	}

	private function site_schema(): array {
		$type = (string) get_option( 'agent_ready_schema_type', 'auto' );
		if ( $type === 'auto' ) {
			$type = $this->detect_type();
		}

		$base = [
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => home_url(),
			'@context'    => 'https://schema.org',
		];

		$logo = $this->logo_url();
		if ( $logo ) {
			$base['logo'] = [ '@type' => 'ImageObject', 'url' => $logo ];
		}

		return match ( $type ) {
			'saas', 'plugin' => array_merge(
				[ '@type' => 'SoftwareApplication', 'applicationCategory' => 'WebApplication', 'operatingSystem' => 'Web' ],
				$base
			),
			'blog' => array_merge( [ '@type' => 'Blog' ], $base ),
			default => array_merge(
				[ '@type' => 'Organization' ],
				$base,
				[ 'contactPoint' => [ '@type' => 'ContactPoint', 'email' => get_bloginfo( 'admin_email' ), 'contactType' => 'customer support' ] ]
			),
		};
	}

	private function article_schema(): array {
		global $post;
		$schema = [
			'@context'      => 'https://schema.org',
			'@type'         => 'Article',
			'headline'      => get_the_title(),
			'description'   => wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 ),
			'url'           => get_permalink(),
			'datePublished' => get_the_date( 'c', $post ),
			'dateModified'  => get_the_modified_date( 'c', $post ),
			'author'        => [ '@type' => 'Person', 'name' => get_the_author_meta( 'display_name', $post->post_author ) ],
			'publisher'     => [ '@type' => 'Organization', 'name' => get_bloginfo( 'name' ) ],
		];

		$thumb = get_the_post_thumbnail_url( $post->ID, 'large' );
		if ( $thumb ) {
			$schema['image'] = $thumb;
		}

		return $schema;
	}

	private function faq_schema(): array {
		global $post;
		$entities = [];
		preg_match_all( '/<h[23][^>]*>(.*?)<\/h[23]>\s*<p>(.*?)<\/p>/is', $post->post_content, $m, PREG_SET_ORDER );

		foreach ( $m as $match ) {
			$entities[] = [
				'@type'          => 'Question',
				'name'           => wp_strip_all_tags( $match[1] ),
				'acceptedAnswer' => [ '@type' => 'Answer', 'text' => wp_strip_all_tags( $match[2] ) ],
			];
		}

		if ( empty( $entities ) ) {
			return $this->site_schema();
		}

		return [ '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities ];
	}

	private function detect_type(): string {
		if ( class_exists( 'WooCommerce' ) ) return 'ecommerce';

		$text = strtolower( get_bloginfo( 'name' ) . ' ' . get_bloginfo( 'description' ) );

		foreach ( [ 'saas', 'software', 'app', 'platform', 'tool' ] as $kw ) {
			if ( str_contains( $text, $kw ) ) return 'saas';
		}
		foreach ( [ 'plugin', 'extension', 'addon' ] as $kw ) {
			if ( str_contains( $text, $kw ) ) return 'plugin';
		}

		return wp_count_posts( 'post' )->publish > 10 ? 'blog' : 'business';
	}

	private function is_faq_page(): bool {
		global $post;
		if ( ! $post ) return false;
		$text = strtolower( get_the_title( $post ) . ' ' . $post->post_name );
		return str_contains( $text, 'faq' )
			|| ( substr_count( $post->post_content, '<h2' ) >= 3 && substr_count( $post->post_content, '<p>' ) >= 3 );
	}

	private function logo_url(): string {
		$id = get_theme_mod( 'custom_logo' );
		if ( $id ) {
			$src = wp_get_attachment_image_src( $id, 'full' );
			if ( $src ) return $src[0];
		}
		return '';
	}

	public function get_preview(): string {
		return wp_json_encode( $this->build(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}
}
