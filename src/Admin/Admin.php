<?php

namespace AgentReady\Admin;

defined( 'ABSPATH' ) || exit;

use AgentReady\Scanner\Scanner;
use AgentReady\Support\ContentAnalyzer;
use AgentReady\Features\LLMS;
use AgentReady\Features\Robots;
use AgentReady\Features\Schema;
use AgentReady\Features\AIPage;

/**
 * Registers the admin menu, enqueues assets, and renders all settings pages.
 */
class Admin {

	/** @var array<string, array{title:string, cb:callable}> */
	private array $pages;

	public function init(): void {
		$this->pages = [
			'agent-ready'         => [ 'title' => 'Dashboard',     'cb' => [ $this, 'page_dashboard' ] ],
			'agent-ready-scanner' => [ 'title' => 'Scanner',       'cb' => [ $this, 'page_scanner' ] ],
			'agent-ready-llms'    => [ 'title' => 'llms.txt',      'cb' => [ $this, 'page_llms' ] ],
			'agent-ready-robots'  => [ 'title' => 'Robots',        'cb' => [ $this, 'page_robots' ] ],
			'agent-ready-schema'  => [ 'title' => 'Schema',        'cb' => [ $this, 'page_schema' ] ],
			'agent-ready-markdown'=> [ 'title' => 'Markdown',      'cb' => [ $this, 'page_markdown' ] ],
			'agent-ready-ai-page' => [ 'title' => 'AI Page',       'cb' => [ $this, 'page_ai_page' ] ],
			'agent-ready-content' => [ 'title' => 'Content Audit', 'cb' => [ $this, 'page_content' ] ],
		];

		add_action( 'admin_menu',                                          [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts',                               [ $this, 'enqueue_assets' ] );
		add_filter( 'plugin_action_links_' . AGENT_READY_BASENAME,        [ $this, 'plugin_links' ] );

		add_action( 'admin_post_ar_save_settings',  [ $this, 'handle_save_settings' ] );
		add_action( 'admin_post_ar_save_llms',      [ $this, 'handle_save_llms' ] );
		add_action( 'admin_post_ar_create_ai_page', [ $this, 'handle_create_ai_page' ] );
		add_action( 'admin_post_ar_run_scan',        [ $this, 'handle_run_scan' ] );
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	public function register_menu(): void {
		add_menu_page( 'Agent Ready', 'Agent Ready', 'manage_options', 'agent-ready', [ $this, 'page_dashboard' ], 'dashicons-superhero', 80 );

		foreach ( $this->pages as $slug => $page ) {
			add_submenu_page( 'agent-ready', $page['title'], $page['title'], 'manage_options', $slug, $page['cb'] );
		}
	}

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'agent-ready' ) ) return;

		wp_enqueue_style(  'agent-ready-admin', AGENT_READY_URL . 'assets/admin.css', [], AGENT_READY_VERSION );
		wp_enqueue_script( 'agent-ready-admin', AGENT_READY_URL . 'assets/admin.js',  [ 'jquery' ], AGENT_READY_VERSION, true );
		wp_localize_script( 'agent-ready-admin', 'agentReady', [
			'nonce'   => wp_create_nonce( 'agent_ready_admin' ),
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		] );
	}

	public function plugin_links( array $links ): array {
		array_unshift( $links, '<a href="' . admin_url( 'admin.php?page=agent-ready' ) . '">' . __( 'Settings', 'agent-ready' ) . '</a>' );
		return $links;
	}

	// -------------------------------------------------------------------------
	// Form Handlers
	// -------------------------------------------------------------------------

	public function handle_save_settings(): void {
		check_admin_referer( 'ar_save_settings' );
		$this->cap_check();

		$checkboxes = [
			'enable_llms_txt', 'enable_robots_rules', 'enable_content_signals',
			'cs_ai_train', 'cs_ai_input', 'cs_search',
			'enable_schema', 'enable_markdown_negotiation',
		];
		foreach ( $checkboxes as $k ) {
			update_option( 'agent_ready_' . $k, isset( $_POST[ $k ] ) );
		}

		$allowed_types = [ 'auto', 'blog', 'business', 'saas', 'plugin', 'ecommerce' ];
		$schema_type   = sanitize_text_field( wp_unslash( $_POST['schema_type'] ?? 'auto' ) );
		update_option( 'agent_ready_schema_type',        in_array( $schema_type, $allowed_types, true ) ? $schema_type : 'auto' );
		update_option( 'agent_ready_schema_custom_json', sanitize_textarea_field( wp_unslash( $_POST['schema_custom_json'] ?? '' ) ) );

		flush_rewrite_rules();
		( new Scanner() )->invalidate();

		$this->redirect_back( 'agent-ready', 'saved=1' );
	}

	public function handle_save_llms(): void {
		check_admin_referer( 'ar_save_llms' );
		$this->cap_check();

		update_option( 'agent_ready_llms_txt_content', sanitize_textarea_field( wp_unslash( $_POST['llms_txt_content'] ?? '' ) ) );
		( new Scanner() )->invalidate();

		$this->redirect_back( 'agent-ready-llms', 'saved=1' );
	}

	public function handle_create_ai_page(): void {
		check_admin_referer( 'ar_create_ai_page' );
		$this->cap_check();

		$result = ( new AIPage() )->create();
		$this->redirect_back( 'agent-ready-ai-page', 'status=' . ( $result['success'] ? 'created' : 'error' ) );
	}

	public function handle_run_scan(): void {
		check_admin_referer( 'ar_run_scan' );
		$this->cap_check();

		( new Scanner() )->run( true );
		$this->redirect_back( 'agent-ready-scanner', 'scanned=1' );
	}

	// -------------------------------------------------------------------------
	// Pages
	// -------------------------------------------------------------------------

	public function page_dashboard(): void {
		$scan  = ( new Scanner() )->run();
		$saved = $this->flash( 'saved' );
		$this->header( 'Dashboard' );
		?>
		<?php if ( $saved ) $this->notice( 'Settings saved.' ); ?>

		<div class="ar-score-card">
			<div class="ar-score-circle ar-grade-<?php echo esc_attr( strtolower( $scan['grade'] ) ); ?>">
				<span class="ar-score-number"><?php echo (int) $scan['score']; ?></span>
				<span class="ar-score-label">/100</span>
			</div>
			<div class="ar-score-meta">
				<h2><?php esc_html_e( 'AI Readiness Score', 'agent-ready' ); ?></h2>
				<p><?php printf( esc_html__( 'Grade: %s', 'agent-ready' ), '<strong>' . esc_html( $scan['grade'] ) . '</strong>' ); ?></p>
				<p class="description"><?php printf( esc_html__( 'Last scanned: %s', 'agent-ready' ), esc_html( $scan['scanned_at'] ) ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=agent-ready-scanner' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Full Report', 'agent-ready' ); ?></a>
			</div>
		</div>

		<div class="ar-checks-grid">
			<?php foreach ( $scan['checks'] as $check ) : ?>
				<div class="ar-check-item <?php echo $check['pass'] ? 'ar-pass' : 'ar-fail'; ?>">
					<span class="ar-check-icon"><?php echo $check['pass'] ? '✓' : '✗'; ?></span>
					<span class="ar-check-label"><?php echo esc_html( $check['label'] ); ?></span>
					<?php if ( ! $check['pass'] && $check['fix'] ) : ?>
						<span class="ar-check-tip"><?php echo esc_html( $check['fix'] ); ?></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<h3><?php esc_html_e( 'Quick Toggles', 'agent-ready' ); ?></h3>
		<?php $this->settings_form_open( 'ar_save_settings' ); ?>
		<table class="form-table">
			<tr><th colspan="2"><strong>Discoverability</strong></th></tr>
			<?php $this->toggle_row( 'enable_llms_txt',             'llms.txt',             'Serve /llms.txt for AI agents' ); ?>
			<?php $this->toggle_row( 'enable_schema',               'JSON-LD Schema',       'Inject structured data in page head' ); ?>
			<tr><th colspan="2"><strong>Content Accessibility</strong></th></tr>
			<?php $this->toggle_row( 'enable_markdown_negotiation', 'Markdown Negotiation', 'Serve Markdown on Accept: text/markdown + /index.md URLs' ); ?>
			<tr><th colspan="2"><strong>Bot Access Control</strong></th></tr>
			<?php $this->toggle_row( 'enable_robots_rules',         'AI Bot Rules',         'Append GPTBot / ClaudeBot / PerplexityBot rules to robots.txt' ); ?>
			<?php $this->toggle_row( 'enable_content_signals',      'Content Signals',      'Add Content-Signal directive to robots.txt (contentsignals.org)' ); ?>
		</table>
		<?php submit_button( 'Save Settings' ); ?>
		</form>

		<?php $this->footer();
	}

	public function page_scanner(): void {
		$scan    = ( new Scanner() )->run();
		$scanned = $this->flash( 'scanned' );
		$this->header( 'AI Readiness Scanner' );
		?>
		<?php if ( $scanned ) $this->notice( 'Scan complete!' ); ?>

		<div class="ar-scanner-summary">
			<div class="ar-stat"><strong><?php echo (int) $scan['score']; ?>/100</strong><span>Score</span></div>
			<div class="ar-stat"><strong><?php echo esc_html( $scan['grade'] ); ?></strong><span>Grade</span></div>
			<div class="ar-stat"><strong><?php echo count( $scan['issues'] ); ?></strong><span>Issues</span></div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:20px">
			<?php wp_nonce_field( 'ar_run_scan' ); ?>
			<input type="hidden" name="action" value="ar_run_scan">
			<?php submit_button( 'Re-run Scan', 'secondary', 'submit', false ); ?>
		</form>

		<?php
		$pillars = [
			'Discoverability'     => [ 'llms_txt', 'json_ld', 'heading_structure', 'internal_linking' ],
			'Content Accessibility' => [ 'markdown_negotiation' ],
			'Bot Access Control'  => [ 'robots_rules', 'content_signals' ],
		];
		foreach ( $pillars as $pillar => $keys ) :
		?>
		<h3><?php echo esc_html( $pillar ); ?></h3>
		<table class="widefat striped" style="margin-bottom:20px">
			<thead><tr><th>Check</th><th>Status</th><th>Weight</th><th>Fix</th></tr></thead>
			<tbody>
			<?php foreach ( $keys as $key ) :
				$check = $scan['checks'][ $key ] ?? null;
				if ( ! $check ) continue;
			?>
				<tr>
					<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
					<td><span class="ar-status <?php echo $check['pass'] ? 'ar-pass' : 'ar-fail'; ?>"><?php echo $check['pass'] ? 'Pass' : 'Fail'; ?></span></td>
					<td><?php echo (int) $check['weight']; ?>%</td>
					<td><?php echo $check['pass'] ? '—' : esc_html( $check['fix'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endforeach; ?>

		<?php if ( $scan['issues'] ) : ?>
			<h3>Issues</h3>
			<ul class="ar-issues-list">
				<?php foreach ( $scan['issues'] as $issue ) : ?>
					<li class="ar-issue"><?php echo esc_html( $issue ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php $this->footer();
	}

	public function page_llms(): void {
		$saved   = $this->flash( 'saved' );
		$llms    = new LLMS();
		$current = (string) get_option( 'agent_ready_llms_txt_content', '' );
		$this->header( 'llms.txt Editor' );
		?>
		<?php if ( $saved ) $this->notice( 'llms.txt saved.' ); ?>
		<p><?php printf( 'Served at: <a href="%s" target="_blank">%s</a>', esc_url( home_url( '/llms.txt' ) ), esc_url( home_url( '/llms.txt' ) ) ); ?></p>

		<div class="ar-two-col">
			<div class="ar-col">
				<h3>Custom Content</h3>
				<p>Leave blank to use auto-generated content.</p>
				<?php $this->settings_form_open( 'ar_save_llms', 'agent-ready-llms' ); ?>
				<textarea name="llms_txt_content" rows="25" class="large-text code"><?php echo esc_textarea( $current ); ?></textarea>
				<?php submit_button( 'Save llms.txt' ); ?>
				</form>
			</div>
			<div class="ar-col">
				<h3>Auto-Generated Preview</h3>
				<pre class="ar-preview"><?php echo esc_html( $llms->generate() ); ?></pre>
			</div>
		</div>
		<?php $this->footer();
	}

	public function page_robots(): void {
		$saved  = $this->flash( 'saved' );
		$robots = new Robots();
		$this->header( 'Robots.txt — AI Rules &amp; Content Signals' );
		?>
		<?php if ( $saved ) $this->notice( 'Settings saved.' ); ?>
		<?php $this->settings_form_open( 'ar_save_settings' ); ?>

		<h3>AI Bot Rules <span style="font-size:11px;font-weight:400;color:#64748b">— Bot Access Control</span></h3>
		<p>Allow known AI crawlers (GPTBot, ClaudeBot, PerplexityBot, etc.) to index your site.</p>
		<table class="form-table">
			<?php $this->toggle_row( 'enable_robots_rules', 'Enable AI Bot Allow Rules', 'Append allow rules for AI crawlers to robots.txt' ); ?>
		</table>

		<h3>Content Signals <span style="font-size:11px;font-weight:400;color:#64748b">— contentsignals.org</span></h3>
		<p>Declare your content licensing intent for AI systems via a <code>Content-Signal</code> directive in robots.txt.</p>
		<table class="form-table">
			<?php $this->toggle_row( 'enable_content_signals', 'Enable Content Signals',   'Append Content-Signal directive to robots.txt' ); ?>
			<?php $this->toggle_row( 'cs_ai_train',            'Allow AI Training',        'ai-train=yes — AI models may train on this content' ); ?>
			<?php $this->toggle_row( 'cs_ai_input',            'Allow AI Inference',       'ai-input=yes — AI models may use this content for inference/grounding' ); ?>
			<?php $this->toggle_row( 'cs_search',              'Allow Search Indexing',    'search=yes — content may appear in search results' ); ?>
		</table>
		<?php submit_button( 'Save' ); ?>
		</form>

		<h3>Preview — What Will Be Appended</h3>
		<p><?php printf( 'Live robots.txt: <a href="%s" target="_blank">%s</a>', esc_url( home_url( '/robots.txt' ) ), esc_url( home_url( '/robots.txt' ) ) ); ?></p>
		<pre class="ar-preview"><?php echo esc_html( $robots->get_preview() ); ?></pre>
		<?php $this->footer();
	}

	public function page_schema(): void {
		$saved  = $this->flash( 'saved' );
		$schema = new Schema();
		$this->header( 'JSON-LD Schema' );
		?>
		<?php if ( $saved ) $this->notice( 'Settings saved.' ); ?>
		<?php $this->settings_form_open( 'ar_save_settings' ); ?>
		<table class="form-table">
			<?php $this->toggle_row( 'enable_schema', 'Enable Schema', 'Inject JSON-LD into page head' ); ?>
			<tr>
				<th>Site Type</th>
				<td>
					<select name="schema_type">
						<?php
						$current = get_option( 'agent_ready_schema_type', 'auto' );
						foreach ( [ 'auto' => 'Auto Detect', 'blog' => 'Blog', 'business' => 'Business / Org', 'saas' => 'SaaS / Web App', 'plugin' => 'Plugin / Software', 'ecommerce' => 'E-commerce' ] as $v => $l ) {
							printf( '<option value="%s" %s>%s</option>', esc_attr( $v ), selected( $current, $v, false ), esc_html( $l ) );
						}
						?>
					</select>
				</td>
			</tr>
			<tr>
				<th>Custom JSON Override</th>
				<td>
					<textarea name="schema_custom_json" rows="10" class="large-text code"><?php echo esc_textarea( (string) get_option( 'agent_ready_schema_custom_json', '' ) ); ?></textarea>
					<p class="description">Paste a complete JSON-LD object to override auto-generation. Leave blank for auto.</p>
				</td>
			</tr>
		</table>
		<?php submit_button( 'Save Schema Settings' ); ?>
		</form>

		<h3>Auto-Generated Preview</h3>
		<pre class="ar-preview"><?php echo esc_html( $schema->get_preview() ); ?></pre>
		<?php $this->footer();
	}

	public function page_markdown(): void {
		$saved = $this->flash( 'saved' );
		$this->header( 'Markdown Negotiation' );
		?>
		<?php if ( $saved ) $this->notice( 'Settings saved.' ); ?>

		<p>When an AI agent sends <code>Accept: text/markdown</code>, your site will respond with <code>Content-Type: text/markdown</code> instead of HTML. This is the <strong>Markdown for Agents</strong> protocol required by isitagentready.com and the Cloudflare AI spec.</p>

		<?php $this->settings_form_open( 'ar_save_settings' ); ?>
		<table class="form-table">
			<?php $this->toggle_row( 'enable_markdown_negotiation', 'Enable Markdown Negotiation', 'Serve text/markdown when Accept: text/markdown header is present' ); ?>
		</table>
		<?php submit_button( 'Save' ); ?>
		</form>

		<h3>How It Works</h3>
		<ul>
			<li><strong>Trigger 1:</strong> Request includes <code>Accept: text/markdown</code> → current page is served as Markdown.</li>
			<li><strong>Trigger 2:</strong> URL ends with <code>/index.md</code> (e.g. <code>/about/index.md</code>) → that page is served as Markdown.</li>
			<li>HTML stays the default for browsers — no change for regular visitors.</li>
			<li>Response headers include <code>x-markdown-tokens</code> (token estimate), <code>Vary: Accept</code>, and <code>Content-Signal</code>.</li>
		</ul>

		<h3>Test It</h3>
		<p>Run this from your terminal to verify:</p>
		<pre class="ar-preview">curl -H "Accept: text/markdown" <?php echo esc_url( home_url( '/' ) ); ?></pre>
		<p>Or open: <a href="<?php echo esc_url( home_url( '/index.md' ) ); ?>" target="_blank"><?php echo esc_url( home_url( '/index.md' ) ); ?></a></p>
		<?php $this->footer();
	}

	public function page_ai_page(): void {
		$status  = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore
		$ai_page = get_page_by_path( 'ai' );
		$this->header( 'AI Landing Page' );
		?>
		<?php if ( $status === 'created' ) $this->notice( 'AI page created/updated!' ); ?>
		<?php if ( $status === 'error' )   $this->notice( 'Error creating AI page. Please try again.', 'error' ); ?>

		<?php if ( $ai_page ) : ?>
			<div class="notice notice-info inline"><p>
				Page exists: <a href="<?php echo esc_url( get_permalink( $ai_page ) ); ?>" target="_blank"><?php echo esc_url( get_permalink( $ai_page ) ); ?></a>
				&nbsp;|&nbsp;<a href="<?php echo esc_url( get_edit_post_link( $ai_page ) ); ?>">Edit in Gutenberg</a>
			</p></div>
		<?php endif; ?>

		<p>Creates a page at <code>/ai/</code> pre-filled with your site description, llms.txt link, sitemap, and key pages. Fully editable via Gutenberg after creation.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ar_create_ai_page' ); ?>
			<input type="hidden" name="action" value="ar_create_ai_page">
			<?php submit_button( $ai_page ? 'Regenerate AI Page Content' : 'Create AI Landing Page (/ai/)' ); ?>
		</form>
		<?php $this->footer();
	}

	public function page_content(): void {
		$report = ( new ContentAnalyzer() )->analyze();
		$this->header( 'Content Structure Audit' );
		?>
		<div class="ar-scanner-summary">
			<div class="ar-stat"><strong><?php echo (int) $report['total_posts']; ?></strong><span>Analyzed</span></div>
			<div class="ar-stat"><strong><?php echo (int) $report['posts_with_issues']; ?></strong><span>With Issues</span></div>
			<div class="ar-stat"><strong><?php echo (int) $report['total_issues']; ?></strong><span>Total Issues</span></div>
		</div>

		<?php if ( empty( $report['items'] ) ) : ?>
			<?php $this->notice( 'No content issues detected — great job!' ); ?>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th>Post / Page</th><th>Type</th><th>Issues</th></tr></thead>
				<tbody>
				<?php foreach ( $report['items'] as $item ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank"><?php echo esc_html( $item['title'] ); ?></a>
							<div class="row-actions"><a href="<?php echo esc_url( (string) get_edit_post_link( $item['id'] ) ); ?>">Edit</a></div>
						</td>
						<td><code><?php echo esc_html( $item['type'] ); ?></code></td>
						<td>
							<ul class="ar-issue-list">
							<?php foreach ( $item['issues'] as $issue ) : ?>
								<li class="ar-severity-<?php echo esc_attr( $issue['severity'] ); ?>">
									<strong><?php echo esc_html( ucfirst( $issue['severity'] ) ); ?>:</strong>
									<?php echo esc_html( $issue['message'] ); ?>
								</li>
							<?php endforeach; ?>
							</ul>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<p class="description">Last analyzed: <?php echo esc_html( $report['analyzed_at'] ); ?></p>
		<?php $this->footer();
	}

	// -------------------------------------------------------------------------
	// UI Helpers
	// -------------------------------------------------------------------------

	private function header( string $title ): void {
		$score   = ( new Scanner() )->run()['score'];
		$current = sanitize_text_field( wp_unslash( $_GET['page'] ?? 'agent-ready' ) ); // phpcs:ignore
		?>
		<div class="wrap ar-wrap">
		<div class="ar-page-header">
			<h1 class="ar-page-title"><?php echo esc_html( $title ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=agent-ready-scanner' ) ); ?>" class="ar-mini-score">
				Score: <strong><?php echo (int) $score; ?>/100</strong>
			</a>
		</div>
		<nav class="ar-subnav">
			<?php foreach ( $this->pages as $slug => $page ) :
				printf(
					'<a href="%s" class="ar-subnav-item %s">%s</a>',
					esc_url( admin_url( 'admin.php?page=' . $slug ) ),
					esc_attr( $current === $slug ? 'ar-active' : '' ),
					esc_html( $page['title'] )
				);
			endforeach; ?>
		</nav>
		<?php
	}

	private function footer(): void {
		echo '</div>'; // .wrap.ar-wrap
	}

	private function notice( string $message, string $type = 'success' ): void {
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	private function toggle_row( string $key, string $label, string $description ): void {
		printf(
			'<tr><th>%s</th><td><label><input type="checkbox" name="%s" %s> %s</label></td></tr>',
			esc_html( $label ),
			esc_attr( $key ),
			checked( get_option( 'agent_ready_' . $key, false ), true, false ),
			esc_html( $description )
		);
	}

	private function settings_form_open( string $nonce_action, string $redirect_page = 'agent-ready' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( $nonce_action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $nonce_action ) . '">';
	}

	private function redirect_back( string $page, string $query = '' ): void {
		$url = admin_url( 'admin.php?page=' . $page . ( $query ? '&' . $query : '' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function flash( string $key ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification
		return isset( $_GET[ $key ] );
	}

	private function cap_check(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'agent-ready' ) );
		}
	}
}
