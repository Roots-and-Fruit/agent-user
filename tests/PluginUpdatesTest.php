<?php
/**
 * Web Dev plugin-update abilities against this Studio site.
 *
 * @package Agent_Role
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers registration, persona defaults, the update list, and the Web Dev migration.
 */
class PluginUpdatesTest extends TestCase {

	/**
	 * Users created by the current test.
	 *
	 * @var int[]
	 */
	private $user_ids = array();

	/**
	 * The update_plugins transient before the test.
	 *
	 * @var mixed
	 */
	private $saved_updates;

	/**
	 * The migration flag before the test.
	 *
	 * @var mixed
	 */
	private $saved_flag;

	/**
	 * HTTP requests seen by the observer.
	 *
	 * @var string[]
	 */
	private $http = array();

	protected function setUp(): void {
		parent::setUp();
		Agent_Role::activate();
		$this->user_ids      = array();
		$this->http          = array();
		$this->saved_updates = get_site_transient( 'update_plugins' );
		$this->saved_flag    = get_option( Agent_Role::WEBDEV_ABILITIES_OPTION, null );
		delete_option( Agent_Role::PERSONA_DEFAULTS_OPTION );
	}

	protected function tearDown(): void {
		foreach ( $this->user_ids as $user_id ) {
			if ( get_userdata( $user_id ) ) {
				wp_delete_user( $user_id );
			}
		}
		if ( false === $this->saved_updates ) {
			delete_site_transient( 'update_plugins' );
		} else {
			set_site_transient( 'update_plugins', $this->saved_updates );
		}
		if ( null === $this->saved_flag ) {
			delete_option( Agent_Role::WEBDEV_ABILITIES_OPTION );
		} else {
			update_option( Agent_Role::WEBDEV_ABILITIES_OPTION, $this->saved_flag, false );
		}
		delete_option( Agent_Role::PERSONA_DEFAULTS_OPTION );
		remove_all_filters( 'pre_http_request' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_list_ability_is_registered_listed_and_filed_under_read(): void {
		$ability = wp_get_ability( Agent_Role_Plugin_Updates::LIST_UPDATES );
		$this->assertNotNull( $ability );
		$this->assertSame( Agent_Role_Plugin_Updates::CATEGORY, $ability->get_category() );
		$this->assertTrue( Agent_Role::is_listed_ability( $ability ) );
		$this->assertSame( 'read', Agent_Role_Admin::file_ability_group( $ability ) );

		$meta = $ability->get_meta();
		$this->assertTrue( $meta['show_in_rest'] );
		$this->assertTrue( $meta['mcp']['public'] );
		$this->assertTrue( $meta['annotations']['readonly'] );

		$category = wp_get_ability_category( Agent_Role_Plugin_Updates::CATEGORY );
		$this->assertNotNull( $category );
		$this->assertSame( 'Agent Role', $category->get_label() );
	}

	public function test_web_dev_starts_with_the_update_abilities_and_other_personas_do_not(): void {
		$names = Agent_Role_Plugin_Updates::ability_names();
		$this->assertContains( Agent_Role_Plugin_Updates::LIST_UPDATES, $names );
		$this->assertContains( Agent_Role_Plugin_Updates::ROLLBACK, $names );

		$webdev = Agent_Role_Admin::factory_persona_shape( 'webdev' )['abilities'];
		foreach ( $names as $name ) {
			$this->assertTrue( ! empty( $webdev[ $name ] ), $name . ' should be on for Web Dev.' );
		}
		$this->assertTrue( $webdev['core/get-site-info'] );

		foreach ( array( 'editor', 'writer', 'analyst' ) as $persona ) {
			$abilities = Agent_Role_Admin::factory_persona_shape( $persona )['abilities'];
			foreach ( $names as $name ) {
				$this->assertTrue( empty( $abilities[ $name ] ), $name . ' should be off for ' . $persona . '.' );
			}
		}
	}

	public function test_web_dev_instructions_name_the_update_workflow_and_others_stay_out(): void {
		$webdev = Agent_Role_Admin::factory_persona_instructions( 'webdev' );
		foreach ( Agent_Role_Plugin_Updates::ability_names() as $name ) {
			$this->assertStringContainsString( $name, $webdev );
		}
		$this->assertStringContainsString( 'one plugin at a time', $webdev );
		$this->assertMatchesRegularExpression( '/wp-rollback\/rollback only when .*restored.*or the user names a version/s', $webdev );

		foreach ( array( 'editor', 'writer', 'analyst' ) as $persona ) {
			$this->assertStringNotContainsString( 'agent-role/update-plugin', Agent_Role_Admin::factory_persona_instructions( $persona ) );
		}
	}

	public function test_web_dev_lists_updates_from_the_transient_without_a_request(): void {
		$this->seed_updates(
			array(
				'user-switching/user-switching.php' => array(
					'slug'           => 'user-switching',
					'new_version'    => '99.0.0',
					'upgrade_notice' => 'Security fix.',
				),
				'wp-rollback/wp-rollback.php'       => array(
					'slug'        => 'wp-rollback',
					'new_version' => '98.0.0',
				),
			)
		);
		$this->observe_http();

		$agent = $this->make_agent( 'webdev' );
		wp_set_current_user( $agent );
		$ability = wp_get_ability( Agent_Role_Plugin_Updates::LIST_UPDATES );
		$this->assertTrue( $ability->check_permissions() );

		$result = $ability->execute();
		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['updates'] );

		$installed = get_plugins();
		$by_file   = array();
		foreach ( $result['updates'] as $row ) {
			$by_file[ $row['plugin'] ] = $row;
		}

		$switching = $by_file['user-switching/user-switching.php'];
		$this->assertSame( 'user-switching', $switching['slug'] );
		$this->assertSame( $installed['user-switching/user-switching.php']['Name'], $switching['name'] );
		$this->assertSame( $installed['user-switching/user-switching.php']['Version'], $switching['installed'] );
		$this->assertSame( '99.0.0', $switching['offered'] );
		$this->assertSame( 'Security fix.', $switching['upgrade_notice'] );

		$rollback = $by_file['wp-rollback/wp-rollback.php'];
		$this->assertSame( '98.0.0', $rollback['offered'] );
		$this->assertSame( '', $rollback['upgrade_notice'] );

		$this->assertSame( array(), $this->http );
	}

	public function test_an_empty_transient_is_an_empty_list(): void {
		$this->seed_updates( array() );
		$this->observe_http();

		wp_set_current_user( $this->make_agent( 'webdev' ) );
		$result = wp_get_ability( Agent_Role_Plugin_Updates::LIST_UPDATES )->execute();

		$this->assertSame( array( 'updates' => array() ), $result );
		$this->assertSame( array(), $this->http );
	}

	public function test_editor_cannot_list_updates_even_with_the_switch_on(): void {
		$editor = $this->make_agent( 'editor' );
		wp_set_current_user( $editor );
		$ability = wp_get_ability( Agent_Role_Plugin_Updates::LIST_UPDATES );

		$off = $ability->check_permissions();
		$this->assertInstanceOf( WP_Error::class, $off );
		$this->assertSame( 'agent_role_ability_off', $off->get_error_code() );

		update_user_meta( $editor, Agent_Role::ABILITIES_META, array( Agent_Role_Plugin_Updates::LIST_UPDATES => true ) );
		$this->assertFalse( user_can( $editor, 'update_plugins' ) );
		$this->assertNotTrue( $ability->check_permissions() );
	}

	public function test_the_abilities_rest_route_runs_for_web_dev_and_refuses_editor(): void {
		$this->seed_updates(
			array(
				'user-switching/user-switching.php' => array(
					'slug'        => 'user-switching',
					'new_version' => '99.0.0',
				),
			)
		);
		$route = '/wp-abilities/v1/abilities/' . Agent_Role_Plugin_Updates::LIST_UPDATES . '/run';

		wp_set_current_user( $this->make_agent( 'webdev' ) );
		$response = rest_do_request( new WP_REST_Request( 'GET', $route ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'user-switching/user-switching.php', $data['updates'][0]['plugin'] );

		wp_set_current_user( $this->make_agent( 'editor' ) );
		$refused = rest_do_request( new WP_REST_Request( 'GET', $route ) );
		$this->assertSame( 403, $refused->get_status() );
	}

	public function test_migration_turns_the_abilities_on_for_every_web_dev_agent_once(): void {
		$names   = Agent_Role_Plugin_Updates::ability_names();
		$custom  = $this->make_agent( 'webdev' );
		$plain   = $this->make_agent( 'webdev' );
		$editor  = $this->make_agent( 'editor' );
		$before  = array( 'core/get-site-info' => true );
		$editors = get_user_meta( $editor, Agent_Role::ABILITIES_META, true );

		update_user_meta( $custom, Agent_Role::ABILITIES_META, $before );
		update_user_meta( $custom, Agent_Role_Admin::PERSONA_CUSTOM_META, '1' );
		update_user_meta( $plain, Agent_Role::ABILITIES_META, $before );
		update_option(
			Agent_Role::PERSONA_DEFAULTS_OPTION,
			array(
				'webdev' => array(
					'caps'         => array(),
					'actions'      => array( 'update_plugins' ),
					'abilities'    => $before,
					'instructions' => 'Saved note.',
				),
				'editor' => array(
					'caps'         => array(),
					'actions'      => array(),
					'abilities'    => $before,
					'instructions' => 'Editor note.',
				),
			),
			false
		);
		delete_option( Agent_Role::WEBDEV_ABILITIES_OPTION );

		Agent_Role::migrate_webdev_abilities();

		foreach ( array( $custom, $plain ) as $agent ) {
			$saved = get_user_meta( $agent, Agent_Role::ABILITIES_META, true );
			$this->assertTrue( $saved['core/get-site-info'] );
			foreach ( $names as $name ) {
				$this->assertTrue( ! empty( $saved[ $name ] ), $name . ' should be on after migration.' );
			}
		}
		$this->assertSame( $editors, get_user_meta( $editor, Agent_Role::ABILITIES_META, true ) );

		$stored = get_option( Agent_Role::PERSONA_DEFAULTS_OPTION );
		foreach ( $names as $name ) {
			$this->assertTrue( ! empty( $stored['webdev']['abilities'][ $name ] ) );
			$this->assertTrue( empty( $stored['editor']['abilities'][ $name ] ) );
		}
		$this->assertSame( 'Saved note.', $stored['webdev']['instructions'] );

		update_user_meta( $plain, Agent_Role::ABILITIES_META, $before );
		Agent_Role::migrate_webdev_abilities();
		$this->assertSame( $before, get_user_meta( $plain, Agent_Role::ABILITIES_META, true ) );
	}

	/**
	 * @param array<string,array<string,string>> $offers Plugin file => offer fields.
	 */
	private function seed_updates( array $offers ) {
		$transient           = new stdClass();
		$transient->last_checked = time();
		$transient->checked  = array();
		$transient->response = array();
		foreach ( $offers as $file => $fields ) {
			$transient->response[ $file ] = (object) array_merge(
				array(
					'plugin'  => $file,
					'package' => '',
				),
				$fields
			);
		}
		set_site_transient( 'update_plugins', $transient );
	}

	private function observe_http() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				unset( $args );
				$this->http[] = $url;
				return $pre;
			},
			10,
			3
		);
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
