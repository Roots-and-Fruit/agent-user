<?php
/**
 * Persona catalog and site-default tests.
 *
 * @package Agent_Role
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers filing unmapped abilities and Save as Default / Reset / Factory Reset.
 */
class PersonaCatalogTest extends TestCase {

	const READ = 'agent-role/catalog-read';

	const CREATE = 'agent-role/catalog-create';

	const UNDO = 'agent-role/catalog-undo';

	const DELETE = 'agent-role/catalog-delete';

	const OTHER = 'agent-role/catalog-other';

	const NEEDY = 'agent-role/catalog-needy';

	/**
	 * Users created by this process.
	 *
	 * @var int[]
	 */
	private $user_ids = array();

	protected function setUp(): void {
		parent::setUp();
		Agent_Role::activate();
		$this->user_ids = array();
		$_POST          = array();
		$_GET           = array();
		$_REQUEST       = array();
		delete_option( Agent_Role::PERSONA_DEFAULTS_OPTION );
		$this->register_catalog_abilities();
	}

	protected function tearDown(): void {
		foreach ( $this->user_ids as $user_id ) {
			if ( get_userdata( $user_id ) ) {
				wp_delete_user( $user_id );
			}
		}
		if ( function_exists( 'wp_unregister_ability' ) ) {
			wp_unregister_ability( self::READ );
			wp_unregister_ability( self::CREATE );
			wp_unregister_ability( self::UNDO );
			wp_unregister_ability( self::DELETE );
			wp_unregister_ability( self::OTHER );
			wp_unregister_ability( self::NEEDY );
		}
		delete_option( Agent_Role::PERSONA_DEFAULTS_OPTION );
		remove_all_filters( 'wp_redirect' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_annotations_file_listed_abilities(): void {
		$this->assertSame( 'read', Agent_Role_Admin::file_ability_group( wp_get_ability( self::READ ) ) );
		$this->assertSame( 'create', Agent_Role_Admin::file_ability_group( wp_get_ability( self::CREATE ) ) );
		$this->assertSame( 'undo', Agent_Role_Admin::file_ability_group( wp_get_ability( self::UNDO ) ) );
		$this->assertSame( 'delete', Agent_Role_Admin::file_ability_group( wp_get_ability( self::DELETE ) ) );
		$this->assertSame( 'other', Agent_Role_Admin::file_ability_group( wp_get_ability( self::OTHER ) ) );
	}

	public function test_factory_shape_turns_on_core_reads_and_not_catalog_abilities(): void {
		$factory = Agent_Role_Admin::factory_persona_shape( 'writer' );
		$this->assertTrue( $factory['abilities']['core/get-site-info'] );
		$this->assertTrue( $factory['abilities']['core/get-user-info'] );
		$this->assertTrue( $factory['abilities']['core/get-environment-info'] );
		$this->assertTrue( empty( $factory['abilities'][ self::READ ] ) );
		$this->assertFalse( Agent_Role_Admin::has_site_persona_default( 'writer' ) );
		$this->assertSame( $factory, Agent_Role_Admin::site_persona_shape( 'writer' ) );
	}

	public function test_saved_default_is_the_site_shape_until_factory_clears_it(): void {
		Agent_Role_Admin::write_site_persona_default(
			'writer',
			array(
				'caps'      => array(
					'edit_posts'             => true,
					'edit_published_posts'   => true,
					'publish_posts'          => true,
					'upload_files'           => true,
					'delete_posts'           => false,
					'delete_published_posts' => false,
				),
				'actions'   => array( 'read_others' ),
				'abilities' => array(
					self::READ => true,
				),
			)
		);

		$this->assertTrue( Agent_Role_Admin::has_site_persona_default( 'writer' ) );
		$site = Agent_Role_Admin::site_persona_shape( 'writer' );
		$this->assertTrue( $site['caps']['edit_posts'] );
		$this->assertSame( array( 'read_others' ), $site['actions'] );
		$this->assertTrue( $site['abilities'][ self::READ ] );
		$this->assertTrue( Agent_Role_Admin::factory_persona_shape( 'writer' )['abilities']['core/get-site-info'] );

		Agent_Role_Admin::clear_site_persona_default( 'writer' );
		$this->assertFalse( Agent_Role_Admin::has_site_persona_default( 'writer' ) );
		$this->assertSame( Agent_Role_Admin::factory_persona_shape( 'writer' ), Agent_Role_Admin::site_persona_shape( 'writer' ) );
	}

	public function test_save_as_default_strips_an_ability_the_agent_cannot_run(): void {
		$admin = $this->make_user( 'administrator' );
		$agent = $this->make_agent();
		wp_set_current_user( $admin );

		update_user_meta( $agent, Agent_Role::INSTRUCTIONS_META, Agent_Role::instructions_for( get_userdata( $agent ) ) );

		$_POST['user_id']                   = (string) $agent;
		$_POST['agent_role_agent_nonce']    = wp_create_nonce( 'agent_role_save_agent_' . $agent );
		$_REQUEST['agent_role_agent_nonce'] = $_POST['agent_role_agent_nonce'];
		$_POST['agent_role_persona']        = 'writer';
		$_POST['agent_role_persona_custom'] = '1';
		$_POST['agent_role_persona_action'] = 'save_default';
		$_POST['agent_role_caps']           = array( 'edit_posts', 'edit_published_posts', 'publish_posts', 'upload_files' );
		$_POST['agent_role_actions']        = array();
		$_POST['agent_role_abilities']      = array( self::READ, self::NEEDY );
		$_POST['agent_role_instructions']   = Agent_Role::instructions_for( get_userdata( $agent ) );

		$this->save_and_redirect();

		$stored = Agent_Role_Admin::site_persona_shape( 'writer' );
		$this->assertTrue( $stored['abilities'][ self::READ ] );
		$this->assertTrue( empty( $stored['abilities'][ self::NEEDY ] ) );
		$saved = get_user_meta( $agent, Agent_Role::ABILITIES_META, true );
		$this->assertTrue( ! empty( $saved[ self::READ ] ) );
		$this->assertTrue( empty( $saved[ self::NEEDY ] ) );
		$this->assertSame( '', (string) get_user_meta( $agent, Agent_Role_Admin::PERSONA_CUSTOM_META, true ) );
	}

	public function test_save_as_default_leaves_a_customized_agent_alone(): void {
		$admin = $this->make_user( 'administrator' );
		$kept  = $this->make_agent();
		$next  = $this->make_agent();
		wp_set_current_user( $admin );

		update_user_meta( $kept, Agent_Role_Admin::PERSONA_META, 'writer' );
		update_user_meta( $kept, Agent_Role_Admin::PERSONA_CUSTOM_META, '1' );
		update_user_meta( $kept, Agent_Role_Admin::ACTIONS_META, array( 'update_plugins' ) );
		$kept_user = get_userdata( $kept );
		$kept_user->add_cap( 'update_plugins', true );

		update_user_meta( $next, Agent_Role::INSTRUCTIONS_META, Agent_Role::instructions_for( get_userdata( $next ) ) );

		$_POST['user_id']                   = (string) $next;
		$_POST['agent_role_agent_nonce']    = wp_create_nonce( 'agent_role_save_agent_' . $next );
		$_REQUEST['agent_role_agent_nonce'] = $_POST['agent_role_agent_nonce'];
		$_POST['agent_role_persona']        = 'writer';
		$_POST['agent_role_persona_custom'] = '1';
		$_POST['agent_role_persona_action'] = 'save_default';
		$_POST['agent_role_caps']           = array( 'edit_posts', 'edit_published_posts', 'publish_posts', 'upload_files' );
		$_POST['agent_role_actions']        = array();
		$_POST['agent_role_instructions']   = Agent_Role::instructions_for( get_userdata( $next ) );

		$this->save_and_redirect();

		$this->assertSame( array( 'update_plugins' ), get_user_meta( $kept, Agent_Role_Admin::ACTIONS_META, true ) );
		$this->assertSame( '1', (string) get_user_meta( $kept, Agent_Role_Admin::PERSONA_CUSTOM_META, true ) );
		$kept_again = get_userdata( $kept );
		$this->assertTrue( $kept_again->has_cap( 'update_plugins' ) );
		$this->assertSame( array(), get_user_meta( $next, Agent_Role_Admin::ACTIONS_META, true ) );
	}

	public function test_reset_uses_the_saved_default_not_factory(): void {
		$admin = $this->make_user( 'administrator' );
		$agent = $this->make_agent();
		wp_set_current_user( $admin );

		Agent_Role_Admin::write_site_persona_default(
			'writer',
			array(
				'caps'      => array(
					'edit_posts'             => true,
					'edit_published_posts'   => true,
					'publish_posts'          => true,
					'upload_files'           => true,
					'delete_posts'           => false,
					'delete_published_posts' => false,
				),
				'actions'   => array( 'read_others' ),
				'abilities' => array(),
			)
		);

		update_user_meta( $agent, Agent_Role::INSTRUCTIONS_META, Agent_Role::instructions_for( get_userdata( $agent ) ) );
		update_user_meta( $agent, Agent_Role_Admin::PERSONA_META, 'writer' );
		update_user_meta( $agent, Agent_Role_Admin::PERSONA_CUSTOM_META, '1' );
		update_user_meta( $agent, Agent_Role_Admin::ACTIONS_META, array( 'update_plugins' ) );

		$_POST['user_id']                   = (string) $agent;
		$_POST['agent_role_agent_nonce']    = wp_create_nonce( 'agent_role_save_agent_' . $agent );
		$_REQUEST['agent_role_agent_nonce'] = $_POST['agent_role_agent_nonce'];
		$_POST['agent_role_persona']        = 'writer';
		$_POST['agent_role_persona_custom'] = '1';
		$_POST['agent_role_persona_action'] = 'reset';
		$_POST['agent_role_caps']           = array();
		$_POST['agent_role_actions']        = array( 'update_plugins' );
		$_POST['agent_role_instructions']   = Agent_Role::instructions_for( get_userdata( $agent ) );

		$this->save_and_redirect();

		$this->assertSame( array( 'read_others' ), get_user_meta( $agent, Agent_Role_Admin::ACTIONS_META, true ) );
		$this->assertSame( '', (string) get_user_meta( $agent, Agent_Role_Admin::PERSONA_CUSTOM_META, true ) );
		$this->assertTrue( Agent_Role_Admin::has_site_persona_default( 'writer' ) );
	}

	public function test_factory_default_clears_the_saved_set(): void {
		$admin = $this->make_user( 'administrator' );
		$agent = $this->make_agent();
		wp_set_current_user( $admin );

		Agent_Role_Admin::write_site_persona_default(
			'writer',
			array(
				'caps'      => array(
					'edit_posts'             => true,
					'edit_published_posts'   => true,
					'publish_posts'          => true,
					'upload_files'           => true,
					'delete_posts'           => false,
					'delete_published_posts' => false,
				),
				'actions'   => array( 'read_others' ),
				'abilities' => array(),
			)
		);

		update_user_meta( $agent, Agent_Role::INSTRUCTIONS_META, Agent_Role::instructions_for( get_userdata( $agent ) ) );

		$_POST['user_id']                   = (string) $agent;
		$_POST['agent_role_agent_nonce']    = wp_create_nonce( 'agent_role_save_agent_' . $agent );
		$_REQUEST['agent_role_agent_nonce'] = $_POST['agent_role_agent_nonce'];
		$_POST['agent_role_persona']        = 'writer';
		$_POST['agent_role_persona_action'] = 'factory';
		$_POST['agent_role_caps']           = array();
		$_POST['agent_role_actions']        = array( 'read_others' );
		$_POST['agent_role_instructions']   = Agent_Role::instructions_for( get_userdata( $agent ) );

		$this->save_and_redirect();

		$this->assertFalse( Agent_Role_Admin::has_site_persona_default( 'writer' ) );
		$this->assertSame( array(), get_user_meta( $agent, Agent_Role_Admin::ACTIONS_META, true ) );
	}

	private function save_and_redirect() {
		add_filter(
			'wp_redirect',
			static function () {
				throw new RuntimeException( 'redirect' );
			}
		);

		try {
			Agent_Role_Admin::handle_save_agent();
			$this->fail( 'The handler should redirect.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'redirect', $exception->getMessage() );
		}
	}

	private function register_catalog_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->fail( 'The Abilities API is not loaded.' );
		}

		$previous = isset( $GLOBALS['wp_filter']['wp_abilities_api_init'] ) ? $GLOBALS['wp_filter']['wp_abilities_api_init'] : null;
		remove_all_actions( 'wp_abilities_api_init' );
		add_action(
			'wp_abilities_api_init',
			static function () {
				PersonaCatalogTest::register_one(
					PersonaCatalogTest::READ,
					'Catalog read',
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					static function () {
						return true;
					}
				);
				PersonaCatalogTest::register_one(
					PersonaCatalogTest::CREATE,
					'Catalog create',
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					static function () {
						return true;
					}
				);
				PersonaCatalogTest::register_one(
					PersonaCatalogTest::UNDO,
					'Catalog undo',
					array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					static function () {
						return true;
					}
				);
				PersonaCatalogTest::register_one(
					PersonaCatalogTest::DELETE,
					'Catalog delete',
					array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					static function () {
						return true;
					}
				);
				PersonaCatalogTest::register_one(
					PersonaCatalogTest::OTHER,
					'Catalog other',
					array(
						'readonly'    => null,
						'destructive' => null,
						'idempotent'  => null,
					),
					static function () {
						return true;
					}
				);
				PersonaCatalogTest::register_one(
					PersonaCatalogTest::NEEDY,
					'Catalog needy',
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					static function () {
						return new WP_Error( 'needy', 'Needs a permission this agent does not have.' );
					}
				);
			}
		);
		do_action( 'wp_abilities_api_init', WP_Abilities_Registry::get_instance() );
		remove_all_actions( 'wp_abilities_api_init' );
		if ( $previous instanceof WP_Hook ) {
			$GLOBALS['wp_filter']['wp_abilities_api_init'] = $previous;
		}
	}

	/**
	 * @param string               $name        Ability name.
	 * @param string               $label       Visible label.
	 * @param array<string,mixed>  $annotations Filing marks.
	 * @param callable             $permission  Permission callback.
	 */
	public static function register_one( $name, $label, array $annotations, $permission ) {
		$registry = WP_Abilities_Registry::get_instance();
		if ( $registry && $registry->is_registered( $name ) ) {
			return;
		}

		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $label,
				'category'            => 'site',
				'permission_callback' => $permission,
				'execute_callback'    => static function () {
					return true;
				},
				'meta'                => array(
					'public'      => true,
					'annotations' => $annotations,
				),
			)
		);
	}

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
