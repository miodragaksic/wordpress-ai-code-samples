<?php
/**
 * Identifies known AI crawlers and AI referral hosts.
 *
 * @package AISignalMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ARTM_Detector {
	/**
	 * Returns supported crawler definitions. Specific patterns must precede broad ones.
	 *
	 * Categories: search, user, training, scraper.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function agents() {
		return apply_filters(
			'artm_agent_definitions',
			array(
				'oai-searchbot'       => array( 'label' => 'OAI-SearchBot', 'provider' => 'OpenAI', 'pattern' => 'OAI-SearchBot', 'category' => 'search' ),
				'chatgpt-user'        => array( 'label' => 'ChatGPT-User', 'provider' => 'OpenAI', 'pattern' => 'ChatGPT-User', 'category' => 'user' ),
				'gptbot'              => array( 'label' => 'GPTBot', 'provider' => 'OpenAI', 'pattern' => 'GPTBot', 'category' => 'training' ),
				'claude-searchbot'    => array( 'label' => 'Claude-SearchBot', 'provider' => 'Anthropic', 'pattern' => 'Claude-SearchBot', 'category' => 'search' ),
				'claude-user'         => array( 'label' => 'Claude-User', 'provider' => 'Anthropic', 'pattern' => 'Claude-User', 'category' => 'user' ),
				'claudebot'           => array( 'label' => 'ClaudeBot', 'provider' => 'Anthropic', 'pattern' => 'ClaudeBot', 'category' => 'training' ),
				'perplexity-user'     => array( 'label' => 'Perplexity-User', 'provider' => 'Perplexity', 'pattern' => 'Perplexity-User', 'category' => 'user' ),
				'perplexitybot'       => array( 'label' => 'PerplexityBot', 'provider' => 'Perplexity', 'pattern' => 'PerplexityBot', 'category' => 'search' ),
				'meta-externalagent'  => array( 'label' => 'Meta-ExternalAgent', 'provider' => 'Meta', 'pattern' => 'Meta-ExternalAgent', 'category' => 'training' ),
				'amazonbot'           => array( 'label' => 'Amazonbot', 'provider' => 'Amazon', 'pattern' => 'Amazonbot', 'category' => 'training' ),
				'applebot'            => array( 'label' => 'Applebot', 'provider' => 'Apple', 'pattern' => 'Applebot', 'category' => 'search' ),
				'bytespider'          => array( 'label' => 'Bytespider', 'provider' => 'ByteDance', 'pattern' => 'Bytespider', 'category' => 'scraper' ),
				'ccbot'               => array( 'label' => 'CCBot', 'provider' => 'Common Crawl', 'pattern' => 'CCBot', 'category' => 'scraper' ),
				'cohere-ai'           => array( 'label' => 'cohere-ai', 'provider' => 'Cohere', 'pattern' => 'cohere-ai', 'category' => 'training' ),
				'you-bot'             => array( 'label' => 'YouBot', 'provider' => 'You.com', 'pattern' => 'YouBot', 'category' => 'search' ),
			)
		);
	}

	/**
	 * Finds an agent definition in a user-agent string.
	 *
	 * @param string $user_agent User-agent header.
	 * @return array<string,string>|null
	 */
	public static function detect_agent( $user_agent ) {
		if ( ! is_string( $user_agent ) || '' === $user_agent ) {
			return null;
		}

		foreach ( self::agents() as $slug => $agent ) {
			if ( false !== stripos( $user_agent, $agent['pattern'] ) ) {
				$agent['slug'] = $slug;
				return $agent;
			}
		}

		return null;
	}

	/**
	 * Returns supported AI referral domains.
	 *
	 * @return array<string,string>
	 */
	public static function referral_hosts() {
		return apply_filters(
			'artm_referral_hosts',
			array(
				'chatgpt.com'             => 'ChatGPT',
				'chat.openai.com'         => 'ChatGPT',
				'claude.ai'               => 'Claude',
				'perplexity.ai'           => 'Perplexity',
				'gemini.google.com'       => 'Gemini',
				'copilot.microsoft.com'   => 'Microsoft Copilot',
				'copilot.cloud.microsoft' => 'Microsoft Copilot',
				'grok.com'                => 'Grok',
				'poe.com'                 => 'Poe',
				'you.com'                 => 'You.com',
			)
		);
	}

	/**
	 * Detects a known AI referrer.
	 *
	 * @param string $referrer Referrer URL.
	 * @return array<string,string>|null
	 */
	public static function detect_referrer( $referrer ) {
		$host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./', '', $host );

		if ( ! $host ) {
			return null;
		}

		foreach ( self::referral_hosts() as $known_host => $label ) {
			$suffix = '.' . $known_host;
			if ( $host === $known_host || ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) ) {
				return array( 'provider' => $label, 'host' => $host );
			}
		}

		return null;
	}

	/**
	 * Detects an AI source explicitly supplied in a campaign parameter.
	 *
	 * ChatGPT Search currently decorates outbound links with
	 * utm_source=chatgpt.com. Values are matched against the same strict host
	 * allow-list as browser referrers; arbitrary UTM values are never accepted.
	 *
	 * @param string $source Campaign source value.
	 * @return array<string,string>|null
	 */
	public static function detect_campaign_source( $source ) {
		$source = strtolower( trim( (string) $source ) );
		$source = preg_replace( '#^https?://#', '', $source );
		$source = preg_replace( '~[/?#].*$~', '', $source );
		$source = preg_replace( '/^www\./', '', $source );

		if ( ! $source || ! preg_match( '/^[a-z0-9.-]+$/', $source ) ) {
			return null;
		}

		foreach ( self::referral_hosts() as $known_host => $label ) {
			$suffix = '.' . $known_host;
			if ( $source === $known_host || ( strlen( $source ) > strlen( $suffix ) && substr( $source, -strlen( $suffix ) ) === $suffix ) ) {
				return array( 'provider' => $label, 'host' => $source, 'method' => 'utm_source' );
			}
		}

		return null;
	}
}
