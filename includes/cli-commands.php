<?php
/**
 * WP CLI commands for Index WP Users For Speed plugin
 *
 * @package Index_Wp_Users_For_Speed
 */

namespace IndexWpUsersForSpeed;

use WP_CLI;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage user indexing operations for Index WP Users For Speed plugin.
 */
class CLI_Commands {

	/**
	 * Populate the meta index for user roles.
	 *
	 * This command processes all users and creates metadata indexes for their roles,
	 * which significantly improves the performance of queries that search for users by role.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<size>]
	 * : Number of users to process per batch. Default: 5000
	 *
	 * [--chunk-size=<size>]
	 * : Number of users to process per transaction within each batch. Default: 50
	 *
	 * [--site-id=<id>]
	 * : Site ID for multisite installations. Default: current blog ID
	 *
	 * ## EXAMPLES
	 *
	 *     # Run with default settings
	 *     wp index-wp-users populate-meta-index-roles
	 *
	 *     # Run with custom batch size
	 *     wp index-wp-users populate-meta-index-roles --batch-size=10000
	 *
	 *     # Run for a specific site in multisite
	 *     wp index-wp-users populate-meta-index-roles --site-id=2
	 *
	 * @subcommand populate-meta-index-roles
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function populate_meta_index_roles( $args, $assoc_args ) {
		global $wpdb;

		// Parse arguments with defaults
		$batch_size = isset( $assoc_args['batch-size'] ) ? intval( $assoc_args['batch-size'] ) : INDEX_WP_USERS_FOR_SPEED_BATCHSIZE;
		$chunk_size = isset( $assoc_args['chunk-size'] ) ? intval( $assoc_args['chunk-size'] ) : INDEX_WP_USERS_FOR_SPEED_CHUNKSIZE;
		$site_id    = isset( $assoc_args['site-id'] ) ? intval( $assoc_args['site-id'] ) : get_current_blog_id();

		// Switch to the specified site in multisite
		if ( is_multisite() ) {
			switch_to_blog( $site_id );
		}

		WP_CLI::log( 'Starting populate meta index roles task...' );
		WP_CLI::log( sprintf( 'Batch size: %d, Chunk size: %d', $batch_size, $chunk_size ) );

		// Get user statistics
		$actual_user_count = $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->users" );
		$max_user_id       = $wpdb->get_var( "SELECT MAX(ID) FROM $wpdb->users" );
		$min_user_id       = $wpdb->get_var( "SELECT MIN(ID) FROM $wpdb->users" );

		WP_CLI::log( sprintf( 'Total users: %s', number_format( $actual_user_count ) ) );
		WP_CLI::log( sprintf( 'User ID range: %s to %s', number_format( $min_user_id ), number_format( $max_user_id ) ) );

		// Get available roles
		$roles      = wp_roles();
		$role_names = $roles->get_names();
		$role_list  = array_keys( $role_names );

		WP_CLI::log( sprintf( 'Indexing %d roles', count( $role_list ) ) );

		// Calculate number of batches based on actual user count
		$total_batches = ceil( $actual_user_count / $batch_size );
		WP_CLI::log( sprintf( 'Processing %d batches of %d users each', $total_batches, $batch_size ) );

		// Create progress bar
		$progress = \WP_CLI\Utils\make_progress_bar( 'Processing users', $total_batches );

		$offset      = 0;
		$batch_count = 0;

		// Process users in batches based on actual user IDs, not ID ranges
		while ( $offset < $actual_user_count ) {
			$batch_count++;

			// Get actual user IDs for this batch
			$user_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM $wpdb->users ORDER BY ID LIMIT %d OFFSET %d",
				$batch_size,
				$offset
			) );

			if ( empty( $user_ids ) ) {
				break;
			}

			// Process this batch in chunks to avoid locking
			$this->process_user_batch( $user_ids, $chunk_size, $role_list );

			// Memory cleanup after each batch
			$this->in_memory_cleanup();

			// Update progress
			$progress->tick();
			$offset += $batch_size;

			// Log progress every 10 batches
			if ( $batch_count % 10 === 0 ) {
				$current_progress = ( $offset / $actual_user_count ) * 100;
				WP_CLI::log( sprintf(
					'Progress: %.1f%% (%s/%s users)',
					min( 100, $current_progress ),
					number_format( min( $offset, $actual_user_count ) ),
					number_format( $actual_user_count )
				) );
			}
		}

		$progress->finish();

		// Update table statistics
		WP_CLI::log( 'Updating table statistics...' );
		$wpdb->query( "ANALYZE TABLE $wpdb->usermeta" );

		// Restore blog if multisite
		if ( is_multisite() ) {
			restore_current_blog();
		}

		WP_CLI::success( sprintf(
			'Completed! Processed %s users in %d batches. All user role metadata indexes have been created.',
			number_format( $actual_user_count ),
			$batch_count
		) );
	}

	/**
	 * Process a batch of users by their IDs.
	 *
	 * @param array $user_ids   Array of user IDs to process.
	 * @param int   $chunk_size Number of users per transaction.
	 * @param array $roles      Array of role names to index.
	 */
	private function process_user_batch( $user_ids, $chunk_size, $roles ) {
		global $wpdb;

		$prefix          = $wpdb->prefix . INDEX_WP_USERS_FOR_SPEED_KEY_PREFIX . 'r:';
		$capabilitiesKey = $wpdb->prefix . 'capabilities';

		// Split user IDs into chunks for transaction safety
		$chunks = array_chunk( $user_ids, $chunk_size );

		foreach ( $chunks as $chunk ) {
			// Create a comma-separated list of user IDs for the WHERE IN clause
			$user_id_list = implode( ',', array_map( 'intval', $chunk ) );

			// Start transaction
			$wpdb->query( 'BEGIN' );

			// Lock the relevant rows
			$wpdb->query( $wpdb->prepare(
				"SELECT COUNT(*) FROM $wpdb->usermeta WHERE meta_key = %s AND user_id IN ($user_id_list) FOR UPDATE",
				$capabilitiesKey
			) );

			// Build and execute queries for each role
			foreach ( $roles as $role ) {
				$prefixedRole = $prefix . $role;
				$escapedRole  = $wpdb->esc_like( $role );

				// Delete incorrect role metadata
				$deleteQuery = "DELETE a FROM $wpdb->usermeta a
					LEFT JOIN $wpdb->usermeta b
						ON a.user_id = b.user_id
						AND b.meta_key = %s
						AND b.meta_value LIKE CONCAT('%%', %s, '%%')
					WHERE a.meta_key = %s
						AND b.umeta_id IS NULL
						AND a.user_id IN ($user_id_list)";

				$wpdb->query( $wpdb->prepare( $deleteQuery, $capabilitiesKey, $escapedRole, $prefixedRole ) );

				// Insert missing role metadata
				$insertQuery = "INSERT INTO $wpdb->usermeta (user_id, meta_key)
					SELECT a.user_id, %s
					FROM $wpdb->usermeta a
					LEFT JOIN $wpdb->usermeta b
						ON a.user_id = b.user_id
						AND b.meta_key = %s
					WHERE a.meta_key = %s
						AND a.meta_value LIKE CONCAT('%%', %s, '%%')
						AND b.user_id IS NULL
						AND a.user_id IN ($user_id_list)";

				$wpdb->query( $wpdb->prepare( $insertQuery, $prefixedRole, $prefixedRole, $capabilitiesKey, $escapedRole ) );
			}

			// Commit transaction
			$wpdb->query( 'COMMIT' );
		}
	}

	/**
	 * Rebuild all user indexes.
	 *
	 * This command triggers a complete rebuild of all user indexes including role metadata,
	 * user counts, and editor caches.
	 *
	 * ## EXAMPLES
	 *
	 *     wp index-wp-users rebuild
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function rebuild( $args, $assoc_args ) {
		WP_CLI::log( 'Starting full rebuild of user indexes...' );

		$indexer = Indexer::getInstance();
		$indexer->rebuildNow();

		WP_CLI::success( 'All user indexes have been scheduled for rebuild.' );
		WP_CLI::log( 'The indexes will be built via WP-Cron. Check your cron jobs for progress.' );
	}

	/**
	 * Clean up user indexes.
	 *
	 * This command removes all metadata indexes created by the plugin.
	 *
	 * ## EXAMPLES
	 *
	 *     wp index-wp-users cleanup
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cleanup( $args, $assoc_args ) {
		WP_CLI::confirm( 'Are you sure you want to remove all user metadata indexes?', $assoc_args );

		WP_CLI::log( 'Starting cleanup of user indexes...' );

		$indexer = Indexer::getInstance();
		$indexer->cleanupNow();

		WP_CLI::success( 'All user metadata indexes have been removed.' );
	}

	/**
	 * In-memory cleanup to prevent memory issues during bulk operations.
	 *
	 * This function is based on VIP's memory management best practices.
	 * It clears the local object cache and database query log to prevent
	 * memory exhaustion during long-running operations.
	 *
	 * Duplicates functionality from:
	 * - https://github.com/Automattic/vip-go-mu-plugins/blob/HEAD/vip-helpers/vip-wp-cli.php
	 * - https://github.com/Automattic/vip-go-mu-plugins/blob/develop/vip-helpers/vip-caching.php
	 *
	 * @return void
	 */
	private function in_memory_cleanup() {
		global $wpdb, $wp_object_cache;

		// Reset database query log to free memory
		if ( isset( $wpdb->queries ) && is_array( $wpdb->queries ) ) {
			$wpdb->queries = array();
		}

		// Reset local object cache
		// This clears the in-memory cache without affecting Memcached or Redis
		if ( is_object( $wp_object_cache ) ) {
			// Clear the local cache array
			if ( isset( $wp_object_cache->cache ) ) {
				$wp_object_cache->cache = array();
			}

			// Clear group cache if it exists
			if ( isset( $wp_object_cache->group_cache ) ) {
				$wp_object_cache->group_cache = array();
			}

			// Clear stats if they exist
			if ( isset( $wp_object_cache->stats ) ) {
				$wp_object_cache->stats = array( 'get' => 0, 'add' => 0, 'delete' => 0 );
			}

			// Some object cache implementations have different properties
			if ( method_exists( $wp_object_cache, '__remoteset' ) ) {
				// For Memcached-based implementations
				$wp_object_cache->__remoteset();
			}
		}

		// Clean term cache for commonly accessed terms
		// This prevents term cache from growing too large
		$this->clean_term_cache();

		// If wp_cache_flush_runtime exists (VIP-specific), use it
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
	}

	/**
	 * Clean term cache to prevent memory bloat.
	 *
	 * Based on VIP's wpcom_vip_clean_term_cache() function.
	 * Removes terms from the local cache while preserving persistent cache.
	 *
	 * @return void
	 */
	private function clean_term_cache() {
		global $wp_object_cache;

		if ( ! is_object( $wp_object_cache ) ) {
			return;
		}

		// Get the cache key prefix for terms
		$cache_group = 'terms';

		// Clear the terms group from local cache
		if ( isset( $wp_object_cache->cache[ $cache_group ] ) ) {
			$wp_object_cache->cache[ $cache_group ] = array();
		}

		// Also clear any taxonomy-specific caches
		$taxonomies = get_taxonomies();
		foreach ( $taxonomies as $taxonomy ) {
			if ( isset( $wp_object_cache->cache[ $taxonomy ] ) ) {
				$wp_object_cache->cache[ $taxonomy ] = array();
			}
		}
	}
}

// Register WP-CLI commands
WP_CLI::add_command( 'index-wp-users', __NAMESPACE__ . '\CLI_Commands' );
