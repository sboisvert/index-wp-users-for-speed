<?php
/**
 * WP CLI commands for Index WP Users For Speed plugin
 *
 * @package Index_Wp_Users_For_Speed
 */

namespace IndexWpUsersForSpeed;

use Exception;
use WP_CLI;
use function WP_CLI\Utils\make_progress_bar;
use function WP_CLI\Utils\mustache_render;

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
	 * This operation runs synchronously and may take a long time on sites with many users.
	 *
	 * For multisite installations, use the --url parameter to specify the site:
	 * wp index-wp-users populate-meta-index-roles --url=site2.example.com
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<size>]
	 * : Number of users to process per batch. Must be greater than chunk-size. Default: 5000
	 *
	 * [--chunk-size=<size>]
	 * : Number of users to process per transaction within each batch. Must be less than batch-size. Default: 50
	 *
	 * [--timeout=<seconds>]
	 * : Maximum time to spend on each chunk. 0 means no limit. Default: 0
	 *
	 * [--dry-run]
	 * : Show what would be done without making any changes.
	 *
	 * ## EXAMPLES
	 *
	 *     # Run with default settings
	 *     wp index-wp-users populate-meta-index-roles
	 *
	 *     # Run with custom batch and chunk sizes
	 *     wp index-wp-users populate-meta-index-roles --batch-size=10000 --chunk-size=100
	 *
	 *     # Run for a specific site in multisite
	 *     wp index-wp-users populate-meta-index-roles --url=site2.example.com
	 *
	 *     # Preview what would happen without making changes
	 *     wp index-wp-users populate-meta-index-roles --dry-run
	 *
	 *     # Run with timeout to prevent long-running operations per chunk
	 *     wp index-wp-users populate-meta-index-roles --timeout=30
	 *
	 * @subcommand populate-meta-index-roles
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function populate_meta_index_roles( $args, $assoc_args ) {
		global $wpdb;

		// Parse and validate arguments with defaults
		$batch_size = isset( $assoc_args['batch-size'] ) ? intval( $assoc_args['batch-size'] ) : INDEX_WP_USERS_FOR_SPEED_BATCHSIZE;
		$chunk_size = isset( $assoc_args['chunk-size'] ) ? intval( $assoc_args['chunk-size'] ) : INDEX_WP_USERS_FOR_SPEED_CHUNKSIZE;
		$timeout    = isset( $assoc_args['timeout'] ) ? intval( $assoc_args['timeout'] ) : 0;
		$dry_run    = isset( $assoc_args['dry-run'] );

		// Validate parameters
		if ( $batch_size <= 0 ) {
			WP_CLI::error( 'batch-size must be a positive integer.' );
		}
		if ( $chunk_size <= 0 ) {
			WP_CLI::error( 'chunk-size must be a positive integer.' );
		}
		if ( $chunk_size >= $batch_size ) {
			WP_CLI::error( 'chunk-size must be less than batch-size.' );
		}
		if ( $timeout < 0 ) {
			WP_CLI::error( 'timeout must be zero or a positive integer.' );
		}

		if ( $dry_run ) {
			WP_CLI::log( WP_CLI::colorize( '%Y[DRY RUN MODE]%n No changes will be made.' ) );
		}

		WP_CLI::log( 'Starting populate meta index roles task...' );
		WP_CLI::log( sprintf( 'Batch size: %d, Chunk size: %d, Timeout: %d seconds', $batch_size, $chunk_size, $timeout ) );

		// Use the existing PopulateMetaIndexRoles task class for consistency and safety
		// This avoids duplicating complex SQL logic and potential SQL injection issues
		try {
			$task = new PopulateMetaIndexRoles( $batch_size, $chunk_size, null, $timeout );
			$task->init();

			// Get user statistics
			// Note: We show both actual user count AND the user ID range because
			// on some sites user IDs can start in the millions/billions (e.g., imported from other systems)
			// which makes ID range-based processing inefficient. Showing both helps diagnose such issues.
			$actual_user_count = $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->users" );
			$max_user_id       = $task->maxUserId;
			$min_user_id       = $wpdb->get_var( "SELECT MIN(ID) FROM $wpdb->users" );
			$roles             = $task->roles;

			WP_CLI::log( sprintf( 'Total users: %s', number_format( $actual_user_count ) ) );
			WP_CLI::log( sprintf( 'User ID range: %s to %s', number_format( $min_user_id ), number_format( $max_user_id ) ) );

			// Calculate ID range efficiency - warn if there's a big gap
			$id_range_size = $max_user_id - $min_user_id + 1;
			if ( $actual_user_count > 0 && $id_range_size / $actual_user_count > 10 ) {
				WP_CLI::warning(
					sprintf(
						'User ID range is sparse (%.1fx actual count). This may indicate non-sequential IDs.',
						$id_range_size / $actual_user_count
					)
				);
			}

			WP_CLI::log( sprintf( 'Indexing %d roles: %s', count( $roles ), implode( ', ', $roles ) ) );

			if ( $dry_run ) {
				WP_CLI::success( 'Dry run complete. No changes were made.' );
				return;
			}

			// Calculate approximate number of batches based on ID range
			$approximate_batches = ceil( $max_user_id / $batch_size );
			WP_CLI::log( sprintf( 'Processing approximately %d batches', $approximate_batches ) );

			// Create progress bar
			$progress = make_progress_bar( 'Processing users', $approximate_batches );

			$batch_count      = 0;
			$start_time       = time();
			$last_memory_info = 0;

			// Process using the task's doChunk method (safe, tested, consistent with cron jobs)
			while ( true ) {
				$done = $task->doChunk();
				++$batch_count;

				// Memory cleanup after each batch to prevent exhaustion on large sites
				$this->in_memory_cleanup();

				// Update progress
				$progress->tick();

				// Log detailed progress every 10 batches
				if ( $batch_count % 10 === 0 ) {
					$elapsed      = time() - $start_time;
					$progress_pct = $task->fractionComplete * 100;
					$memory_mb    = round( memory_get_usage() / 1024 / 1024, 2 );

					WP_CLI::log(
						sprintf(
							'Progress: %.1f%% | Batch %d | Elapsed: %s | Memory: %s MB',
							$progress_pct,
							$batch_count,
							$this->format_elapsed_time( $elapsed ),
							$memory_mb
						)
					);

					$last_memory_info = time();
				}

				// Show memory info every 5 minutes if not shown via batch logging
				if ( ( time() - $last_memory_info ) > 300 ) {
					$memory_mb = round( memory_get_usage() / 1024 / 1024, 2 );
					WP_CLI::log( sprintf( 'Current memory usage: %s MB', $memory_mb ) );
					$last_memory_info = time();
				}

				if ( $done ) {
					break;
				}
			}

			$progress->finish();

			$elapsed_total = time() - $start_time;

			// Note: ANALYZE TABLE was executed automatically by the task after the final batch
			// to update MySQL's query optimizer statistics for better query performance

			WP_CLI::success(
				sprintf(
					'Completed! Processed %d batches in %s. All user role metadata indexes have been created.',
					$batch_count,
					$this->format_elapsed_time( $elapsed_total )
				)
			);
		} catch ( Exception $e ) {
			WP_CLI::error( sprintf( 'Operation failed: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Rebuild all user indexes.
	 *
	 * This command performs a SYNCHRONOUS and DESTRUCTIVE rebuild of all user indexes.
	 * It first removes all existing indexes, then rebuilds them from scratch including
	 * role metadata, user counts, and editor caches. The rebuild runs immediately,
	 * blocks until completion, and shows progress.
	 *
	 * IMPORTANT: This differs from the admin "Rebuild Now" button, which schedules
	 * asynchronous background tasks via WP-Cron and does NOT remove existing indexes first.
	 * Use this CLI command for maintenance windows or when immediate completion is required.
	 *
	 * For multisite installations, use the --url parameter to specify the site.
	 *
	 * ## OPTIONS
	 *
	 * [--batch-size=<size>]
	 * : Number of users to process per batch. Default: 5000
	 *
	 * [--chunk-size=<size>]
	 * : Number of users per transaction. Default: 50
	 *
	 * [--timeout=<seconds>]
	 * : Maximum time to spend on each chunk. 0 means no limit. Default: 0
	 *
	 * ## EXAMPLES
	 *
	 *     # Rebuild all indexes immediately
	 *     wp index-wp-users rebuild
	 *
	 *     # Rebuild with custom batch size
	 *     wp index-wp-users rebuild --batch-size=10000
	 *
	 *     # Rebuild with timeout per chunk
	 *     wp index-wp-users rebuild --timeout=30
	 *
	 *     # Rebuild for a specific site in multisite
	 *     wp index-wp-users rebuild --url=site2.example.com
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function rebuild( $args, $assoc_args ) {
		$batch_size = isset( $assoc_args['batch-size'] ) ? intval( $assoc_args['batch-size'] ) : INDEX_WP_USERS_FOR_SPEED_BATCHSIZE;
		$chunk_size = isset( $assoc_args['chunk-size'] ) ? intval( $assoc_args['chunk-size'] ) : INDEX_WP_USERS_FOR_SPEED_CHUNKSIZE;
		$timeout    = isset( $assoc_args['timeout'] ) ? intval( $assoc_args['timeout'] ) : 0;

		// Validate parameters
		if ( $batch_size <= 0 ) {
			WP_CLI::error( 'batch-size must be a positive integer.' );
		}
		if ( $chunk_size <= 0 ) {
			WP_CLI::error( 'chunk-size must be a positive integer.' );
		}
		if ( $chunk_size >= $batch_size ) {
			WP_CLI::error( 'chunk-size must be less than batch-size.' );
		}
		if ( $timeout < 0 ) {
			WP_CLI::error( 'timeout must be zero or a positive integer.' );
		}

		WP_CLI::log( 'Starting full rebuild of user indexes...' );
		WP_CLI::log( 'This will: 1) Clean up old indexes, 2) Count users, 3) Identify editors, 4) Rebuild role indexes' );
		WP_CLI::log( sprintf( 'Batch size: %d, Chunk size: %d, Timeout: %d seconds', $batch_size, $chunk_size, $timeout ) );

		try {
			$indexer = Indexer::getInstance();

			// Step 1: Clean up existing indexes
			WP_CLI::log( 'Step 1/4: Cleaning up existing indexes...' );
			$indexer->removeNow();
			WP_CLI::log( '✓ Cleanup complete' );

			// Step 2: Count users
			WP_CLI::log( 'Step 2/4: Counting users by role...' );
			$count_task = new CountUsers();
			$count_task->init();
			while ( ! $count_task->doChunk() ) {
				$this->in_memory_cleanup();
			}
			WP_CLI::log( '✓ User counts updated' );

			// Step 3: Get editors
			WP_CLI::log( 'Step 3/4: Identifying users with edit capabilities...' );
			$editor_task = new GetEditors();
			$editor_task->init();
			while ( ! $editor_task->doChunk() ) {
				$this->in_memory_cleanup();
			}
			WP_CLI::log( '✓ Editor list updated' );

			// Step 4: Populate role indexes
			WP_CLI::log( 'Step 4/4: Populating role metadata indexes...' );
			$role_task = new PopulateMetaIndexRoles( $batch_size, $chunk_size, null, $timeout );
			$role_task->init();

			$batch_count = 0;
			$progress    = make_progress_bar(
				'Rebuilding indexes',
				ceil( $role_task->maxUserId / $batch_size )
			);

			while ( ! $role_task->doChunk() ) {
				++$batch_count;
				$this->in_memory_cleanup();
				$progress->tick();

				// Log progress periodically
				if ( $batch_count % 10 === 0 ) {
					$progress_pct = $role_task->fractionComplete * 100;
					$memory_mb    = round( memory_get_usage() / 1024 / 1024, 2 );
					WP_CLI::log( sprintf( 'Progress: %.1f%% | Memory: %s MB', $progress_pct, $memory_mb ) );
				}
			}

			$progress->finish();

			WP_CLI::success(
				sprintf(
					'Rebuild complete! Processed %d batches. All indexes are now up to date.',
					$batch_count
				)
			);
		} catch ( Exception $e ) {
			WP_CLI::error( sprintf( 'Rebuild failed: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Clean up user indexes.
	 *
	 * This command removes all metadata indexes created by the plugin.
	 * This is useful before deactivation or when troubleshooting issues.
	 *
	 * For multisite installations, use the --url parameter to specify the site.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation prompt
	 *
	 * ## EXAMPLES
	 *
	 *     # Clean up with confirmation prompt
	 *     wp index-wp-users cleanup
	 *
	 *     # Clean up without confirmation
	 *     wp index-wp-users cleanup --yes
	 *
	 *     # Clean up for a specific site in multisite
	 *     wp index-wp-users cleanup --url=site2.example.com --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cleanup( $args, $assoc_args ) {
		WP_CLI::confirm( 'Are you sure you want to remove all user metadata indexes?', $assoc_args );

		WP_CLI::log( 'Starting cleanup of user indexes...' );

		try {
			global $wpdb;
			$prefix = $wpdb->prefix . INDEX_WP_USERS_FOR_SPEED_KEY_PREFIX;

			// Count what we're about to delete
			$count_query        = $wpdb->prepare(
				"SELECT COUNT(*) FROM $wpdb->usermeta WHERE meta_key LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			);
			$index_count_before = $wpdb->get_var( $count_query );

			WP_CLI::log( sprintf( 'Found %s metadata index records to remove...', number_format( $index_count_before ) ) );

			// Use removeNow() which properly cleans up all indexes and associated options
			$indexer = Indexer::getInstance();
			$indexer->removeNow();

			// Verify actual deletions
			$index_count_after = $wpdb->get_var( $count_query );
			$actual_deleted    = $index_count_before - $index_count_after;

			if ( $index_count_after > 0 ) {
				WP_CLI::warning(
					sprintf(
						'Removed %s of %s metadata index records. %s records remain (may require another pass).',
						number_format( $actual_deleted ),
						number_format( $index_count_before ),
						number_format( $index_count_after )
					)
				);
			} else {
				WP_CLI::success(
					sprintf(
						'All user metadata indexes have been removed (%s records deleted).',
						number_format( $actual_deleted )
					)
				);
			}
		} catch ( Exception $e ) {
			WP_CLI::error( sprintf( 'Cleanup failed: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Show status of user indexes.
	 *
	 * Displays information about the current state of user indexes,
	 * including counts, completion status, and performance metrics.
	 *
	 * For multisite installations, use the --url parameter to specify the site.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Options: table, json, yaml. Default: table
	 *
	 * ## EXAMPLES
	 *
	 *     # Show status for current site
	 *     wp index-wp-users status
	 *
	 *     # Show status in JSON format
	 *     wp index-wp-users status --format=json
	 *
	 *     # Show status for a specific site in multisite
	 *     wp index-wp-users status --url=site2.example.com
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		global $wpdb;

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		// Get basic stats
		$user_count  = $wpdb->get_var( "SELECT COUNT(*) FROM $wpdb->users" );
		$max_user_id = $wpdb->get_var( "SELECT MAX(ID) FROM $wpdb->users" );
		$min_user_id = $wpdb->get_var( "SELECT MIN(ID) FROM $wpdb->users" );

		// Get index stats
		$prefix      = $wpdb->prefix . INDEX_WP_USERS_FOR_SPEED_KEY_PREFIX;
		$index_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $wpdb->usermeta WHERE meta_key LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		// Get roles
		$roles      = wp_roles();
		$role_names = $roles->get_names();
		$role_count = count( $role_names );

		// Get task status
		$indexer        = Indexer::getInstance();
		$meta_fraction  = $indexer->metaIndexRoleFraction();
		$meta_available = $indexer->isMetaIndexRoleAvailable();

		// Determine status
		if ( $meta_available ) {
			$status       = 'complete';
			$status_label = '✓ Complete';
			$status_color = '%G';
		} elseif ( $meta_fraction > 0 ) {
			$status       = 'in_progress';
			$status_label = sprintf( '⏳ %.1f%% Complete', $meta_fraction * 100 );
			$status_color = '%Y';
		} else {
			$status       = 'not_started';
			$status_label = '✗ Not Started';
			$status_color = '%R';
		}

		if ( $format === 'json' || $format === 'yaml' ) {
			$data = [
				'total_users'    => intval( $user_count ),
				'min_user_id'    => intval( $min_user_id ),
				'max_user_id'    => intval( $max_user_id ),
				'role_count'     => $role_count,
				'index_records'  => intval( $index_count ),
				'status'         => $status,
				'completion_pct' => round( $meta_fraction * 100, 1 ),
			];

			if ( $format === 'json' ) {
				WP_CLI::log( json_encode( $data, JSON_PRETTY_PRINT ) );
			} else {
				WP_CLI::log( mustache_render( 'status-template.mustache', $data ) );
			}
		} else {
			// Table format (default)
			WP_CLI::log( WP_CLI::colorize( '%GUser Index Status%n' ) );
			WP_CLI::log( str_repeat( '=', 60 ) );
			WP_CLI::log( sprintf( '%-30s %s', 'Total Users:', number_format( $user_count ) ) );
			WP_CLI::log( sprintf( '%-30s %s', 'User ID Range:', number_format( $min_user_id ) . ' to ' . number_format( $max_user_id ) ) );
			WP_CLI::log( sprintf( '%-30s %d', 'Number of Roles:', $role_count ) );
			WP_CLI::log( sprintf( '%-30s %s', 'Index Records:', number_format( $index_count ) ) );
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( '%-30s %s', 'Role Index Status:', WP_CLI::colorize( $status_color . $status_label . '%n' ) ) );

			if ( $index_count > 0 && $user_count > 0 ) {
				$expected_records = $user_count * $role_count;
				$coverage_pct     = ( $index_count / $expected_records ) * 100;
				WP_CLI::log( sprintf( '%-30s %.1f%%', 'Index Coverage:', min( 100, $coverage_pct ) ) );
			}
		}
	}

	/**
	 * In-memory cleanup to prevent memory issues during bulk operations.
	 *
	 * This function is based on VIP's memory management best practices.
	 * It clears the local object cache and database query log to prevent
	 * memory exhaustion during long-running operations.
	 *
	 * References:
	 * - https://github.com/Automattic/vip-go-mu-plugins/blob/HEAD/vip-helpers/vip-wp-cli.php
	 * - https://github.com/Automattic/vip-go-mu-plugins/blob/develop/vip-helpers/vip-caching.php
	 *
	 * @return void
	 */
	private function in_memory_cleanup() {
		global $wpdb, $wp_object_cache;

		// Reset database query log to free memory
		if ( isset( $wpdb->queries ) && is_array( $wpdb->queries ) ) {
			$wpdb->queries = [];
		}

		// Reset local object cache
		// This clears the in-memory cache without affecting Memcached or Redis
		if ( is_object( $wp_object_cache ) ) {
			// Clear the local cache array
			if ( isset( $wp_object_cache->cache ) ) {
				$wp_object_cache->cache = [];
			}

			// Clear group cache if it exists
			if ( isset( $wp_object_cache->group_cache ) ) {
				$wp_object_cache->group_cache = [];
			}

			// Clear stats if they exist
			if ( isset( $wp_object_cache->stats ) ) {
				$wp_object_cache->stats = [
					'get'    => 0,
					'add'    => 0,
					'delete' => 0,
				];
			}

			// Clear any non-persistent group caches
			if ( isset( $wp_object_cache->non_persistent_groups ) && is_array( $wp_object_cache->non_persistent_groups ) ) {
				foreach ( $wp_object_cache->non_persistent_groups as $group ) {
					if ( isset( $wp_object_cache->cache[ $group ] ) ) {
						$wp_object_cache->cache[ $group ] = [];
					}
				}
			}
		}

		// Clean term cache to prevent memory bloat
		$this->clean_term_cache();

		// If wp_cache_flush_runtime exists (VIP-specific), use it
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}

		// Force garbage collection to reclaim memory
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}
	}

	/**
	 * Clean term cache to prevent memory bloat.
	 *
	 * Based on VIP's wpcom_vip_clean_term_cache() function.
	 * Removes terms from the local cache while preserving persistent cache.
	 * Only clears common taxonomies to avoid expensive iteration.
	 *
	 * @return void
	 */
	private function clean_term_cache() {
		global $wp_object_cache;

		if ( ! is_object( $wp_object_cache ) || ! isset( $wp_object_cache->cache ) ) {
			return;
		}

		// Get the cache key prefix for terms
		$cache_group = 'terms';

		// Clear the terms group from local cache
		if ( isset( $wp_object_cache->cache[ $cache_group ] ) ) {
			$wp_object_cache->cache[ $cache_group ] = [];
		}

		// Clear common taxonomy-specific caches without iterating over all taxonomies
		// This avoids the performance issue of calling get_taxonomies() which can be slow
		$common_taxonomies = [ 'category', 'post_tag', 'nav_menu', 'link_category', 'post_format' ];
		foreach ( $common_taxonomies as $taxonomy ) {
			if ( isset( $wp_object_cache->cache[ $taxonomy ] ) ) {
				$wp_object_cache->cache[ $taxonomy ] = [];
			}
		}
	}

	/**
	 * Format elapsed time in human-readable format.
	 *
	 * @param int $seconds Number of seconds.
	 * @return string Formatted time string.
	 */
	private function format_elapsed_time( $seconds ) {
		if ( $seconds < 60 ) {
			return sprintf( '%d seconds', $seconds );
		} elseif ( $seconds < 3600 ) {
			$minutes = floor( $seconds / 60 );
			$secs    = $seconds % 60;
			return sprintf( '%d min %d sec', $minutes, $secs );
		} else {
			$hours   = floor( $seconds / 3600 );
			$minutes = floor( ( $seconds % 3600 ) / 60 );
			return sprintf( '%d hours %d min', $hours, $minutes );
		}
	}
}

// Register WP-CLI commands
WP_CLI::add_command( 'index-wp-users', __NAMESPACE__ . '\CLI_Commands' );
