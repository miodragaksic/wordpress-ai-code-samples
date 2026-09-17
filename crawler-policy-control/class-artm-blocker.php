<?php
/**
 * Adds robots.txt directives and optional request enforcement.
 *
 * @package AISignalMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ARTM_Blocker {
	public function register() {
		add_filter( 'robots_txt', array( $this, 'filter_robots' ), 99, 2 );
		add_action( 'template_redirect', array( $this, 'maybe_block' ), 0 );
	}

	/**
	 * Appends transparent crawler-specific rules to virtual robots.txt.
	 *
	 * @param string $output Existing robots.txt.
	 * @param bool   $public Whether search visibility is enabled.
	 * @return string
	 */
	public function filter_robots( $output, $public ) {
		unset( $public );
		$blocked = $this->blocked_agents();

		if ( empty( $blocked ) ) {
			return $output;
		}

		$output .= "\n# AI crawler controls\n";
		foreach ( $blocked as $agent ) {
			$output .= 'User-agent: ' . $agent['pattern'] . "\nDisallow: /\n\n";
		}

		return $output;
	}

	/**
	 * Returns a hard 403 only when explicitly enabled.
	 */
	public function maybe_block() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_user_logged_in() ) {
			return;
		}

		$settings = $this->settings();
		if ( empty( $settings['hard_block'] ) ) {
			return;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$agent      = ARTM_Detector::detect_agent( $user_agent );

		if ( ! $agent || ! $this->is_blocked( $agent ) ) {
			return;
		}

		ARTM_Logger::insert(
			array(
				'event_type' => 'blocked',
				'provider'   => $agent['provider'],
				'agent'      => $agent['label'],
				'category'   => $agent['category'],
				'status_code'=> 403,
				'user_agent' => $user_agent,
			)
		);

		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html__( 'Access denied by the website owner\'s AI crawler policy.', 'ai-signal-monitor' );
		exit;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function settings() {
		return wp_parse_args(
			get_option( 'artm_settings', array() ),
			array( 'mode' => 'open', 'hard_block' => 0, 'custom_blocks' => array() )
		);
	}

	/**
	 * Determines policy for an agent.
	 *
	 * @param array<string,string> $agent Agent definition.
	 * @return bool
	 */
	private function is_blocked( $agent ) {
		$settings = $this->settings();
		$mode     = $settings['mode'];

		if ( 'protected' === $mode ) {
			return true;
		}

		if ( 'balanced' === $mode ) {
			return in_array( $agent['category'], array( 'training', 'scraper' ), true );
		}

		if ( 'custom' === $mode ) {
			$custom = is_array( $settings['custom_blocks'] ) ? $settings['custom_blocks'] : array();
			return in_array( $agent['slug'], $custom, true );
		}

		return false;
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function blocked_agents() {
		$blocked = array();
		foreach ( ARTM_Detector::agents() as $slug => $agent ) {
			$agent['slug'] = $slug;
			if ( $this->is_blocked( $agent ) ) {
				$blocked[] = $agent;
			}
		}
		return $blocked;
	}
}
