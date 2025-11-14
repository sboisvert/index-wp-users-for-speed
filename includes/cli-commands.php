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
	 * [--timeout=<seconds>]
	 * : Runtime limit in seconds per chunk. Default: 0 (no limit)
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
		// Parse arguments with defaults
		$batch_size = isset( $assoc_args['batch-size'] ) ? intval( $assoc_args['batch-size'] ) : INDEX_WP_USERS_FOR_SPEED_BATCHSIZE;
		$chunk_size = isset( $assoc_args['chunk-size'] ) ? intval( $assoc_args['chunk-size'] ) : INDEX_WP_USERS_FOR_SPEED_CHUNKSIZE;
		$site_id    = isset( $assoc_args['site-id'] ) ? intval( $assoc_args['site-id'] ) : null;
		$timeout    = isset( $assoc_args['timeout'] ) ? intval( $assoc_args['timeout'] ) : 0;

		WP_CLI::log( 'Starting populate meta index roles task...' );
		WP_CLI::log( sprintf( 'Batch size: %d, Chunk size: %d', $batch_size, $chunk_size ) );

		// Create and initialize the task
		$task = new PopulateMetaIndexRoles( $batch_size, $chunk_size, $site_id, $timeout );
		$task->init();

		// Get actual user count for better progress tracking
		global $wpdb;
		$actual_user_count = $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->users" );
		$max_user_id       = $task->maxUserId;

		WP_CLI::log( sprintf( 'Total users: %s', number_format( $actual_user_count ) ) );
		WP_CLI::log( sprintf( 'Max user ID: %s', number_format( $max_user_id ) ) );
		WP_CLI::log( sprintf( 'Indexing %d roles', count( $task->roles ) ) );

		// Use actual user count for progress bar
		$expected_chunks = ceil( $actual_user_count / $batch_size );
		WP_CLI::log( sprintf( 'Estimated chunks: %d (may process more due to gaps in user IDs)', $expected_chunks ) );

		// Create progress bar based on user ID range
		$progress = \WP_CLI\Utils\make_progress_bar( 'Processing user ID ranges', ceil( $max_user_id / $batch_size ) );

		$chunk_count       = 0;
		$done              = false;
		$last_log_progress = 0;

		// Process chunks until complete
		while ( ! $done ) {
			$done = $task->doChunk();
			$chunk_count++;

			// Memory cleanup after each chunk
			$this->in_memory_cleanup();

			// Update progress
			$progress->tick();

			// Log progress every 5% or every 10 chunks, whichever comes first
			$current_progress = $task->fractionComplete * 100;
			if ( ( $current_progress - $last_log_progress >= 5 ) || ( $chunk_count % 10 === 0 ) ) {
				WP_CLI::log( sprintf(
					'Progress: %.1f%% (user ID range: %s/%s)',
					$current_progress,
					number_format( $task->currentStart ),
					number_format( $max_user_id )
				) );
				$last_log_progress = $current_progress;
			}
		}

		$progress->finish();

		WP_CLI::success( sprintf(
			'Completed! Processed %d chunks covering user IDs 0-%s. All user role metadata indexes have been created.',
			$chunk_count,
			number_format( $max_user_id )
		) );
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
