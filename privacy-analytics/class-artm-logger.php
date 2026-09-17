<?php
/**
 * Stores privacy-conscious AI crawler and referral events.
 *
 * @package AISignalMonitor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ARTM_Logger {
	private static $table_suffix = 'artm_events';
	private static $aggregate_suffix = 'artm_daily';

	public function register() {
		add_action( 'template_redirect', array( $this, 'capture' ), 2 );
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . self::$table_suffix;
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			occurred_at datetime NOT NULL,
			event_type varchar(20) NOT NULL,
			provider varchar(80) NOT NULL DEFAULT '',
			agent varchar(100) NOT NULL DEFAULT '',
			category varchar(30) NOT NULL DEFAULT '',
			request_path varchar(500) NOT NULL DEFAULT '',
			referrer_host varchar(255) NOT NULL DEFAULT '',
			status_code smallint(5) unsigned NOT NULL DEFAULT 200,
			ip_hash char(64) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			request_kind varchar(20) NOT NULL DEFAULT 'unknown',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			identity_status varchar(20) NOT NULL DEFAULT 'detected',
			identity_method varchar(30) NOT NULL DEFAULT 'user_agent',
			rule_action varchar(20) NOT NULL DEFAULT 'allowed',
			PRIMARY KEY  (id),
			KEY occurred_at (occurred_at),
			KEY event_date (event_type, occurred_at),
			KEY content_date (request_kind, occurred_at),
			KEY post_date (post_id, occurred_at),
			KEY agent_date (agent, occurred_at)
		) {$charset};";
		dbDelta( $sql );
	}

	/** Creates compact daily totals that remain after detailed events expire. */
	public static function create_aggregate_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = $wpdb->prefix . self::$aggregate_suffix;
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			activity_date date NOT NULL,
			event_type varchar(20) NOT NULL,
			provider varchar(80) NOT NULL DEFAULT '',
			agent varchar(100) NOT NULL DEFAULT '',
			category varchar(30) NOT NULL DEFAULT '',
			request_kind varchar(20) NOT NULL DEFAULT 'unknown',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			request_path varchar(500) NOT NULL DEFAULT '',
			request_count bigint(20) unsigned NOT NULL DEFAULT 0,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY daily_item (activity_date, event_type, agent(40), request_kind, post_id, request_path(100)),
			KEY activity_date (activity_date),
			KEY post_date (post_id, activity_date),
			KEY event_date (event_type, activity_date)
		) {$charset};";
		dbDelta( $sql );
	}

	/** Builds daily totals once from events already collected by earlier versions. */
	public static function maybe_rebuild_aggregates() {
		if ( get_option( 'artm_aggregate_version' ) === ARTM_SCHEMA_VERSION ) {
			return;
		}
		global $wpdb;
		$events = $wpdb->prefix . self::$table_suffix;
		$daily = $wpdb->prefix . self::$aggregate_suffix;
		$wpdb->query( "TRUNCATE TABLE {$daily}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "INSERT INTO {$daily} (activity_date,event_type,provider,agent,category,request_kind,post_id,request_path,request_count,first_seen,last_seen)
			SELECT DATE(occurred_at),event_type,provider,agent,category,request_kind,post_id,request_path,COUNT(*),MIN(occurred_at),MAX(occurred_at)
			FROM {$events} GROUP BY DATE(occurred_at),event_type,provider,agent,category,request_kind,post_id,request_path" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( 'artm_aggregate_version', ARTM_SCHEMA_VERSION, false );
	}

	/** Captures public GET/HEAD requests. HEAD checks are not treated as content reads. */
	public function capture() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_user_logged_in() ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$agent = ARTM_Detector::detect_agent( $user_agent );
		$campaign_source = isset( $_GET['utm_source'] ) ? sanitize_text_field( wp_unslash( $_GET['utm_source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public analytics signal.
		$referral = ARTM_Detector::detect_campaign_source( $campaign_source );
		if ( ! $referral ) {
			$referral = ARTM_Detector::detect_referrer( $referrer );
			if ( $referral ) {
				$referral['method'] = 'http_referrer';
			}
		}
		$request_kind = ( 'HEAD' === $method || is_404() ) ? 'infrastructure' : '';

		if ( $agent ) {
			self::insert( array( 'event_type' => 'crawler', 'provider' => $agent['provider'], 'agent' => $agent['label'], 'category' => $agent['category'], 'user_agent' => $user_agent, 'request_kind' => $request_kind ) );
		}
		if ( $referral ) {
			self::insert( array( 'event_type' => 'referral', 'provider' => $referral['provider'], 'referrer_host' => $referral['host'], 'user_agent' => $user_agent, 'request_kind' => $request_kind, 'identity_method' => $referral['method'] ) );
		}
	}

	/** @param array<string,mixed> $data Event data. */
	public static function insert( $data ) {
		global $wpdb;
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$ip = self::client_ip();
		$occurred_at = current_time( 'mysql', true );
		$defaults = array(
			'occurred_at' => $occurred_at,
			'event_type' => '', 'provider' => '', 'agent' => '', 'category' => '',
			'request_path' => substr( sanitize_text_field( $path ?: '/' ), 0, 500 ),
			'referrer_host' => '', 'status_code' => 200,
			'ip_hash' => $ip ? hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) ) : '',
			'user_agent' => '',
			'request_kind' => self::request_kind( $path ),
			'post_id' => function_exists( 'get_queried_object_id' ) ? absint( get_queried_object_id() ) : 0,
			'identity_status' => 'detected', 'identity_method' => 'user_agent',
			'rule_action' => isset( $data['event_type'] ) && 'blocked' === $data['event_type'] ? 'blocked' : 'allowed',
		);
		$row = wp_parse_args( $data, $defaults );
		if ( empty( $row['request_kind'] ) ) {
			$row['request_kind'] = self::request_kind( $path );
		}
		$row['user_agent'] = substr( sanitize_text_field( $row['user_agent'] ), 0, 255 );
		$row['provider'] = substr( sanitize_text_field( $row['provider'] ), 0, 80 );
		$row['agent'] = substr( sanitize_text_field( $row['agent'] ), 0, 100 );
		$row['category'] = substr( sanitize_key( $row['category'] ), 0, 30 );
		$row['event_type'] = substr( sanitize_key( $row['event_type'] ), 0, 20 );
		$row['identity_status'] = in_array( $row['identity_status'], array( 'detected', 'probable', 'verified', 'suspicious' ), true ) ? $row['identity_status'] : 'detected';
		if ( 'referral' === $row['event_type'] ) {
			// A human AI-referral visit does not need a browser fingerprint or full User-Agent.
			$row['ip_hash'] = '';
			$row['user_agent'] = '';
		}
		$inserted = $wpdb->insert( $wpdb->prefix . self::$table_suffix, $row );
		if ( false !== $inserted ) {
			self::increment_aggregate( $row );
		}
	}

	/** @param array<string,mixed> $row Sanitized event row. */
	private static function increment_aggregate( $row ) {
		global $wpdb;
		$table = $wpdb->prefix . self::$aggregate_suffix;
		$date = substr( $row['occurred_at'], 0, 10 );
		$sql = "INSERT INTO {$table} (activity_date,event_type,provider,agent,category,request_kind,post_id,request_path,request_count,first_seen,last_seen)
			VALUES (%s,%s,%s,%s,%s,%s,%d,%s,1,%s,%s)
			ON DUPLICATE KEY UPDATE request_count=request_count+1,last_seen=VALUES(last_seen),provider=VALUES(provider),category=VALUES(category)";
		$wpdb->query( $wpdb->prepare( $sql, $date, $row['event_type'], $row['provider'], $row['agent'], $row['category'], $row['request_kind'], absint( $row['post_id'] ), $row['request_path'], $row['occurred_at'], $row['occurred_at'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	}

	public static function request_kind( $path ) {
		$path = strtolower( (string) $path );
		$exact = array( '/robots.txt', '/sitemap.xml', '/wp-sitemap.xml', '/favicon.ico' );
		if ( in_array( $path, $exact, true ) || 0 === strpos( $path, '/wp-json' ) || 0 === strpos( $path, '/wp-admin' ) || preg_match( '#/(feed|sitemap[^/]*)/?$#', $path ) ) {
			return 'infrastructure';
		}
		if ( preg_match( '/\.(?:css|js|map|jpe?g|png|gif|webp|svg|ico|woff2?|ttf|eot|mp4|webm|mp3|xml|txt)$/', $path ) ) {
			return 'asset';
		}
		return 'content';
	}

	private static function content_condition() {
		return "(request_kind = 'content' OR (request_kind = 'unknown' AND request_path NOT IN ('/robots.txt','/sitemap.xml','/wp-sitemap.xml','/favicon.ico') AND request_path NOT LIKE '/wp-json%' AND request_path NOT LIKE '/wp-admin%'))";
	}

	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	public static function count( $type, $days = 7 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::$table_suffix;
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, absint( $days ) ) * DAY_IN_SECONDS ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND occurred_at >= %s", $type, $since ) );
	}

	public static function lifetime_count( $type, $content_only = false ) {
		global $wpdb;
		$table = $wpdb->prefix . self::$aggregate_suffix;
		$where = $content_only ? " AND (request_kind = 'content' OR (request_kind = 'unknown' AND request_path NOT IN ('/robots.txt','/sitemap.xml','/wp-sitemap.xml','/favicon.ico') AND request_path NOT LIKE '/wp-json%' AND request_path NOT LIKE '/wp-admin%'))" : '';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(request_count),0) FROM {$table} WHERE event_type = %s {$where}", $type ) );
	}

	public static function content_request_count( $days = 7 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::$table_suffix;
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, absint( $days ) ) * DAY_IN_SECONDS ) );
		$condition = self::content_condition();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = 'crawler' AND occurred_at >= %s AND {$condition}", $since ) );
	}

	public static function session_count( $days = 7 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::$table_suffix;
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, absint( $days ) ) * DAY_IN_SECONDS ) );
		$condition = self::content_condition();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT CONCAT(agent,'|',ip_hash,'|',FLOOR(UNIX_TIMESTAMP(occurred_at)/1800))) FROM {$table} WHERE event_type='crawler' AND occurred_at >= %s AND {$condition}", $since ) );
	}

	public static function top_paths( $days = 7, $limit = 5 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::$table_suffix;
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, absint( $days ) ) * DAY_IN_SECONDS ) );
		$limit = min( 50, max( 1, absint( $limit ) ) );
		$condition = self::content_condition();
		return $wpdb->get_results( $wpdb->prepare( "SELECT request_path,MAX(post_id) AS post_id,COUNT(*) AS total,COUNT(DISTINCT agent) AS agents,GROUP_CONCAT(DISTINCT agent ORDER BY agent SEPARATOR ', ') AS sources,MIN(occurred_at) AS first_seen,MAX(occurred_at) AS last_seen FROM {$table} WHERE event_type='crawler' AND occurred_at >= %s AND {$condition} GROUP BY request_path ORDER BY total DESC LIMIT %d", $since, $limit ) );
	}

	public static function topic_trends( $days = 7, $limit = 3 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::$table_suffix;
		$days = min( 30, max( 7, absint( $days ) ) );
		$limit = min( 20, max( 1, absint( $limit ) ) );
		$current_since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$previous_since = gmdate( 'Y-m-d H:i:s', time() - ( 2 * $days * DAY_IN_SECONDS ) );
		$condition = self::content_condition();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT request_path,MAX(post_id) AS post_id,SUM(CASE WHEN occurred_at >= %s THEN 1 ELSE 0 END) AS current_reads,SUM(CASE WHEN occurred_at < %s THEN 1 ELSE 0 END) AS previous_reads FROM {$table} WHERE event_type='crawler' AND occurred_at >= %s AND {$condition} GROUP BY request_path", $current_since, $current_since, $previous_since ) );
		$topics = array();
		foreach ( $rows as $row ) {
			$post_id = absint( $row->post_id );
			if ( ! $post_id ) { $post_id = url_to_postid( home_url( $row->request_path ) ); }
			$names = array();
			if ( $post_id ) {
				$terms = wp_get_post_terms( $post_id, array( 'category', 'post_tag' ), array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $terms ) ) { $names = array_slice( array_unique( $terms ), 0, 3 ); }
				if ( empty( $names ) && get_the_title( $post_id ) ) { $names[] = get_the_title( $post_id ); }
			}
			if ( empty( $names ) ) { $names[] = '/' === $row->request_path ? __( 'Homepage', 'ai-signal-monitor' ) : trim( $row->request_path, '/' ); }
			foreach ( $names as $name ) {
				$key = sanitize_title( $name );
				if ( ! isset( $topics[ $key ] ) ) { $topics[ $key ] = array( 'name' => $name, 'current' => 0, 'previous' => 0, 'pages' => array() ); }
				$topics[ $key ]['current'] += (int) $row->current_reads;
				$topics[ $key ]['previous'] += (int) $row->previous_reads;
				$topics[ $key ]['pages'][ $row->request_path ] = true;
			}
		}
		foreach ( $topics as &$topic ) { $topic['page_count'] = count( $topic['pages'] ); unset( $topic['pages'] ); }
		unset( $topic );
		usort( $topics, function( $a, $b ) { return $b['current'] <=> $a['current']; } );
		return array_slice( $topics, 0, $limit );
	}

	public static function activity_comparison( $days = 7 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::$table_suffix;
		$days = min( 30, max( 1, absint( $days ) ) );
		$current_since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$previous_since = gmdate( 'Y-m-d H:i:s', time() - ( 2 * $days * DAY_IN_SECONDS ) );
		$condition = self::content_condition();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(CASE WHEN occurred_at >= %s THEN 1 ELSE 0 END) AS current_reads,SUM(CASE WHEN occurred_at < %s THEN 1 ELSE 0 END) AS previous_reads FROM {$table} WHERE event_type='crawler' AND occurred_at >= %s AND {$condition}", $current_since, $current_since, $previous_since ) );
		$current = $row ? (int) $row->current_reads : 0;
		$previous = $row ? (int) $row->previous_reads : 0;
		$percent = $previous > 0 ? (int) round( ( ( $current - $previous ) / $previous ) * 100 ) : ( $current > 0 ? 100 : 0 );
		$status = $current > 0 && 0 === $previous ? 'new' : ( $percent >= 15 ? 'growing' : ( $percent <= -15 ? 'cooling' : 'stable' ) );
		return array( 'current' => $current, 'previous' => $previous, 'percent' => $percent, 'status' => $status );
	}

	public static function recent( $limit = 20 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::$table_suffix;
		$limit = min( 10000, max( 1, absint( $limit ) ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY occurred_at DESC LIMIT %d", $limit ) );
	}

	public static function agent_totals( $days = 7 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::$table_suffix;
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, absint( $days ) ) * DAY_IN_SECONDS ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT agent,provider,category,identity_status,COUNT(*) AS total,MAX(occurred_at) AS last_seen FROM {$table} WHERE event_type IN ('crawler','blocked') AND occurred_at >= %s GROUP BY agent,provider,category,identity_status ORDER BY total DESC", $since ) );
	}

	public static function last_content_activity() {
		global $wpdb;
		$table = $wpdb->prefix . self::$table_suffix;
		$condition = self::content_condition();
		return $wpdb->get_var( "SELECT MAX(occurred_at) FROM {$table} WHERE event_type='crawler' AND {$condition}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function reset_all() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . self::$table_suffix ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . self::$aggregate_suffix ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
		delete_transient( 'artm_widget_summary_v22' );
	}

	public static function cleanup() {
		global $wpdb;
		$settings = get_option( 'artm_settings', array() );
		$retention = isset( $settings['retention'] ) ? min( 365, max( 7, absint( $settings['retention'] ) ) ) : 30;
		$before = gmdate( 'Y-m-d H:i:s', time() - ( $retention * DAY_IN_SECONDS ) );
		$table = $wpdb->prefix . self::$table_suffix;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE occurred_at < %s", $before ) );
	}
}
