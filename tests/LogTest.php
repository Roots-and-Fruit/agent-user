<?php
/**
 * Activity log storage tests against this Studio site.
 *
 * @package Agent_Role
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers insert, refusal, retention, and isolation between agents.
 */
class LogTest extends TestCase {

	/**
	 * Users created by the current test.
	 *
	 * @var int[]
	 */
	private $user_ids = array();

	protected function setUp(): void {
		parent::setUp();
		Agent_Role::activate();
		Agent_Role_Log::install();
		$this->user_ids = array();
	}

	protected function tearDown(): void {
		global $wpdb;

		$table = Agent_Role_Log::table();
		foreach ( $this->user_ids as $user_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d", $user_id ) );
			if ( get_userdata( $user_id ) ) {
				wp_delete_user( $user_id );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is not user input.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE user_id = %d", $user_id ) );
		}
		$this->user_ids = array();
		parent::tearDown();
	}

	public function test_record_round_trips_on_this_database(): void {
		$agent = $this->make_agent();
		$id    = Agent_Role_Log::record( $agent, 'ability', 'Permission denied', 'mc-functionality/create-snippet', 'denied' );

		$this->assertIsInt( $id );
		$rows = Agent_Role_Log::query( $agent, 'ability' );
		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $id, (string) $rows[0]['id'] );
		$this->assertSame( 'Permission denied', $rows[0]['detail'] );
		$this->assertSame( 'mc-functionality/create-snippet', $rows[0]['identifier'] );
		$this->assertSame( 'denied', $rows[0]['outcome'] );
	}

	public function test_record_refuses_a_non_agent(): void {
		$author = $this->make_user( 'author' );
		$this->assertFalse( Agent_Role_Log::record( $author, 'rest', 'POST /wp/v2/posts', 'POST /wp/v2/posts', 'success' ) );
		$this->assertSame( array(), Agent_Role_Log::query( $author ) );
	}

	public function test_the_501st_row_drops_the_oldest(): void {
		$agent = $this->make_agent();
		$first = Agent_Role_Log::record( $agent, 'admin', 'Switches saved', 'abilities', 'changed' );
		$this->assertIsInt( $first );

		for ( $i = 0; $i < 500; $i++ ) {
			$this->assertIsInt( Agent_Role_Log::record( $agent, 'rest', 'POST /wp/v2/posts', 'POST /wp/v2/posts', 'success' ) );
		}

		$ids = array_map(
			static function ( $row ) {
				return (int) $row['id'];
			},
			Agent_Role_Log::query( $agent )
		);
		$this->assertCount( Agent_Role_Log::CAP, $ids );
		$this->assertNotContains( $first, $ids );
	}

	public function test_a_row_older_than_30_days_is_dropped(): void {
		global $wpdb;

		$agent = $this->make_agent();
		$old   = Agent_Role_Log::record( $agent, 'admin', 'Password revoked', 'password', 'changed' );
		$this->assertIsInt( $old );

		$wpdb->update(
			Agent_Role_Log::table(),
			array( 'created_at' => current_datetime()->modify( '-31 days' )->format( 'Y-m-d H:i:s' ) ),
			array( 'id' => $old ),
			array( '%s' ),
			array( '%d' )
		);

		$fresh = Agent_Role_Log::record( $agent, 'admin', 'Password reissued', 'password', 'changed' );
		$this->assertIsInt( $fresh );

		$ids = array_map(
			static function ( $row ) {
				return (int) $row['id'];
			},
			Agent_Role_Log::query( $agent )
		);
		$this->assertContains( $fresh, $ids );
		$this->assertNotContains( $old, $ids );
	}

	public function test_pruning_one_agent_leaves_another_agents_rows(): void {
		$kept   = $this->make_agent();
		$capped = $this->make_agent();
		$kept_id = Agent_Role_Log::record( $kept, 'ability', 'List snippets denied', 'mc-functionality/list-snippets', 'denied' );
		$this->assertIsInt( $kept_id );

		for ( $i = 0; $i < 501; $i++ ) {
			$this->assertIsInt( Agent_Role_Log::record( $capped, 'rest', 'DELETE /wp/v2/media/1', 'DELETE /wp/v2/media/1', 'error' ) );
		}

		$kept_rows = Agent_Role_Log::query( $kept );
		$this->assertCount( 1, $kept_rows );
		$this->assertSame( (string) $kept_id, (string) $kept_rows[0]['id'] );
		$this->assertCount( Agent_Role_Log::CAP, Agent_Role_Log::query( $capped ) );
	}

	public function test_query_can_limit_to_a_date_range(): void {
		$agent = $this->make_agent();
		$id    = Agent_Role_Log::record( $agent, 'admin', 'Switches saved', 'switches', 'changed' );
		$this->assertIsInt( $id );

		$tomorrow  = current_datetime()->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
		$yesterday = current_datetime()->modify( '-1 day' )->format( 'Y-m-d H:i:s' );

		$this->assertSame( array(), Agent_Role_Log::query( $agent, '', $tomorrow, '' ) );
		$this->assertCount( 1, Agent_Role_Log::query( $agent, '', $yesterday, '' ) );
		$this->assertContains(
			current_datetime()->format( 'Y-m-d' ),
			Agent_Role_Log::logged_dates()
		);
	}

	public function test_a_saved_cap_replaces_the_default_for_that_agent(): void {
		$previous = get_option( Agent_Role_Log::OPTION_CAP, null );
		update_option( Agent_Role_Log::OPTION_CAP, 2 );

		try {
			$agent = $this->make_agent();
			$first = Agent_Role_Log::record( $agent, 'admin', 'First', 'one', 'changed' );
			$this->assertIsInt( $first );
			$this->assertIsInt( Agent_Role_Log::record( $agent, 'admin', 'Second', 'two', 'changed' ) );
			$this->assertIsInt( Agent_Role_Log::record( $agent, 'admin', 'Third', 'three', 'changed' ) );

			$ids = array_map(
				static function ( $row ) {
					return (int) $row['id'];
				},
				Agent_Role_Log::query( $agent )
			);
			$this->assertCount( 2, $ids );
			$this->assertNotContains( $first, $ids );
		} finally {
			$this->restore_option( Agent_Role_Log::OPTION_CAP, $previous );
		}
	}

	public function test_limits_below_one_fall_back_and_limits_above_the_ceiling_clamp(): void {
		$this->assertSame( Agent_Role_Log::DAYS, Agent_Role_Log::days() );
		$this->assertSame( Agent_Role_Log::CAP, Agent_Role_Log::cap() );

		$previous_days = get_option( Agent_Role_Log::OPTION_DAYS, null );
		$previous_cap  = get_option( Agent_Role_Log::OPTION_CAP, null );
		update_option( Agent_Role_Log::OPTION_DAYS, 0 );
		update_option( Agent_Role_Log::OPTION_CAP, 90000 );

		try {
			$this->assertSame( Agent_Role_Log::DAYS, Agent_Role_Log::days() );
			$this->assertSame( Agent_Role_Log::MAX_CAP, Agent_Role_Log::cap() );
		} finally {
			$this->restore_option( Agent_Role_Log::OPTION_DAYS, $previous_days );
			$this->restore_option( Agent_Role_Log::OPTION_CAP, $previous_cap );
		}
	}

	/**
	 * @param string     $name  Option name.
	 * @param mixed|null $value Previous value, or null when the option was absent.
	 */
	private function restore_option( $name, $value ) {
		if ( null === $value ) {
			delete_option( $name );
			return;
		}

		update_option( $name, $value );
	}

	/**
	 * @param string $role Role slug.
	 */
	private function make_user( $role ) {
		$login   = 'agentrole_test_' . strtolower( wp_generate_password( 8, false, false ) );
		$user_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => $login . '@example.invalid',
				'role'       => $role,
			)
		);
		$this->assertIsInt( $user_id );
		$this->user_ids[] = $user_id;
		return $user_id;
	}

	private function make_agent() {
		$login   = 'agentrole_test_' . strtolower( wp_generate_password( 8, false, false ) );
		$user_id = Agent_Role_Account::insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 24 ),
				'user_email' => $login . '@example.invalid',
				'role'       => Agent_Role::SLUG,
			)
		);
		$this->assertIsInt( $user_id );
		$this->user_ids[] = $user_id;
		return $user_id;
	}
}
