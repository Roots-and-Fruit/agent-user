<?php
/**
 * Activity tab render tests against this Studio site.
 *
 * @package Agent_Role
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers the Activity tab filters and the agent screen staying free of the log.
 */
class ActivityTest extends TestCase {

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
		$_GET           = array();
		$_POST          = array();
		$_REQUEST       = array();
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
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_activity_tab_lists_both_agents_and_filters_them(): void {
		$admin = $this->make_user( 'administrator', 'Admin Person' );
		$ada   = $this->make_agent( 'Ada Agent' );
		$bea   = $this->make_agent( 'Bea Agent' );
		wp_set_current_user( $admin );

		$_GET['page']  = 'agent-role';
		$_GET['tab']   = 'activity';
		$_GET['agent'] = (string) $ada;
		$empty         = $this->capture();
		$this->assertStringContainsString( 'No agent activity yet.', $empty );
		$this->assertStringNotContainsString( '<tbody>', $empty );
		unset( $_GET['agent'] );

		Agent_Role_Log::record( $ada, 'ability', 'Permission denied', 'probe-ada', 'denied' );
		Agent_Role_Log::record( $bea, 'rest', '201 Created', 'POST /wp/v2/posts', 'success' );
		Agent_Role_Log::record( $ada, 'admin', 'Switches saved', 'switches', 'changed' );

		$all = $this->tbody( $this->capture() );
		$this->assertStringContainsString( 'Ada Agent', $all );
		$this->assertStringContainsString( 'Bea Agent', $all );
		$this->assertMatchesRegularExpression(
			'#<a class="ar-rf-agent-link" href="[^"]*user_id=' . $ada . '[^"]*">Ada Agent</a>#',
			$all
		);
		$this->assertStringContainsString( 'probe-ada', $all );
		$this->assertStringContainsString( 'POST /wp/v2/posts', $all );
		$stored = '';
		foreach ( Agent_Role_Log::query( $ada, 'ability' ) as $row ) {
			if ( 'probe-ada' === $row['identifier'] ) {
				$stored = $row['created_at'];
			}
		}
		$tz       = wp_timezone();
		$dt       = date_create_immutable( $stored, $tz );
		$expected = wp_date( trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ), $dt->getTimestamp(), $tz );
		$this->assertNotSame( '', $stored );
		$this->assertStringContainsString( $expected, $all );

		$_GET['agent'] = (string) $bea;
		$only_bea      = $this->tbody( $this->capture() );
		$this->assertStringContainsString( 'Bea Agent', $only_bea );
		$this->assertStringNotContainsString( 'Ada Agent', $only_bea );
		$this->assertStringNotContainsString( 'probe-ada', $only_bea );

		unset( $_GET['agent'] );
		$_GET['event'] = 'ability';
		$only_ability  = $this->tbody( $this->capture() );
		$this->assertStringContainsString( 'probe-ada', $only_ability );
		$this->assertStringNotContainsString( 'POST /wp/v2/posts', $only_ability );
		$this->assertStringNotContainsString( 'switches', $only_ability );
	}

	public function test_agent_detail_screen_does_not_render_the_activity_log(): void {
		$admin = $this->make_user( 'administrator', 'Admin Person' );
		$ada   = $this->make_agent( 'Ada Agent' );
		Agent_Role_Log::record( $ada, 'ability', 'Permission denied', 'probe-ada', 'denied' );
		wp_set_current_user( $admin );

		$_GET['page']    = 'agent-role';
		$_GET['user_id'] = (string) $ada;
		$html            = $this->capture();

		$this->assertStringNotContainsString( 'Retained for 30 days', $html );
		$this->assertStringNotContainsString( 'probe-ada', $html );
	}

	public function test_a_deleted_agent_is_marked_with_the_deletion_time(): void {
		$admin = $this->make_user( 'administrator', 'Admin Person' );
		$ada   = $this->make_agent( 'Ada Agent' );
		wp_set_current_user( $admin );
		Agent_Role_Log::record( $ada, 'ability', 'Permission denied', 'probe-gone', 'denied' );
		wp_delete_user( $ada );

		$when = '';
		foreach ( Agent_Role_Log::query( $ada, 'admin' ) as $row ) {
			if ( 'account-deleted' === $row['identifier'] ) {
				$when = $row['created_at'];
			}
		}
		$this->assertNotSame( '', $when );

		$_GET['page'] = 'agent-role';
		$_GET['tab']  = 'activity';
		$html         = $this->tbody( $this->capture() );

		$this->assertStringNotContainsString( 'user_id=' . $ada, $html );
		$this->assertStringContainsString( (string) $ada, $html );
		$this->assertStringContainsString( 'ar-rf-deleted', $html );
		$this->assertStringContainsString( 'This agent account was deleted on ' . Agent_Role_Log::format_timestamp( $when ), $html );
	}

	public function test_settings_show_retention_controls_and_an_editor_cannot_save_them(): void {
		$admin = $this->make_user( 'administrator', 'Admin Person' );
		wp_set_current_user( $admin );
		$_GET['page'] = 'agent-role';
		$_GET['tab']  = 'settings';
		$html         = $this->capture();

		$this->assertStringContainsString( 'name="agent_role_log_days"', $html );
		$this->assertStringContainsString( 'name="agent_role_log_cap"', $html );
		$this->assertStringContainsString( 'does not store passwords or request contents', $html );
		$this->assertStringContainsString(
			sprintf( 'Retained for %d days (capped at %d events per agent)', Agent_Role_Log::days(), Agent_Role_Log::cap() ),
			$this->activity_page()
		);

		add_filter(
			'wp_die_handler',
			static function () {
				return static function ( $message ) {
					throw new RuntimeException( wp_strip_all_tags( (string) $message ) );
				};
			}
		);

		$editor = $this->make_user( 'editor', 'Editor Person' );
		wp_set_current_user( $editor );
		$_POST['agent_role_log_days'] = '10';
		$_POST['agent_role_log_cap']  = '10';
		try {
			Agent_Role_Admin::handle_log_setting();
			$this->fail( 'An editor must not change the log settings.' );
		} catch ( RuntimeException $exception ) {
			$this->assertStringContainsString( 'permission', strtolower( $exception->getMessage() ) );
		}
		$this->assertNotSame( '10', (string) get_option( Agent_Role_Log::OPTION_DAYS, '' ) );
		$this->assertNotSame( '10', (string) get_option( Agent_Role_Log::OPTION_CAP, '' ) );
	}

	private function activity_page() {
		$_GET['page'] = 'agent-role';
		$_GET['tab']  = 'activity';
		unset( $_GET['user_id'] );
		return $this->capture();
	}

	private function capture() {
		ob_start();
		Agent_Role_Admin::render();
		return (string) ob_get_clean();
	}

	private function tbody( $html ) {
		$start = strpos( $html, '<tbody>' );
		$end   = strpos( $html, '</tbody>' );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		return substr( $html, $start, $end - $start );
	}

	private function make_user( $role, $display ) {
		$login   = 'agentrole_test_' . strtolower( wp_generate_password( 8, false, false ) );
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 24 ),
				'user_email'   => $login . '@example.invalid',
				'display_name' => $display,
				'role'         => $role,
			)
		);
		$this->assertIsInt( $user_id );
		$this->user_ids[] = $user_id;
		return $user_id;
	}

	private function make_agent( $display ) {
		$login   = 'agentrole_test_' . strtolower( wp_generate_password( 8, false, false ) );
		$user_id = Agent_Role_Account::insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 24 ),
				'user_email'   => $login . '@example.invalid',
				'display_name' => $display,
				'role'         => Agent_Role::SLUG,
			)
		);
		$this->assertIsInt( $user_id );
		$this->user_ids[] = $user_id;
		return $user_id;
	}
}
