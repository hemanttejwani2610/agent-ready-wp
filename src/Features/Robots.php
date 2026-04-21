<?php

namespace AgentReady\Features;

defined( 'ABSPATH' ) || exit;

/**
 * Appends AI-bot allow rules and Content Signals to robots.txt.
 *
 * Content Signals (contentsignals.org) — added as a Content-Signal directive:
 *   Content-Signal: ai-train=yes, ai-input=yes, search=yes
 *
 * Three dimensions:
 *   ai-train  — may AI models train on this content?
 *   ai-input  — may AI models use this content for inference/grounding?
 *   search    — may this content appear in search results?
 */
class Robots {

	public function init(): void {
		add_filter( 'robots_txt', [ $this, 'append_rules' ], 99, 2 );
	}

	public function append_rules( string $output, bool $public ): string {
		if ( ! $public ) {
			return $output;
		}

		$parts = [];

		if ( (bool) get_option( 'agent_ready_enable_robots_rules', false ) ) {
			if ( ! str_contains( $output, 'GPTBot' ) ) {
				$parts[] = $this->build_bot_rules();
			}
		}

		if ( (bool) get_option( 'agent_ready_enable_content_signals', true ) ) {
			if ( ! str_contains( $output, 'Content-Signal' ) ) {
				$parts[] = $this->build_content_signals();
			}
		}

		if ( empty( $parts ) ) {
			return $output;
		}

		return $output . "\n" . implode( "\n", $parts );
	}

	// -------------------------------------------------------------------------
	// AI Bot Allow Rules
	// -------------------------------------------------------------------------

	public function build_bot_rules(): string {
		$path  = wp_parse_url( home_url(), PHP_URL_PATH ) ?: '/';
		$lines = [ '# AI Agent Allow Rules — Added by Agent Ready Plugin' ];

		foreach ( $this->get_bots() as $bot => $allow ) {
			$lines[] = '';
			$lines[] = "User-agent: {$bot}";
			$lines[] = $allow ? "Allow: {$path}" : 'Disallow: /';
		}

		$lines[] = '';
		$lines[] = 'Sitemap: ' . home_url( '/wp-sitemap.xml' );
		$lines[] = 'Sitemap: ' . home_url( '/llms.txt' );

		return implode( "\n", $lines );
	}

	/** @return array<string, bool> bot_name => allowed */
	public function get_bots(): array {
		return (array) apply_filters( 'agent_ready_ai_bots', [
			'GPTBot'        => true,
			'ChatGPT-User'  => true,
			'ClaudeBot'     => true,
			'Claude-Web'    => true,
			'anthropic-ai'  => true,
			'PerplexityBot' => true,
			'cohere-ai'     => true,
			'YouBot'        => true,
			'Googlebot'     => true,
			'Bingbot'       => true,
		] );
	}

	// -------------------------------------------------------------------------
	// Content Signals (contentsignals.org)
	// -------------------------------------------------------------------------

	public function build_content_signals(): string {
		$ai_train = (bool) get_option( 'agent_ready_cs_ai_train', true )  ? 'yes' : 'no';
		$ai_input = (bool) get_option( 'agent_ready_cs_ai_input', true )  ? 'yes' : 'no';
		$search   = (bool) get_option( 'agent_ready_cs_search',   true )  ? 'yes' : 'no';

		$lines   = [];
		$lines[] = '# Content Signals (contentsignals.org) — Added by Agent Ready Plugin';
		$lines[] = '';
		$lines[] = 'User-agent: *';
		$lines[] = "Content-Signal: ai-train={$ai_train}, ai-input={$ai_input}, search={$search}";
		$lines[] = '';
		$lines[] = '# MCP Server Card Discovery';
		$lines[] = 'MCP-Server-Card: ' . home_url( '/.well-known/mcp/server-card.json' );
		$lines[] = 'Agent-Skills: ' . home_url( '/.well-known/agent-skills/index.json' );

		return implode( "\n", $lines );
	}

	public function get_preview(): string {
		return $this->build_bot_rules() . "\n\n" . $this->build_content_signals();
	}
}
