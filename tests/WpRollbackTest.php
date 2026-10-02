<?php
/**
 * The wp-rollback/rollback adapter against WP Rollback's real step pipeline.
 *
 * WordPress.org is answered at the HTTP layer with a fixture plugin, so the steps,
 * the unzip, and the reactivation all run for real. When WP Rollback is inactive,
 * every test asserts that the ability is not registered.
 *
 * @package Agent_Role
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers registration, the rollback, early failures, the lock, and permissions.
 */
class WpRollbackTest extends TestCase {

	const SLUG = 'agent-role-fixture';

	const FILE = 'agent-role-fixture/agent-role-fixture.php';

	/**
	 * Users created by the current test.
	 *
	 * @var int[]
	 */
	private $user_ids = array();

	/**
	 * Zips served as WordPress.org downloads, keyed by version.
	 *
	 * @var array<string,string>
	 */
	private $zips = array();

	/**
	 * WordPress.org URLs requested during the test.
	 *
	 * @var string[]
	 */
	private $wporg = array();

	/**
	 * The active_plugins option before the test.
	 *
	 * @var array
	 */
	private $saved_active;

	/**
	 * The bootstrap's $_SERVER['HTTPS'].
	 *
	 * @var string|null
	 */
	private $saved_https;

	protected function setUp(): void {
		parent::setUp();
		$this->saved_https = isset( $_SERVER['HTTPS'] ) ? $_SERVER['HTTPS'] : null;
		unset( $_SERVER['HTTPS'] );
		Agent_Role::activate();
		$this->user_ids     = array();
		$this->zips         = array();
		$this->wporg        = array();
		$this->saved_active = (array) get_option( 'active_plugins', array() );
		$this->assertDirectoryDoesNotExist( $this->plugin_dir(), 'A fixture from an earlier run was left behind.' );
	}

	protected function tearDown(): void {
		foreach ( $this->user_ids as $user_id ) {
			if ( get_userdata( $user_id ) ) {
				wp_delete_user( $user_id );
			}
		}
		remove_all_filters( 'pre_http_request' );
		update_option( 'active_plugins', $this->saved_active );
		if ( class_exists( 'WP_Upgrader', false ) ) {
			WP_Upgrader::release_lock( Agent_Role_Plugin_Updates::LOCK );
		}
		foreach ( array( 'package', 'download_url' ) as $key ) {
			delete_transient( 'wpr_plugin_' . self::SLUG . '_' . $key );
		}
		$this->remove_dir( $this->plugin_dir() );
		foreach ( $this->zips as $zip ) {
			wp_delete_file( $zip );
		}
		wp_clean_plugins_cache( false );
		wp_set_current_user( 0 );
		if ( null !== $this->saved_https ) {
			$_SERVER['HTTPS'] = $this->saved_https;
		}
		parent::tearDown();
	}

	public function test_registered_only_while_wp_rollback_runs_and_filed_under_delete(): void {
		if ( ! $this->wp_rollback_loaded() ) {
			$this->assertFalse( wp_has_ability( Agent_Role_Plugin_Updates::ROLLBACK ) );
			$this->assertFalse( Agent_Role_Wp_Rollback::is_available() );
			$this->assertNotNull( wp_get_ability( Agent_Role_Plugin_Updates::UPDATE ), 'The other abilities do not depend on WP Rollback.' );
			return;
		}

		$this->assertTrue( Agent_Role_Wp_Rollback::is_available() );
		$ability = wp_get_ability( Agent_Role_Plugin_Updates::ROLLBACK );
		$this->assertNotNull( $ability );
		$this->assertSame( Agent_Role_Plugin_Updates::CATEGORY, $ability->get_category() );
		$this->assertSame( 'delete', Agent_Role_Admin::file_ability_group( $ability ) );
		$this->assertTrue( $ability->get_meta()['annotations']['destructive'] );
	}

	public function test_active_plugin_rolls_back_to_an_older_version_and_stays_active(): void {
		if ( ! $this->rollback_or_assert_absent() ) {
			return;
		}
		$this->install_fixture( '1.1.0', true );
		$this->serve_wporg( array( '1.0.0', '1.1.0' ) );

		$result = $this->run_as_web_dev( '1.0.0' );

		$this->assertSame( 'rolled_back', $result['outcome'], $result['message'] );
		$this->assertSame( '1.1.0', $result['from_version'] );
		$this->assertSame( '1.0.0', $result['to_version'] );
		$this->assertFalse( $result['homepage_fatal'], $result['message'] );
		$this->assertSame( '1.0.0', $this->installed_version() );
		$this->assertContains( self::FILE, (array) get_option( 'active_plugins' ) );
		$this->assertContains( 'https://downloads.wordpress.org/plugin/agent-role-fixture.1.0.0.zip', $this->wporg );
		$this->assertNoLeftovers();
	}

	public function test_a_version_wordpress_org_does_not_list_fails_before_any_step(): void {
		if ( ! $this->rollback_or_assert_absent() ) {
			return;
		}
		$this->install_fixture( '1.1.0', true );
		$this->serve_wporg( array( '1.0.0', '1.1.0' ) );

		$result = $this->run_as_web_dev( '0.9.9' );

		$this->assertSame( 'failed', $result['outcome'] );
		$this->assertStringContainsString( '1.0.0', $result['message'], 'The message lists versions that exist.' );
		$this->assertSame( '1.1.0', $this->installed_version() );
		$this->assertSame( array(), preg_grep( '#downloads\.wordpress\.org#', $this->wporg ), 'Nothing is downloaded.' );
		$this->assertNoLeftovers();
	}

	public function test_the_installed_version_fails(): void {
		if ( ! $this->rollback_or_assert_absent() ) {
			return;
		}
		$this->install_fixture( '1.1.0', true );
		$this->serve_wporg( array( '1.0.0', '1.1.0' ) );

		$result = $this->run_as_web_dev( '1.1.0' );

		$this->assertSame( 'failed', $result['outcome'] );
		$this->assertStringContainsString( 'already installed', $result['message'] );
		$this->assertSame( array(), $this->wporg );
		$this->assertNoLeftovers();
	}

	public function test_a_plugin_that_is_not_installed_fails(): void {
		if ( ! $this->rollback_or_assert_absent() ) {
			return;
		}
		$this->serve_wporg( array( '1.0.0', '1.1.0' ) );

		$result = $this->run_as_web_dev( '1.0.0' );

		$this->assertSame( 'failed', $result['outcome'] );
		$this->assertStringContainsString( 'not installed', $result['message'] );
		$this->assertSame( array(), $this->wporg );
		$this->assertNoLeftovers();
	}

	public function test_a_held_lock_fails_before_any_step(): void {
		if ( ! $this->rollback_or_assert_absent() ) {
			return;
		}
		$this->install_fixture( '1.1.0', true );
		$this->serve_wporg( array( '1.0.0', '1.1.0' ) );
		$this->assertTrue( Agent_Role_Plugin_Updates::take_lock() );

		$result = $this->run_as_web_dev( '1.0.0' );

		$this->assertSame( 'failed', $result['outcome'] );
		$this->assertStringContainsString( 'Another plugin update', $result['message'] );
		$this->assertSame( '1.1.0', $this->installed_version() );
		$this->assertFileDoesNotExist( ABSPATH . '.wpr-maintenance' );
		$this->assertNotFalse( get_option( Agent_Role_Plugin_Updates::LOCK . '.lock' ) );
	}

	public function test_editor_cannot_roll_back_even_with_the_switch_on(): void {
		if ( ! $this->rollback_or_assert_absent() ) {
			return;
		}
		$editor = $this->make_agent( 'editor' );
		wp_set_current_user( $editor );
		$ability = wp_get_ability( Agent_Role_Plugin_Updates::ROLLBACK );
		$input   = array(
			'slug'    => self::SLUG,
			'version' => '1.0.0',
		);

		$off = $ability->check_permissions( $input );
		$this->assertInstanceOf( WP_Error::class, $off );
		$this->assertSame( 'agent_role_ability_off', $off->get_error_code() );

		update_user_meta( $editor, Agent_Role::ABILITIES_META, array( Agent_Role_Plugin_Updates::ROLLBACK => true ) );
		$this->assertNotTrue( $ability->check_permissions( $input ) );
	}

	private function wp_rollback_loaded() {
		return did_action( 'wpr_init' ) > 0;
	}

	/**
	 * True when WP Rollback runs. Otherwise asserts that the ability is absent.
	 */
	private function rollback_or_assert_absent() {
		if ( $this->wp_rollback_loaded() ) {
			return true;
		}
		$this->assertFalse( wp_has_ability( Agent_Role_Plugin_Updates::ROLLBACK ) );
		return false;
	}

	private function run_as_web_dev( $version ) {
		wp_set_current_user( $this->make_agent( 'webdev' ) );
		$result = wp_get_ability( Agent_Role_Plugin_Updates::ROLLBACK )->execute(
			array(
				'slug'    => self::SLUG,
				'version' => $version,
			)
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : 'Not an array.' );
		wp_cache_flush();
		return $result;
	}

	private function assertNoLeftovers(): void {
		$this->assertFileDoesNotExist( ABSPATH . '.wpr-maintenance' );
		$this->assertFalse( get_transient( 'wpr_plugin_' . self::SLUG . '_package' ) );
		$this->assertFalse( get_transient( 'wpr_plugin_' . self::SLUG . '_download_url' ) );
		$this->assertFalse( get_option( Agent_Role_Plugin_Updates::LOCK . '.lock' ) );
	}

	/**
	 * Answer plugins_api() with a version list and downloads.wordpress.org with fixture zips.
	 *
	 * @param string[] $versions Versions WordPress.org lists.
	 */
	private function serve_wporg( array $versions ) {
		$listed = array();
		foreach ( $versions as $version ) {
			$listed[ $version ]       = 'https://downloads.wordpress.org/plugin/' . self::SLUG . '.' . $version . '.zip';
			$this->zips[ $version ] = $this->build_zip( $version );
		}
		$body = wp_json_encode(
			array(
				'name'     => 'Agent Role Fixture',
				'slug'     => self::SLUG,
				'version'  => end( $versions ),
				'versions' => $listed,
				'sections' => array( 'changelog' => '' ),
			)
		);

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $body ) {
				if ( false !== strpos( $url, 'api.wordpress.org/plugins/info/' ) ) {
					$this->wporg[] = $url;
					return $this->http_response( 200, $body );
				}
				if ( preg_match( '#^https://downloads\.wordpress\.org/plugin/' . self::SLUG . '\.([0-9.]+)\.zip$#', $url, $match ) ) {
					$this->wporg[] = $url;
					if ( ! isset( $this->zips[ $match[1] ] ) || empty( $args['filename'] ) ) {
						return $this->http_response( 404, '' );
					}
					copy( $this->zips[ $match[1] ], $args['filename'] );
					return $this->http_response( 200, '' );
				}
				return $pre;
			},
			10,
			3
		);
	}

	private function http_response( $code, $body ) {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function build_zip( $version ) {
		$path = trailingslashit( get_temp_dir() ) . self::SLUG . '-' . $version . '-' . wp_generate_password( 6, false, false ) . '.zip';
		$zip  = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$zip->addFromString( self::SLUG . '/agent-role-fixture.php', $this->source( $version ) );
		$zip->close();
		return $path;
	}

	private function install_fixture( $version, $active ) {
		wp_mkdir_p( $this->plugin_dir() );
		file_put_contents( $this->plugin_dir() . '/agent-role-fixture.php', $this->source( $version ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		if ( $active ) {
			$plugins   = (array) get_option( 'active_plugins', array() );
			$plugins[] = self::FILE;
			update_option( 'active_plugins', $plugins );
		}
		wp_clean_plugins_cache( false );
	}

	private function source( $version ) {
		return "<?php\n/**\n * Plugin Name: Agent Role Fixture\n * Version: {$version}\n */\n";
	}

	private function installed_version() {
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . self::FILE, false, false );
		return $data['Version'];
	}

	private function plugin_dir() {
		return WP_PLUGIN_DIR . '/' . self::SLUG;
	}

	private function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test cleanup.
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Test cleanup.
	}

	private function make_agent( $persona ) {
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
		Agent_Role_Admin::assign_persona( $user_id, $persona );
		clean_user_cache( $user_id );
		return $user_id;
	}
}
