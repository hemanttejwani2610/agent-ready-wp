<?php

namespace AgentReady;

defined( 'ABSPATH' ) || exit;

use AgentReady\Features\LLMS;
use AgentReady\Features\Robots;
use AgentReady\Features\Schema;
use AgentReady\Features\MarkdownNegotiation;
use AgentReady\Features\AIPage;
use AgentReady\Admin\Admin;

/**
 * Central service locator / bootstrapper.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	/** @var array<string, object> Registered feature instances. */
	private array $services = [];

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		$this->register_services();
		$this->init_services();
	}

	private function register_services(): void {
		$this->services = [
			'llms'                 => new LLMS(),
			'robots'               => new Robots(),
			'schema'               => new Schema(),
			'markdown_negotiation' => new MarkdownNegotiation(),
			'ai_page'              => new AIPage(),
			'admin'                => new Admin(),
		];
	}

	private function init_services(): void {
		foreach ( $this->services as $service ) {
			if ( method_exists( $service, 'init' ) ) {
				$service->init();
			}
		}
	}

	/** Retrieve a registered service by key. */
	public function get( string $key ): ?object {
		return $this->services[ $key ] ?? null;
	}

	// -------------------------------------------------------------------------
	// Lifecycle hooks
	// -------------------------------------------------------------------------

	public static function activate(): void {
		$defaults = [
			'enable_llms_txt'             => true,
			'enable_robots_rules'         => true,
			'enable_content_signals'      => true,
			'cs_ai_train'                 => true,
			'cs_ai_input'                 => true,
			'cs_search'                   => true,
			'enable_schema'               => true,
			'enable_markdown_negotiation' => true,
			'llms_txt_content'            => '',
			'schema_type'                 => 'auto',
			'schema_custom_json'          => '',
			'ai_page_created'             => false,
		];

		foreach ( $defaults as $key => $value ) {
			if ( false === get_option( 'agent_ready_' . $key ) ) {
				update_option( 'agent_ready_' . $key, $value );
			}
		}

		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
