<?php
/**
 * wp-rollback/rollback, run through WP Rollback's own steps.
 *
 * Every reference to WP Rollback's classes lives in this file.
 *
 * @package Agent_Role
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the rollback ability while WP Rollback is active.
 */
class Agent_Role_Wp_Rollback {

	const SHARED_CORE = '\WpRollback\SharedCore\Core\SharedCore';

	const REGISTERER = '\WpRollback\SharedCore\Rollbacks\Registry\RollbackStepRegisterer';

	const DTO = '\WpRollback\SharedCore\Rollbacks\DTO\RollbackApiRequestDTO';

	const MAINTENANCE = '\WpRollback\SharedCore\Rollbacks\Services\MaintenanceService';

	/**
	 * Hook registration. The ability is added after the Agent Role category exists.
	 */
	public static function register() {
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_ability' ) );
	}

	/**
	 * Whether WP Rollback has booted and its step registry is ready.
	 *
	 * Before wpr_init the container returns an empty registry, so that action is part of the check.
	 */
	public static function is_available() {
		if ( ! did_action( 'wpr_init' ) || ! class_exists( self::SHARED_CORE ) || ! class_exists( self::REGISTERER ) ) {
			return false;
		}

		$core = self::SHARED_CORE;
		return $core::container()->has( ltrim( self::REGISTERER, '\\' ) );
	}

	/**
	 * Register wp-rollback/rollback when WP Rollback runs.
	 */
	public static function register_ability() {
		if ( ! function_exists( 'wp_register_ability' ) || ! self::is_available() ) {
			return;
		}

		wp_register_ability(
			Agent_Role_Plugin_Updates::ROLLBACK,
			array(
				'label'               => __( 'Roll back a plugin', 'agent-role' ),
				'description'         => __( 'Replace an installed plugin with an older version from WordPress.org, using WP Rollback. Use it when an update could not be restored, or when the user names a version.', 'agent-role' ),
				'category'            => Agent_Role_Plugin_Updates::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'slug'    => array(
							'type'        => 'string',
							'pattern'     => '^[a-z0-9][a-z0-9-]*$',
							'description' => __( 'WordPress.org plugin slug, the folder name of the installed plugin.', 'agent-role' ),
						),
						'version' => array(
							'type'        => 'string',
							'description' => __( 'Version to install. It must be listed on WordPress.org.', 'agent-role' ),
						),
					),
					'required'             => array( 'slug', 'version' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'outcome'        => array(
							'type' => 'string',
							'enum' => array( 'rolled_back', 'failed' ),
						),
						'slug'           => array( 'type' => 'string' ),
						'from_version'   => array( 'type' => 'string' ),
						'to_version'     => array( 'type' => 'string' ),
						'homepage_fatal' => array( 'type' => 'boolean' ),
						'message'        => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'rollback' ),
				'permission_callback' => array( 'Agent_Role_Plugin_Updates', 'can_update' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'      => false,
						'destructive'   => true,
						'idempotent'    => false,
						'openWorldHint' => true,
					),
					'public'       => true,
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	/**
	 * Check the request, take the lock, then run WP Rollback's steps.
	 *
	 * @param array{slug:string,version:string} $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function rollback( $input ) {
		$slug    = (string) $input['slug'];
		$version = (string) $input['version'];
		$file    = self::installed_file( $slug );
		if ( '' === $file ) {
			/* translators: %s: plugin slug. */
			return self::outcome( 'failed', $slug, '', $version, false, sprintf( __( 'The plugin %s is not installed. WP Rollback can only roll back a plugin in its own folder.', 'agent-role' ), $slug ) );
		}

		$installed = (string) get_plugins()[ $file ]['Version'];
		if ( version_compare( $installed, $version, '==' ) ) {
			/* translators: %s: version. */
			return self::outcome( 'failed', $slug, $installed, $version, false, sprintf( __( 'Version %s is already installed.', 'agent-role' ), $version ) );
		}

		$problem = self::version_problem( $slug, $version );
		if ( '' !== $problem ) {
			return self::outcome( 'failed', $slug, $installed, $version, false, $problem );
		}

		if ( ! Agent_Role_Plugin_Updates::take_lock() ) {
			return self::outcome( 'failed', $slug, $installed, $version, false, __( 'Another plugin update or rollback is running. Try again in a few minutes.', 'agent-role' ) );
		}

		try {
			return self::run_steps( $slug, $version, $installed );
		} finally {
			Agent_Role_Plugin_Updates::release_lock();
			delete_transient( "wpr_plugin_{$slug}_download_url" );
		}
	}

	/**
	 * Run every registered step in order with one request object, as WP Rollback's screen does.
	 *
	 * @param string $slug      Plugin slug.
	 * @param string $version   Target version.
	 * @param string $installed Version installed before the call.
	 * @return array<string,mixed>
	 */
	private static function run_steps( $slug, $version, $installed ) {
		if ( ! defined( 'WPR_ROLLBACK_ACTIVE' ) ) {
			define( 'WPR_ROLLBACK_ACTIVE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP Rollback's own flag; its steps read it.
		}
		add_action( 'shutdown', array( __CLASS__, 'force_maintenance_off' ) );

		$core     = self::SHARED_CORE;
		$dto_type = self::DTO;
		$replaced = null;
		$failure  = '';
		try {
			$dto   = new $dto_type( 'plugin', $slug, $version );
			$steps = $core::container()->make( ltrim( self::REGISTERER, '\\' ) )->getAllRollbackSteps();
			foreach ( $steps as $class ) {
				$result = $core::container()->make( $class )->execute( $dto );
				if ( ! $result->isSuccess() ) {
					/* translators: %s: WP Rollback step id. */
					$failure = '' !== $result->getMessage() ? $result->getMessage() : sprintf( __( 'WP Rollback step %s failed.', 'agent-role' ), $class::id() );
					break;
				}
				if ( 'replace-asset' === $class::id() ) {
					$replaced = $result->getData();
				}
			}
		} catch ( \Throwable $e ) {
			$failure = $e->getMessage();
		}

		if ( null === $replaced ) {
			return self::fail( $slug, $version, $installed, $failure, isset( $dto ) ? $dto : null );
		}

		remove_action( 'shutdown', array( __CLASS__, 'force_maintenance_off' ) );
		self::force_maintenance_off();
		$from = isset( $replaced['current_version'] ) && '' !== $replaced['current_version'] ? (string) $replaced['current_version'] : $installed;
		if ( isset( $replaced['asset_path'] ) ) {
			do_action( 'wpr_plugin_rollback_success', $replaced['asset_path'], $from, $dto->getMeta() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Rollback's own action.
		}

		return self::report( $slug, $from, $version, $failure );
	}

	/**
	 * Undo maintenance mode and tell WP Rollback's listeners the rollback failed.
	 *
	 * @param string      $slug      Plugin slug.
	 * @param string      $version   Target version.
	 * @param string      $installed Version installed before the call.
	 * @param string      $failure   What went wrong.
	 * @param object|null $dto       Request object, when one was built.
	 * @return array<string,mixed>
	 */
	private static function fail( $slug, $version, $installed, $failure, $dto ) {
		if ( null !== $dto ) {
			try {
				$core = self::SHARED_CORE;
				$core::container()->make( 'WpRollback\SharedCore\Rollbacks\RollbackSteps\Cleanup' )->execute( $dto );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		remove_action( 'shutdown', array( __CLASS__, 'force_maintenance_off' ) );
		self::force_maintenance_off();
		do_action( 'wpr_plugin_rollback_failed', $slug, $version, $failure ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP Rollback's own action.

		/* translators: %s: what went wrong. */
		return self::outcome( 'failed', $slug, $installed, $version, false, sprintf( __( 'The rollback did not finish: %s', 'agent-role' ), wp_strip_all_tags( $failure ) ) );
	}

	/**
	 * Check the homepage after the files were replaced. The result is reported, not undone.
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $from    Version before the rollback.
	 * @param string $version Version now installed.
	 * @param string $note    A late step's warning, or empty.
	 * @return array<string,mixed>
	 */
	private static function report( $slug, $from, $version, $note ) {
		if ( function_exists( 'wp_opcache_invalidate_directory' ) ) {
			wp_opcache_invalidate_directory( WP_PLUGIN_DIR . '/' . $slug );
		}
		$fatal = Agent_Role_Plugin_Updates::scrape_for_fatal();
		/* translators: 1: old version, 2: new version. */
		$message  = sprintf( __( 'Rolled back from %1$s to %2$s.', 'agent-role' ), $from, $version );
		$message .= true === $fatal ? ' ' . __( 'The homepage loads without a fatal error.', 'agent-role' ) : ' ' . $fatal;
		if ( '' !== $note ) {
			$message .= ' ' . wp_strip_all_tags( $note );
		}

		return self::outcome( 'rolled_back', $slug, $from, $version, true !== $fatal, $message );
	}

	/**
	 * Empty when WordPress.org lists the version, otherwise why not.
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $version Target version.
	 */
	private static function version_problem( $slug, $version ) {
		$info = Agent_Role_Plugin_Updates::plugin_info( $slug );
		if ( is_wp_error( $info ) ) {
			return $info->get_error_message();
		}

		$listed = array_diff( array_keys( isset( $info->versions ) ? (array) $info->versions : array() ), array( 'trunk' ) );
		if ( in_array( $version, array_map( 'strval', $listed ), true ) ) {
			return '';
		}
		usort( $listed, 'version_compare' );

		/* translators: 1: requested version, 2: comma-separated recent versions. */
		return sprintf( __( 'WordPress.org does not list version %1$s. Recent versions: %2$s.', 'agent-role' ), $version, implode( ', ', array_slice( array_reverse( $listed ), 0, 5 ) ) );
	}

	/**
	 * Plugin file inside the slug's folder, or empty.
	 *
	 * @param string $slug Plugin slug.
	 */
	private static function installed_file( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( 0 === strpos( $file, $slug . '/' ) ) {
				return $file;
			}
		}

		return '';
	}

	/**
	 * Take the site out of WP Rollback's maintenance mode. Also the shutdown safety net.
	 */
	public static function force_maintenance_off() {
		try {
			$core = self::SHARED_CORE;
			$core::container()->make( ltrim( self::MAINTENANCE, '\\' ) )->forceDisableMaintenanceMode();
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Rollback result in the shape the output schema describes.
	 *
	 * @param string $outcome rolled_back or failed.
	 * @param string $slug    Plugin slug.
	 * @param string $from    Version before the call.
	 * @param string $to      Requested version.
	 * @param bool   $fatal   Whether the homepage check found a fatal.
	 * @param string $message What happened, for the agent.
	 * @return array<string,mixed>
	 */
	private static function outcome( $outcome, $slug, $from, $to, $fatal, $message ) {
		return array(
			'outcome'        => $outcome,
			'slug'           => $slug,
			'from_version'   => $from,
			'to_version'     => $to,
			'homepage_fatal' => (bool) $fatal,
			'message'        => $message,
		);
	}
}
