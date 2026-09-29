<?php
/**
 * Cross-agent activity log.
 *
 * @package Agent_Role
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores agent writes, refusals, and account changes.
 */
class Agent_Role_Log {

	const CAP = 500;

	const DAYS = 30;

	const MAX_CAP = 5000;

	const MAX_DAYS = 365;

	const OPTION_CAP = 'agent_role_log_cap';

	const OPTION_DAYS = 'agent_role_log_days';

	/**
	 * Event types the Activity tab can filter.
	 *
	 * @var string[]
	 */
	const TYPES = array( 'ability', 'rest', 'admin' );

	/**
	 * Outcomes the Activity tab can show.
	 *
	 * @var string[]
	 */
	const OUTCOMES = array( 'success', 'denied', 'changed', 'error' );

	/**
	 * Create the table once this class is loaded.
	 */
	public static function register() {
		self::install();
		add_action( 'wp_ability_invoked', array( __CLASS__, 'on_ability_invoked' ), 10, 1 );
		add_filter( 'wp_ability_permission_result', array( __CLASS__, 'on_ability_permission' ), 100, 2 );
		add_filter( 'wp_ability_execute_result', array( __CLASS__, 'on_ability_result' ), 100, 2 );
		add_action( 'wp_after_execute_ability', array( __CLASS__, 'on_ability_after' ), 10, 1 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'on_rest_dispatch' ), 10, 3 );
		add_action( 'delete_user', array( __CLASS__, 'on_user_delete' ) );
	}

	/**
	 * How many events to keep for one agent.
	 */
	public static function cap() {
		return self::bounded( get_option( self::OPTION_CAP, self::CAP ), 1, self::MAX_CAP, self::CAP );
	}

	/**
	 * How many days to keep an event.
	 */
	public static function days() {
		return self::bounded( get_option( self::OPTION_DAYS, self::DAYS ), 1, self::MAX_DAYS, self::DAYS );
	}

	/**
	 * Store the retention limits and drop rows that no longer fit.
	 *
	 * @param int $days Days to keep.
	 * @param int $cap  Events to keep per agent.
	 */
	public static function save_limits( $days, $cap ) {
		update_option( self::OPTION_DAYS, self::bounded( $days, 1, self::MAX_DAYS, self::DAYS ) );
		update_option( self::OPTION_CAP, self::bounded( $cap, 1, self::MAX_CAP, self::CAP ) );
		self::prune_all();
	}

	/**
	 * Clamp a retention value. A value below the minimum falls back to the default.
	 *
	 * @param mixed $value    Submitted value.
	 * @param int   $min      Lowest accepted value.
	 * @param int   $max      Highest accepted value.
	 * @param int   $fallback Value used when the submission is below the minimum.
	 */
	private static function bounded( $value, $min, $max, $fallback ) {
		$value = (int) $value;
		if ( $value < $min ) {
			return (int) $fallback;
		}

		return min( $value, (int) $max );
	}

	/**
	 * Table name with the site prefix.
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'agent_role_log';
	}

	/**
	 * Create the table when it is missing.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			event_type varchar(20) NOT NULL,
			detail text NOT NULL,
			identifier varchar(191) NOT NULL,
			outcome varchar(20) NOT NULL,
			PRIMARY KEY  (id),
			KEY user_created (user_id, created_at)
		) {$charset};";

		dbDelta( $sql );
		self::ensure_table();
	}

	/**
	 * Create the table directly when dbDelta did not.
	 */
	private static function ensure_table() {
		global $wpdb;

		$table = self::table();
		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
		$wpdb->query( "SELECT 1 FROM {$table} LIMIT 1" );
		$missing          = '' !== $wpdb->last_error;
		$wpdb->last_error = '';
		if ( $missing ) {
			$charset = $wpdb->get_charset_collate();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and charset are not user input.
			$wpdb->query(
				"CREATE TABLE IF NOT EXISTS {$table} (
					id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
					user_id bigint(20) unsigned NOT NULL,
					created_at datetime NOT NULL,
					event_type varchar(20) NOT NULL,
					detail text NOT NULL,
					identifier varchar(191) NOT NULL,
					outcome varchar(20) NOT NULL,
					PRIMARY KEY  (id),
					KEY user_created (user_id, created_at)
				) {$charset}"
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->last_error = '';
		}
		$wpdb->suppress_errors( false );
	}

	/**
	 * Drop the table. Called from uninstall.
	 */
	public static function drop() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Store one event for an Agent, then prune that agent.
	 *
	 * @param int    $user_id    Agent user ID.
	 * @param string $event_type ability, rest, or admin.
	 * @param string $detail     Short label safe to show.
	 * @param string $identifier Ability name, route, or setting name.
	 * @param string $outcome    success, denied, changed, or error.
	 * @return int|false Inserted row ID, or false when refused.
	 */
	public static function record( $user_id, $event_type, $detail, $identifier, $outcome ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof WP_User || ! Agent_Role::is_agent( $user ) ) {
			return false;
		}

		if ( ! in_array( $event_type, self::TYPES, true ) || ! in_array( $outcome, self::OUTCOMES, true ) ) {
			return false;
		}

		global $wpdb;

		$row     = array(
			'user_id'    => (int) $user_id,
			'created_at' => current_datetime()->format( 'Y-m-d H:i:s' ),
			'event_type' => $event_type,
			'detail'     => mb_substr( sanitize_text_field( $detail ), 0, 500 ),
			'identifier' => mb_substr( sanitize_text_field( $identifier ), 0, 191 ),
			'outcome'    => $outcome,
		);
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s' );

		$wpdb->suppress_errors( true );
		$inserted = $wpdb->insert( self::table(), $row, $formats );
		if ( ! $inserted ) {
			self::ensure_table();
			$inserted = $wpdb->insert( self::table(), $row, $formats );
		}
		$wpdb->suppress_errors( false );

		if ( ! $inserted ) {
			return false;
		}

		$id = (int) $wpdb->insert_id;
		self::prune( (int) $user_id );

		return $id;
	}

	/**
	 * Events, newest first.
	 *
	 * @param int    $user_id    Limit to one agent. Zero returns every agent.
	 * @param string $event_type Limit to one type. Empty returns every type.
	 * @return array<int,array<string,string>>
	 */
	public static function query( $user_id = 0, $event_type = '', $from = '', $to = '' ) {
		global $wpdb;

		$table = self::table();
		$where = array( '1=1' );
		$args  = array();

		if ( $user_id ) {
			$where[] = 'user_id = %d';
			$args[]  = (int) $user_id;
		}

		if ( '' !== $event_type ) {
			$where[] = 'event_type = %s';
			$args[]  = $event_type;
		}

		if ( '' !== $from ) {
			$where[] = 'created_at >= %s';
			$args[]  = $from;
		}

		if ( '' !== $to ) {
			$where[] = 'created_at <= %s';
			$args[]  = $to;
		}

		$sql = "SELECT id, user_id, created_at, event_type, detail, identifier, outcome FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC, id DESC';
		if ( $args ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are assembled above and passed to prepare().
			$sql = $wpdb->prepare( $sql, $args );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above when it has placeholders. Table name is not user input.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Site-local dates that have at least one log row.
	 *
	 * @return string[] Y-m-d dates, oldest first.
	 */
	public static function logged_dates() {
		global $wpdb;

		$table = self::table();
		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
		$dates = $wpdb->get_col( "SELECT DISTINCT DATE(created_at) FROM {$table} ORDER BY DATE(created_at) ASC" );
		$wpdb->suppress_errors( false );
		$wpdb->last_error = '';

		if ( ! is_array( $dates ) ) {
			return array();
		}

		return array_values( array_filter( $dates, 'is_string' ) );
	}

	/**
	 * Show a stored site-local timestamp with Settings → General formats.
	 *
	 * @param string $mysql Datetime stored in the site timezone.
	 */
	public static function format_timestamp( $mysql ) {
		$tz = wp_timezone();
		$dt = date_create_immutable( (string) $mysql, $tz );
		if ( ! $dt ) {
			return (string) $mysql;
		}

		$format = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		if ( '' === $format ) {
			$format = 'Y-m-d H:i:s';
		}

		return wp_date( $format, $dt->getTimestamp(), $tz );
	}

	/**
	 * Newest deletion time for each agent, keyed by user ID.
	 *
	 * @param int[] $user_ids Agent user IDs.
	 * @return array<int,string>
	 */
	public static function deleted_at( array $user_ids ) {
		global $wpdb;

		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( ! $user_ids ) {
			return array();
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$args         = $user_ids;
		array_unshift( $args, 'account-deleted' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name is not user input. Placeholders match $user_ids.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, MAX(created_at) AS deleted_at FROM {$table} WHERE identifier = %s AND user_id IN ({$placeholders}) GROUP BY user_id", $args ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$times = array();
		foreach ( $rows as $row ) {
			$times[ (int) $row['user_id'] ] = (string) $row['deleted_at'];
		}

		return $times;
	}

	/**
	 * Record that an Agent account is being deleted, while the user row still exists.
	 *
	 * @param int $user_id User ID.
	 */
	public static function on_user_delete( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user instanceof WP_User || ! Agent_Role::is_agent( $user ) ) {
			return;
		}

		self::record( (int) $user_id, 'admin', 'Agent account deleted', 'account-deleted', 'changed' );
	}

	/**
	 * Drop expired rows and rows past the per-agent cap.
	 *
	 * @param int $user_id Agent user ID.
	 */
	public static function prune( $user_id ) {
		global $wpdb;

		$table  = self::table();
		$cutoff = current_datetime()->modify( '-' . self::days() . ' days' )->format( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d AND created_at < %s", (int) $user_id, $cutoff ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE user_id = %d ORDER BY created_at DESC, id DESC", (int) $user_id ) );
		if ( ! is_array( $ids ) || count( $ids ) <= self::cap() ) {
			return;
		}

		$drop = array_map( 'intval', array_slice( $ids, self::cap() ) );
		if ( ! $drop ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $drop ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name is not user input. Placeholders match $drop.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $drop ) );
	}

	/**
	 * Apply the current limits to every agent in the log.
	 */
	public static function prune_all() {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
		$ids = $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table}" );
		if ( ! is_array( $ids ) ) {
			return;
		}

		foreach ( $ids as $user_id ) {
			self::prune( (int) $user_id );
		}
	}

	/**
	 * Reads that are not rows when they succeed. A denial is still recorded.
	 *
	 * @var string[]
	 */
	const QUIET_READS = array(
		'core/get-site-info',
		'core/get-user-info',
		'core/get-environment-info',
		'mc-functionality/list-snippets',
		'mcp-adapter/discover-abilities',
		'mcp-adapter/get-ability-info',
	);

	/**
	 * Ability names currently inside execute().
	 *
	 * @var string[]
	 */
	private static $invocations = array();

	/**
	 * Mark an ability execution so a later denial or result can be stored.
	 *
	 * @param string $ability_name Ability name.
	 */
	public static function on_ability_invoked( $ability_name ) {
		self::$invocations[] = (string) $ability_name;
	}

	/**
	 * Record a refusal that happens inside execute(). Leave the result unchanged.
	 *
	 * @param bool|WP_Error $result       Permission result.
	 * @param string        $ability_name Ability name.
	 * @return bool|WP_Error
	 */
	public static function on_ability_permission( $result, $ability_name ) {
		if ( true === $result || ! self::accept_invocation( $ability_name ) ) {
			return $result;
		}

		array_pop( self::$invocations );
		self::record_ability( $ability_name, 'denied', 'Permission denied' );
		return $result;
	}

	/**
	 * Record a failed callback. Leave the result unchanged.
	 *
	 * @param mixed  $result       Callback result.
	 * @param string $ability_name Ability name.
	 * @return mixed
	 */
	public static function on_ability_result( $result, $ability_name ) {
		if ( ! is_wp_error( $result ) || ! self::accept_invocation( $ability_name ) ) {
			return $result;
		}

		array_pop( self::$invocations );
		self::record_ability( $ability_name, 'error', 'Ability failed' );
		return $result;
	}

	/**
	 * Record a finished execution. Quiet reads are omitted.
	 *
	 * @param string $ability_name Ability name.
	 */
	public static function on_ability_after( $ability_name ) {
		if ( ! self::accept_invocation( $ability_name ) ) {
			return;
		}

		array_pop( self::$invocations );
		if ( in_array( $ability_name, self::QUIET_READS, true ) ) {
			return;
		}

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability_name ) : null;
		$detail  = ( $ability && method_exists( $ability, 'get_label' ) ) ? $ability->get_label() : $ability_name;
		self::record_ability( $ability_name, 'success', $detail );
	}

	/**
	 * Record a non-GET REST request made by an Agent.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public static function on_rest_dispatch( $response, $handler, $request ) {
		unset( $handler );

		if ( ! $request instanceof WP_REST_Request || 'GET' === strtoupper( $request->get_method() ) ) {
			return $response;
		}

		$user = wp_get_current_user();
		if ( ! Agent_Role::is_agent( $user ) ) {
			return $response;
		}

		if ( is_wp_error( $response ) ) {
			$data   = $response->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
		} elseif ( $response instanceof WP_HTTP_Response ) {
			$status = (int) $response->get_status();
		} else {
			$status = 200;
		}
		if ( $status >= 200 && $status < 300 ) {
			$outcome = 'success';
		} elseif ( 401 === $status || 403 === $status ) {
			$outcome = 'denied';
		} else {
			$outcome = 'error';
		}

		$method     = strtoupper( $request->get_method() );
		$route      = $request->get_route();
		$identifier = $method . ' ' . $route;
		$phrase     = function_exists( 'get_status_header_desc' ) ? get_status_header_desc( $status ) : '';
		$detail     = $phrase ? $status . ' ' . $phrase : (string) $status;

		self::record( $user->ID, 'rest', $detail, $identifier, $outcome );
		return $response;
	}

	/**
	 * Whether this ability name is the execution in progress.
	 *
	 * @param string $ability_name Ability name.
	 */
	private static function accept_invocation( $ability_name ) {
		if ( ! self::$invocations ) {
			return false;
		}

		$current = self::$invocations[ count( self::$invocations ) - 1 ];
		return (string) $ability_name === (string) $current;
	}

	/**
	 * Store an ability row for the current user when they are an Agent.
	 *
	 * @param string $ability_name Ability name.
	 * @param string $outcome      success, denied, or error.
	 * @param string $detail       Short label.
	 */
	private static function record_ability( $ability_name, $outcome, $detail ) {
		$user = wp_get_current_user();
		if ( ! Agent_Role::is_agent( $user ) ) {
			return;
		}

		self::record( $user->ID, 'ability', $detail, $ability_name, $outcome );
	}
}
