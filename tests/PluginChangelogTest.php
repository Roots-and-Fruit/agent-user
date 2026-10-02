<?php
/**
 * Changelog ability against real WordPress.org changelog HTML.
 *
 * The fixtures are sections.changelog as plugins_api() returned it on 2026-10-01.
 *
 * @package Agent_Role
 */

use PHPUnit\Framework\TestCase;

/**
 * Covers version sections, version ranges, and the plugins_api() call. The live call is checked over HTTP, outside PHPUnit.
 */
class PluginChangelogTest extends TestCase {

	/**
	 * Users created by the current test.
	 *
	 * @var int[]
	 */
	private $user_ids = array();

	/**
	 * WordPress.org API URLs requested during the test.
	 *
	 * @var string[]
	 */
	private $http = array();

	protected function setUp(): void {
		parent::setUp();
		Agent_Role::activate();
		$this->user_ids = array();
		$this->http     = array();
	}

	protected function tearDown(): void {
		foreach ( $this->user_ids as $user_id ) {
			if ( get_userdata( $user_id ) ) {
				wp_delete_user( $user_id );
			}
		}
		remove_all_filters( 'pre_http_request' );
		remove_filter( 'wp_trigger_error_trigger_error', '__return_false' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_changelog_ability_is_registered_as_an_open_world_read(): void {
		$ability = wp_get_ability( Agent_Role_Plugin_Updates::CHANGELOG );
		$this->assertNotNull( $ability );
		$this->assertSame( Agent_Role_Plugin_Updates::CATEGORY, $ability->get_category() );
		$this->assertSame( 'read', Agent_Role_Admin::file_ability_group( $ability ) );
		$meta = $ability->get_meta();
		$this->assertTrue( $meta['annotations']['readonly'] );
		$this->assertTrue( $meta['annotations']['openWorldHint'] );
	}

	public function test_one_version_stops_at_the_next_version_heading_not_a_sub_heading(): void {
		$result = Agent_Role_Plugin_Updates::changelog_sections( $this->fixture( 'wordpress-seo' ), '28.6' );

		$this->assertSame( array( '28.6' ), wp_list_pluck( $result, 'version' ) );
		$text = $result[0]['text'];
		$this->assertStringContainsString( 'Enhancements', $text );
		$this->assertStringContainsString( 'Bugfixes', $text );
		$this->assertStringContainsString( '- Introduces 2 new Yoast Abilities', $text );
		$this->assertStringNotContainsString( '<', $text );
	}

	public function test_a_short_version_does_not_match_a_longer_one(): void {
		$html = $this->fixture( 'akismet' );

		$short = Agent_Role_Plugin_Updates::changelog_sections( $html, '5.7' );
		$this->assertSame( array( '5.7' ), wp_list_pluck( $short, 'version' ) );
		$this->assertStringContainsString( 'Add Abilities API support', $short[0]['text'] );
		$this->assertStringNotContainsString( 'Fluent Forms', $short[0]['text'] );

		$long = Agent_Role_Plugin_Updates::changelog_sections( $html, '5.7.1' );
		$this->assertSame( array( '5.7.1' ), wp_list_pluck( $long, 'version' ) );
		$this->assertStringContainsString( 'link styling', $long[0]['text'] );
	}

	public function test_installed_version_returns_every_newer_section_up_to_the_offer(): void {
		$result = Agent_Role_Plugin_Updates::changelog_sections( $this->fixture( 'user-switching' ), '1.12.2', '1.11.1' );

		$this->assertSame( array( '1.12.2', '1.12.1', '1.12.0', '1.11.2' ), wp_list_pluck( $result, 'version' ) );
		$this->assertStringContainsString( 'expired nonce', $result[3]['text'] );
		$this->assertStringContainsString( "command palette integration\n- Added ARIA labels", $result[0]['text'] );
	}

	public function test_a_heading_with_a_date_after_the_version_still_matches(): void {
		$result = Agent_Role_Plugin_Updates::changelog_sections( $this->fixture( 'woocommerce' ), '11.1.2' );

		$this->assertSame( array( '11.1.2' ), wp_list_pluck( $result, 'version' ) );
		$this->assertStringContainsString( 'comment pipeline', $result[0]['text'] );
	}

	public function test_a_version_the_changelog_does_not_list_is_empty(): void {
		$this->assertSame( array(), Agent_Role_Plugin_Updates::changelog_sections( $this->fixture( 'woocommerce' ), '11.1.1' ) );
		$this->assertSame( array(), Agent_Role_Plugin_Updates::changelog_sections( '', '1.0' ) );
	}

	public function test_web_dev_reads_a_changelog_through_plugins_api_and_editor_cannot(): void {
		$this->serve_wporg(
			200,
			wp_json_encode(
				array(
					'name'     => 'User Switching',
					'slug'     => 'user-switching',
					'version'  => '1.12.2',
					'sections' => array( 'changelog' => $this->fixture( 'user-switching' ) ),
					'versions' => array( '1.12.1' => 'https://downloads.wordpress.org/plugin/user-switching.1.12.1.zip' ),
				)
			)
		);

		wp_set_current_user( $this->make_agent( 'webdev' ) );
		$ability = wp_get_ability( Agent_Role_Plugin_Updates::CHANGELOG );
		$input   = array(
			'slug'      => 'user-switching',
			'version'   => '1.12.2',
			'installed' => '1.12.0',
		);
		$result  = $ability->execute( $input );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['found'] );
		$this->assertSame( array( '1.12.2', '1.12.1' ), wp_list_pluck( $result['sections'], 'version' ) );
		$this->assertContains( '1.11.2', $result['listed_versions'] );

		$this->assertCount( 1, $this->http );
		$this->assertStringStartsWith( 'https://api.wordpress.org/plugins/info/1.2/', $this->http[0] );
		$this->assertStringContainsString( 'request%5Bslug%5D=user-switching', $this->http[0] );
		$this->assertStringContainsString( 'request%5Bfields%5D%5Bsections%5D=1', $this->http[0] );

		wp_set_current_user( $this->make_agent( 'editor' ) );
		$this->assertNotTrue( $ability->check_permissions( $input ) );
	}

	public function test_an_unknown_plugin_is_a_clean_error(): void {
		$this->serve_wporg( 404, wp_json_encode( array( 'error' => 'Plugin not found.' ) ) );

		wp_set_current_user( $this->make_agent( 'webdev' ) );
		$result = wp_get_ability( Agent_Role_Plugin_Updates::CHANGELOG )->execute(
			array(
				'slug'    => 'agent-role-no-such-plugin',
				'version' => '1.0.0',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agent_role_plugin_info', $result->get_error_code() );
		$this->assertSame( 'Plugin not found.', $result->get_error_message() );
		$this->assertSame( array( 'status' => 502 ), $result->get_error_data() );
	}

	public function test_an_unreachable_wordpress_org_is_a_plain_text_error(): void {
		$this->serve_wporg( 0, '' );
		add_filter( 'wp_trigger_error_trigger_error', '__return_false' );

		wp_set_current_user( $this->make_agent( 'webdev' ) );
		$result = wp_get_ability( Agent_Role_Plugin_Updates::CHANGELOG )->execute(
			array(
				'slug'    => 'user-switching',
				'version' => '1.12.2',
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agent_role_plugin_info', $result->get_error_code() );
		$this->assertStringContainsString( 'WordPress.org', $result->get_error_message() );
		$this->assertStringNotContainsString( '<', $result->get_error_message() );
		$this->assertSame( array( 'status' => 502 ), $result->get_error_data() );
		$this->assertCount( 2, $this->http, 'Core retries over HTTP after HTTPS fails.' );
	}

	/**
	 * Answer api.wordpress.org requests at the HTTP layer so plugins_api() runs for real.
	 *
	 * @param int    $status Status code, or 0 for a connection failure.
	 * @param string $body   Response body.
	 */
	private function serve_wporg( $status, $body ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $status, $body ) {
				unset( $args );
				if ( false === strpos( $url, 'api.wordpress.org/plugins/info/' ) ) {
					return $pre;
				}
				$this->http[] = $url;
				if ( 0 === $status ) {
					return new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out' );
				}
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => $body,
					'response' => array(
						'code'    => $status,
						'message' => get_status_header_desc( $status ),
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	private function fixture( $slug ) {
		$html = file_get_contents( __DIR__ . '/fixtures/changelog-' . $slug . '.html' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$this->assertIsString( $html );
		return $html;
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
