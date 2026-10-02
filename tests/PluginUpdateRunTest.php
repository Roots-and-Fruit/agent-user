<?php
/**
 * The update ability against real plugin zips and a real homepage loopback.
 *
 * Needs the Studio server running. The fixture plugin is built at test time.
 *
 * @package Agent_Role
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers updated, restored, and failed outcomes, the lock, and permissions.
 */
class PluginUpdateRunTest extends TestCase {

	const SLUG = 'agent-role-fixture';

	const FILE = 'agent-role-fixture/agent-role-fixture.php';

	/**
	 * Users created by the current test.
	 *
	 * @var int[]
	 */
	private $user_ids = array();

	/**
	 * Zips built by the current test.
	 *
	 * @var string[]
	 */
	private $zips = array();

	/**
	 * The update_plugins transient before the test.
	 *
	 * @var mixed
	 */
	private $saved_updates;

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

	/**
	 * api.wordpress.org URLs refused in this process.
	 *
	 * @var string[]
	 */
	private $wporg_calls = array();

	protected function setUp(): void {
		parent::setUp();
		/*
		 * The bootstrap sets HTTPS=on, which turns home_url() into https://localhost:8883.
		 * That port speaks plain HTTP, so the loopback must use the stored home URL.
		 */
		$this->saved_https = isset( $_SERVER['HTTPS'] ) ? $_SERVER['HTTPS'] : null;
		unset( $_SERVER['HTTPS'] );
		Agent_Role::activate();
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$this->user_ids      = array();
		$this->zips          = array();
		$this->wporg_calls   = array();
		$this->saved_updates = get_site_transient( 'update_plugins' );
		$this->saved_active  = (array) get_option( 'active_plugins', array() );
		$this->assertDirectoryDoesNotExist( $this->plugin_dir(), 'A fixture from an earlier run was left behind.' );
		$this->refuse_wporg();
	}

	protected function tearDown(): void {
		foreach ( $this->user_ids as $user_id ) {
			if ( get_userdata( $user_id ) ) {
				wp_delete_user( $user_id );
			}
		}
		update_option( 'active_plugins', $this->saved_active );
		if ( false === $this->saved_updates ) {
			delete_site_transient( 'update_plugins' );
		} else {
			set_site_transient( 'update_plugins', $this->saved_updates );
		}
		WP_Upgrader::release_lock( Agent_Role_Plugin_Updates::LOCK );
		$this->remove_dir( $this->plugin_dir() );
		$this->remove_dir( $this->backup_dir() );
		foreach ( $this->zips as $zip ) {
			wp_delete_file( $zip );
		}
		wp_clean_plugins_cache( false );
		remove_all_filters( 'pre_http_request' );
		remove_filter( 'wp_trigger_error_trigger_error', '__return_false' );
		wp_set_current_user( 0 );
		if ( null !== $this->saved_https ) {
			$_SERVER['HTTPS'] = $this->saved_https;
		}
		parent::tearDown();
	}

	public function test_update_ability_is_registered_and_filed_under_undo(): void {
		$ability = wp_get_ability( Agent_Role_Plugin_Updates::UPDATE );
		$this->assertNotNull( $ability );
		$this->assertSame( Agent_Role_Plugin_Updates::CATEGORY, $ability->get_category() );
		$this->assertSame( 'undo', Agent_Role_Admin::file_ability_group( $ability ) );
		$meta = $ability->get_meta();
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
		$this->assertTrue( $meta['annotations']['idempotent'] );
	}

	public function test_active_plugin_updates_to_a_healthy_version_and_stays_active(): void {
		$this->install_fixture( true );
		$this->offer( '1.1.0', 'healthy' );

		$result = $this->run_over_rest();

		$this->assertSame( 'updated', $result['outcome'], $result['message'] );
		$this->assertSame( '1.0.0', $result['from_version'] );
		$this->assertSame( '1.1.0', $result['to_version'] );
		$this->assertTrue( $result['was_active'] );
		$this->assertSame( '1.1.0', $this->installed_version() );
		$this->assertContains( self::FILE, (array) get_option( 'active_plugins' ) );
		$this->assertArrayNotHasKey( self::FILE, get_site_transient( 'update_plugins' )->response );
		$this->assertNoLeftovers();
	}

	public function test_active_plugin_with_a_fatal_update_is_restored_and_the_homepage_works(): void {
		$this->install_fixture( true );
		$this->offer( '1.2.0', 'fatal' );

		$result = $this->run_over_rest();

		$this->assertSame( 'restored', $result['outcome'], $result['message'] );
		$this->assertSame( '1.0.0', $result['from_version'] );
		$this->assertSame( '1.2.0', $result['to_version'] );
		$this->assertStringContainsString( 'agent_role_fixture_missing_function', $result['message'] );
		$this->assertSame( '1.0.0', $this->installed_version() );
		$this->assertContains( self::FILE, (array) get_option( 'active_plugins' ) );
		$this->assertArrayHasKey( self::FILE, get_site_transient( 'update_plugins' )->response, 'The offer stays listed after a restore.' );
		$this->assertNoLeftovers();

		$home = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'   => 30,
				'sslverify' => false,
			)
		);
		$this->assertSame( 200, wp_remote_retrieve_response_code( $home ) );
		$this->assertStringNotContainsString( 'critical error', wp_remote_retrieve_body( $home ) );
	}

	public function test_inactive_plugin_updates_without_a_scrape_and_stays_inactive(): void {
		$this->install_fixture( false );
		$this->offer( '1.2.0', 'fatal' );
		$loopbacks = 0;
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$loopbacks ) {
				unset( $args );
				if ( false !== strpos( $url, 'wp_scrape_key' ) ) {
					++$loopbacks;
				}
				return $pre;
			},
			10,
			3
		);

		$result = $this->run_as_web_dev();
		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 'updated', $result['outcome'], $result['message'] );
		$this->assertFalse( $result['was_active'] );
		$this->assertSame( 0, $loopbacks );
		$this->assertSame( array(), $this->wporg_calls, 'The update must not wait on WordPress.org update checks.' );
		$this->assertSame( '1.2.0', $this->installed_version() );
		$this->assertNotContains( self::FILE, (array) get_option( 'active_plugins' ) );
		$this->assertNoLeftovers();
		$this->assertNotFalse( has_action( 'upgrader_process_complete', 'wp_update_plugins' ), 'Core hooks are put back after the update.' );
		$this->assertLoggedOutcome( 'Update a plugin: updated', 'success' );
	}

	public function test_a_plugin_without_an_offer_fails_before_anything_changes(): void {
		$this->install_fixture( true );
		$this->offer( '1.1.0', 'healthy' );

		wp_set_current_user( $this->make_agent( 'webdev' ) );
		$result = wp_get_ability( Agent_Role_Plugin_Updates::UPDATE )->execute( array( 'plugin' => 'user-switching/user-switching.php' ) );

		$this->assertSame( 'failed', $result['outcome'] );
		$this->assertStringContainsString( 'list-plugin-updates', $result['message'] );
		$this->assertSame( '1.0.0', $this->installed_version() );
		$this->assertNoLeftovers();
		$this->assertLoggedOutcome( 'Update a plugin: failed', 'error' );
	}

	public function test_a_held_lock_fails_with_a_busy_message(): void {
		$this->install_fixture( true );
		$this->offer( '1.1.0', 'healthy' );
		$this->assertTrue( WP_Upgrader::create_lock( Agent_Role_Plugin_Updates::LOCK ) );

		$result = $this->run_as_web_dev();

		$this->assertSame( 'failed', $result['outcome'] );
		$this->assertStringContainsString( 'Another plugin update', $result['message'] );
		$this->assertSame( '1.0.0', $this->installed_version() );
		$this->assertNotFalse( get_option( Agent_Role_Plugin_Updates::LOCK . '.lock' ), 'The ability must not release a lock it did not take.' );
	}

	public function test_the_lock_is_released_after_an_update(): void {
		$this->install_fixture( false );
		$this->offer( '1.1.0', 'healthy' );

		$this->run_as_web_dev();

		$this->assertFalse( get_option( Agent_Role_Plugin_Updates::LOCK . '.lock' ) );
	}

	public function test_editor_cannot_update_plugins_even_with_the_switch_on(): void {
		$editor = $this->make_agent( 'editor' );
		wp_set_current_user( $editor );
		$ability = wp_get_ability( Agent_Role_Plugin_Updates::UPDATE );
		$input   = array( 'plugin' => self::FILE );

		$off = $ability->check_permissions( $input );
		$this->assertInstanceOf( WP_Error::class, $off );
		$this->assertSame( 'agent_role_ability_off', $off->get_error_code() );

		update_user_meta( $editor, Agent_Role::ABILITIES_META, array( Agent_Role_Plugin_Updates::UPDATE => true ) );
		$this->assertNotTrue( $ability->check_permissions( $input ) );
	}

	/**
	 * Run the ability through the Abilities REST route as a Web Dev agent with an application password.
	 *
	 * Active-plugin updates must run inside the web server. The server keeps its own opcache,
	 * and only the process that copies the files can invalidate it.
	 */
	private function run_over_rest() {
		$agent    = $this->make_agent( 'webdev' );
		$password = WP_Application_Passwords::create_new_application_password( $agent, array( 'name' => 'update-test' ) );
		$this->assertIsArray( $password );
		$login = get_userdata( $agent )->user_login;
		$url   = rest_url( 'wp-abilities/v1/abilities/' . Agent_Role_Plugin_Updates::UPDATE . '/run' );
		$args  = array(
			'timeout'     => 90,
			'sslverify'   => false,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Basic ' . base64_encode( $login . ':' . $password[0] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth.
				'Content-Type'  => 'application/json',
			),
			'body'        => wp_json_encode( array( 'input' => array( 'plugin' => self::FILE ) ) ),
		);
		// Studio redirects localhost to its HTTPS domain, and following a redirect turns POST into GET.
		$response = wp_remote_post( $url, $args );
		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( ! is_wp_error( $response ) && $location && in_array( wp_remote_retrieve_response_code( $response ), array( 301, 302, 307, 308 ), true ) ) {
			$response = wp_remote_post( $location, $args );
		}
		$this->assertNotWPError( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$this->assertSame( 200, wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_body( $response ) );
		wp_cache_flush();
		return $body;
	}

	/**
	 * Refuse api.wordpress.org in this process. Core's admin hooks check for updates after every upgrade,
	 * and warn when that check fails.
	 */
	private function refuse_wporg() {
		add_filter( 'wp_trigger_error_trigger_error', '__return_false' );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				unset( $args );
				if ( false !== strpos( $url, 'api.wordpress.org' ) ) {
					$this->wporg_calls[] = $url;
					return new WP_Error( 'agent_role_test_offline', 'Tests do not contact WordPress.org.' );
				}
				return $pre;
			},
			10,
			3
		);
	}

	private function assertNotWPError( $value ): void {
		$this->assertFalse( is_wp_error( $value ), is_wp_error( $value ) ? $value->get_error_message() : '' );
	}

	private function run_as_web_dev() {
		wp_set_current_user( $this->make_agent( 'webdev' ) );
		$result = wp_get_ability( Agent_Role_Plugin_Updates::UPDATE )->execute( array( 'plugin' => self::FILE ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : 'Not an array.' );
		return $result;
	}

	private function assertLoggedOutcome( $detail, $outcome ): void {
		$rows = Agent_Role_Log::query( get_current_user_id(), 'ability' );
		$this->assertNotEmpty( $rows, 'The update call was not logged.' );
		$this->assertSame( Agent_Role_Plugin_Updates::UPDATE, $rows[0]['identifier'] );
		$this->assertSame( $detail, $rows[0]['detail'] );
		$this->assertSame( $outcome, $rows[0]['outcome'] );
	}

	private function assertNoLeftovers(): void {
		$this->assertDirectoryDoesNotExist( $this->backup_dir(), 'The temp backup was left behind.' );
		$this->assertFileDoesNotExist( ABSPATH . '.maintenance' );
		$this->assertFalse( get_option( Agent_Role_Plugin_Updates::LOCK . '.lock' ) );
	}

	/**
	 * Install version 1.0.0 straight from source files, like an older release left on disk.
	 *
	 * @param bool $active Whether to put it on the active list without loading it here.
	 */
	private function install_fixture( $active ) {
		wp_mkdir_p( $this->plugin_dir() );
		file_put_contents( $this->plugin_dir() . '/agent-role-fixture.php', $this->source( '1.0.0', 'healthy' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		if ( $active ) {
			$plugins   = (array) get_option( 'active_plugins', array() );
			$plugins[] = self::FILE;
			update_option( 'active_plugins', $plugins );
		}
		wp_clean_plugins_cache( false );
	}

	/**
	 * Offer a version through the update_plugins transient, with a local zip as the package.
	 *
	 * @param string $version Offered version.
	 * @param string $health  healthy, or fatal on front-end load.
	 */
	private function offer( $version, $health ) {
		$zip_path = trailingslashit( get_temp_dir() ) . self::SLUG . '-' . $version . '-' . wp_generate_password( 6, false, false ) . '.zip';
		$zip      = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$zip->addFromString( self::SLUG . '/agent-role-fixture.php', $this->source( $version, $health ) );
		$zip->close();
		$this->zips[] = $zip_path;

		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		$transient->last_checked      = time();
		$transient->response          = isset( $transient->response ) && is_array( $transient->response ) ? $transient->response : array();
		$transient->response[ self::FILE ] = (object) array(
			'slug'        => self::SLUG,
			'plugin'      => self::FILE,
			'new_version' => $version,
			'package'     => $zip_path,
		);
		set_site_transient( 'update_plugins', $transient );
	}

	private function source( $version, $health ) {
		$code = "<?php\n/**\n * Plugin Name: Agent Role Fixture\n * Version: {$version}\n */\n";
		if ( 'fatal' === $health ) {
			$code .= "add_action( 'template_redirect', function () { agent_role_fixture_missing_function(); } );\n";
		}
		return $code;
	}

	private function installed_version() {
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . self::FILE, false, false );
		return $data['Version'];
	}

	private function plugin_dir() {
		return WP_PLUGIN_DIR . '/' . self::SLUG;
	}

	private function backup_dir() {
		return WP_CONTENT_DIR . '/upgrade-temp-backup/plugins/' . self::SLUG;
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
