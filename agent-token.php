<?php
/**
 * Plugin Name:       Agent Token
 * Plugin URI:        https://github.com/marsdream/agent-token
 * Description:       Give your AI agent a single, revocable token for the WordPress REST API. The token is sent in a custom header (X-Agent-Token) over HTTPS and acts as a WordPress user you choose. No username and password pairs, no Basic Auth.
 * Version:           1.0.0
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agent-token
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 *
 * Security notes:
 * - Only a SHA-256 hash of the token is stored in the database; the plain
 *   token is shown exactly once, when it is generated.
 * - Tokens are only honoured on HTTPS requests.
 * - The token acts as one admin-chosen existing user who must have the
 *   edit_posts capability. Its permissions are exactly that user's.
 * - When no token is configured, or after revocation, this plugin
 *   authenticates nothing.
 * - No telemetry, no external requests: this plugin never phones home.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Agent_Token {

	const VERSION       = '1.0.0';
	const OPTION_KEY    = 'agent_token_settings';
	const TRANSIENT_KEY = 'agent_token_plain_'; // Suffixed with the admin user ID.
	const HEADER_NAME   = 'X-Agent-Token';

	/** Load settings with defaults. */
	private static function settings() {
		$defaults = array(
			'token_hash' => '',
			'user_id'    => 0,
		);
		$saved    = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/** Save settings. */
	private static function save_settings( $settings ) {
		update_option( self::OPTION_KEY, $settings );
	}

	/** Register hooks. */
	public static function init() {
		add_filter( 'determine_current_user', array( __CLASS__, 'authenticate' ), 30 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_post_agent_token_action', array( __CLASS__, 'handle_action' ) );
	}

	/**
	 * Core auth: a request carrying a valid X-Agent-Token header over HTTPS
	 * is authenticated as the mapped user.
	 *
	 * @param int|false $user_id User already determined by another method.
	 * @return int|false
	 */
	public static function authenticate( $user_id ) {
		if ( $user_id ) {
			return $user_id; // Already authenticated (cookie, app password, ...). Do not interfere.
		}
		if ( empty( $_SERVER['HTTP_X_AGENT_TOKEN'] ) ) {
			return $user_id;
		}
		// HTTPS only, so the token never travels in clear text.
		if ( ! is_ssl() ) {
			return $user_id;
		}
		$settings = self::settings();
		// No token configured or revoked: never authenticate anything.
		if ( empty( $settings['token_hash'] ) || empty( $settings['user_id'] ) ) {
			return $user_id;
		}
		$given = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_AGENT_TOKEN'] ) );
		if ( ! hash_equals( $settings['token_hash'], hash( 'sha256', $given ) ) ) {
			return $user_id;
		}
		$user = get_user_by( 'id', (int) $settings['user_id'] );
		if ( ! $user || ! user_can( $user, 'edit_posts' ) ) {
			return $user_id;
		}
		return $user->ID;
	}

	/** Register the public, read-only agent discovery manifest route. */
	public static function register_routes() {
		register_rest_route(
			'agent-token/v1',
			'/manifest',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_manifest' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /wp-json/agent-token/v1/manifest
	 *
	 * Public discovery document for agents. Contains no secrets: never the
	 * token, its hash, or any personal data (such as an email address).
	 */
	public static function rest_manifest() {
		$settings = self::settings();
		$mapped   = null;

		if ( ! empty( $settings['user_id'] ) ) {
			$user = get_user_by( 'id', (int) $settings['user_id'] );
			if ( $user && user_can( $user, 'edit_posts' ) ) {
				$mapped = array(
					'roles'        => array_values( $user->roles ),
					'capabilities' => self::capability_summary( $user ),
				);
			}
		}

		$data = array(
			'plugin'           => 'agent-token',
			'version'          => self::VERSION,
			'site_url'         => get_site_url(),
			'rest_url'         => rest_url(),
			'auth'             => array(
				'type'   => 'header',
				'header' => self::HEADER_NAME,
				'scheme' => 'https',
			),
			'token_configured' => ( ! empty( $settings['token_hash'] ) && null !== $mapped ),
			'mapped_user'      => $mapped,
		);

		return rest_ensure_response( $data );
	}

	/** Summarise the content capabilities a mapped user has, as booleans. */
	private static function capability_summary( $user ) {
		$keys    = array(
			'read',
			'edit_posts',
			'publish_posts',
			'edit_others_posts',
			'delete_posts',
			'edit_pages',
			'publish_pages',
			'upload_files',
			'moderate_comments',
		);
		$summary = array();
		foreach ( $keys as $cap ) {
			$summary[ $cap ] = user_can( $user, $cap );
		}
		return $summary;
	}

	/** Add the settings page under the Settings menu. */
	public static function add_settings_page() {
		add_options_page(
			'Agent Token',
			'Agent Token',
			'manage_options',
			'agent-token',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/** Handle generate, revoke, and mapped-user save actions. */
	public static function handle_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'agent-token' ) );
		}
		check_admin_referer( 'agent_token_action' );

		$action   = isset( $_POST['agent_action'] ) ? sanitize_key( wp_unslash( $_POST['agent_action'] ) ) : '';
		$settings = self::settings();

		if ( 'generate' === $action ) {
			$plain                  = wp_generate_password( 48, false );
			$settings['token_hash'] = hash( 'sha256', $plain );
			if ( isset( $_POST['agent_user_id'] ) ) {
				$settings['user_id'] = (int) $_POST['agent_user_id'];
			}
			self::save_settings( $settings );
			// The plain token is handed to the generating admin exactly once,
			// via a one-time transient that expires after 10 minutes.
			set_transient( self::TRANSIENT_KEY . get_current_user_id(), $plain, 10 * MINUTE_IN_SECONDS );
		} elseif ( 'revoke' === $action ) {
			$settings['token_hash'] = '';
			self::save_settings( $settings );
		} elseif ( 'save_user' === $action ) {
			if ( isset( $_POST['agent_user_id'] ) ) {
				$settings['user_id'] = (int) $_POST['agent_user_id'];
			}
			self::save_settings( $settings );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=agent-token' ) );
		exit;
	}

	/** Render the settings page. */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'agent-token' ) );
		}
		$settings = self::settings();
		$plain    = get_transient( self::TRANSIENT_KEY . get_current_user_id() );
		if ( false !== $plain ) {
			delete_transient( self::TRANSIENT_KEY . get_current_user_id() );
		}
		$users = get_users(
			array(
				'capability' => 'edit_posts',
				'orderby'    => 'display_name',
			)
		);
		?>
		<div class="wrap">
			<h1>Agent Token</h1>
			<p>
			<?php
			printf(
				/* translators: %s: the HTTP header name the agent sends the token in. */
				esc_html__( 'Agent Token lets an AI agent or automation tool authenticate to the WordPress REST API with a single token, sent in the %s header over HTTPS. The token acts as the user you map below. Only a hash of the token is stored; the token itself is shown once, when generated.', 'agent-token' ),
				esc_html( self::HEADER_NAME )
			);
			?>
			</p>

			<?php if ( false !== $plain ) : ?>
				<?php
				$manifest_url = rest_url( 'agent-token/v1/manifest' );
				$snippet      = sprintf(
					"WordPress site: %s\nREST API base: %s\nAuth: send the header \"%s: %s\" over HTTPS\nDiscovery manifest: %s",
					get_site_url(),
					rest_url(),
					self::HEADER_NAME,
					$plain,
					$manifest_url
				);
				?>
				<div class="notice notice-success" style="padding:12px;">
					<p><strong><?php esc_html_e( 'New token generated. Copy it now - it will not be shown again, and any previous token has stopped working.', 'agent-token' ); ?></strong></p>
					<p><input type="text" readonly value="<?php echo esc_attr( $plain ); ?>" style="width:520px;max-width:100%;" onclick="this.select();" /></p>
					<p><strong><?php esc_html_e( 'Setup snippet - paste this to your agent:', 'agent-token' ); ?></strong></p>
					<p><textarea readonly rows="5" style="width:640px;max-width:100%;" onclick="this.select();"><?php echo esc_textarea( $snippet ); ?></textarea></p>
					<p><?php esc_html_e( 'This snippet contains the only copy of the token. Keep it in your agent credential vault, not in plain-text files or chat logs.', 'agent-token' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $settings['token_hash'] ) ) : ?>
				<p><?php esc_html_e( 'Status: active (a token is currently in effect).', 'agent-token' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Status: inactive (no token has been generated yet).', 'agent-token' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'agent_token_action' ); ?>
				<input type="hidden" name="action" value="agent_token_action" />
				<table class="form-table">
					<tr>
						<th scope="row"><label for="agent_user_id"><?php esc_html_e( 'Mapped user', 'agent-token' ); ?></label></th>
						<td>
							<select name="agent_user_id" id="agent_user_id">
								<?php foreach ( $users as $u ) : ?>
									<option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( (int) $settings['user_id'], $u->ID ); ?>>
										<?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The token can do exactly what this user can do. Create a dedicated low-privilege user for your agent (Editor is typical) instead of using your main admin account.', 'agent-token' ); ?></p>
						</td>
					</tr>
				</table>
				<p>
					<button type="submit" name="agent_action" value="generate" class="button button-primary"><?php esc_html_e( 'Generate new token', 'agent-token' ); ?></button>
					<button type="submit" name="agent_action" value="save_user" class="button"><?php esc_html_e( 'Save mapped user only', 'agent-token' ); ?></button>
					<?php if ( ! empty( $settings['token_hash'] ) ) : ?>
						<button type="submit" name="agent_action" value="revoke" class="button" onclick="return confirm('<?php echo esc_js( __( 'Revoke the current token? It will stop working immediately.', 'agent-token' ) ); ?>');"><?php esc_html_e( 'Revoke token', 'agent-token' ); ?></button>
					<?php endif; ?>
				</p>
			</form>

			<h2><?php esc_html_e( 'Agent discovery', 'agent-token' ); ?></h2>
			<p><?php esc_html_e( 'Agents can discover how to connect through the public manifest endpoint:', 'agent-token' ); ?></p>
			<p><code><?php echo esc_url( rest_url( 'agent-token/v1/manifest' ) ); ?></code></p>
			<p class="description"><?php esc_html_e( 'The manifest is public and read-only. It never contains the token, its hash, or any personal data.', 'agent-token' ); ?></p>
			<hr />
			<p class="description">
			<?php
			printf(
				/* translators: %s: link to the author's Ko-fi page. */
				esc_html__( 'Agent Token is free and open source. If it saves you time, you can %s.', 'agent-token' ),
				'<a href="https://ko-fi.com/aitoken" target="_blank" rel="noopener noreferrer">' . esc_html__( 'support its development on Ko-fi', 'agent-token' ) . '</a>'
			);
			?>
			</p>
		</div>
		<?php
	}
}

Agent_Token::init();
