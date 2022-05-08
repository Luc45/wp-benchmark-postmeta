<?php

/*
 * Plugin name: Benchmark Postmeta
 */

declare( ticks=1 );

if ( ! class_exists( WP_CLI::class ) ) {
	return;
}

\WP_CLI::add_command( 'benchmark', BenchmarkCommand::class, BenchmarkCommand::registration_args() );

class BenchmarkCommand {
	/**
	 * @return string[]
	 */
	public static function registration_args() {
		return [
			'shortdesc' => 'Run Benchmark.',
		];
	}

	/**
	 * Runs a benchmark
	 *
	 * ## OPTIONS
	 * [--post-mode=<mode>]
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - 100_posts
	 *   - 1k_posts
	 *   - 10k_posts
	 *   - 1M_posts
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp benchmark run
	 *
	 * @param array<string> $args
	 * @param array<string> $assoc_args
	 */
	public function run( array $args = [], array $assoc_args = [] ): void {
		if ( empty( $assoc_args['post-mode'] ) ) {
			\WP_CLI::error( 'Please pass --post-mode as an argument.' );
		}

		define( 'SAVEQUERIES', true );

		$post_count = [
			'100_posts'  => 100,
			'1k_posts'   => 1000,
			'10k_posts'  => 10000,
			'100k_posts' => 100000,
		];

		$post_modes = $assoc_args['post-mode'] === 'all' ? [
			'100_posts',
			'1k_posts',
			'10k_posts',
		] : [ $assoc_args['post-mode'] ];

		$benchmark = [];

		$time_limit = 4 * HOUR_IN_SECONDS;

		$elapsed_time_reference = microtime( true );

		$ary = [];

		// How many postmeta to add to each post
		$postmeta_min = $assoc_args['postmeta-min'] ?? 0;
		$postmeta_max   = $assoc_args['postmeta-max'] ?? 10;

		for ( $meta_count = $postmeta_min; $meta_count <= $postmeta_max; $meta_count ++ ) {
			$benchmark = array_merge( $benchmark, $this->execute_benchmark( $meta_count, $post_modes, $post_count, $elapsed_time_reference, $time_limit ) );
		}

		$this->generate_html( $benchmark );

		exit;
	}

	protected function execute_benchmark( $meta_count, $post_modes, $post_count, $elapsed_time_reference, $time_limit ): array {
		global $wpdb;

		if ( ! $wpdb->check_connection() ) {
			WP_CLI::error( 'DB Connection down...' );
		}

		if ( ! empty( $wpdb->last_error ) ) {
			WP_CLI::error( $wpdb->last_error );
		}

		\WP_CLI::log( "Starting benchmark with $meta_count metas..." );

		$meta_k = "{$meta_count}_metas";

		$generate_random_array = static function () use ( $meta_count ) {
			return array_map( static function () {
				return wp_generate_uuid4();
			}, array_fill( 0, $meta_count, null ) );
		};

		$benchmark            = [];
		$benchmark[ $meta_k ] = [];

		foreach ( $post_modes as $post_m ) {
			$this->delete_all_benchmark();

			$meta_input = array_combine( $generate_random_array(), $generate_random_array() );

			$benchmark[ $meta_k ][ $post_m ] = [];

			wp_cache_flush();

			/*
			 * wp_insert_post
			 */
			$start = microtime( true );

			$progress = \WP_CLI\Utils\make_progress_bar( "Inserting {$post_count[ $post_m ]} benchmark entries...", $post_count[ $post_m ] );
			for ( $i = 0; $i < $post_count[ $post_m ]; $i ++ ) {
				$post_id = wp_insert_post( [
					'post_title' => wp_generate_uuid4(),
					'post_type'  => 'benchmark',
					'meta_input' => $meta_input,
				] );

				if ( $post_id === 0 ) {
					global $wpdb;
					\WP_CLI::error( sprintf( 'Failed to insert post. Last query: %s Last query error: %s', $wpdb->last_query, $wpdb->last_error ) );
				}

				if ( is_wp_error( $post_id ) ) {
					\WP_CLI::error( $post_id->get_error_message() );
				}

				$progress->tick();
			}

			$progress->finish();

			$benchmark[ $meta_k ][ $post_m ]['wp_insert_post'] = microtime( true ) - $start;

			wp_cache_flush();

			/*
			 * get_posts
			 */
			$start = microtime( true );

			// We will search for a post that does not exist with newly-generated meta queries, so that it searches everywhere.
			$meta_query = array_map( function ( $k, $v ) {
				return [
					'key'   => $k,
					'value' => $v,
				];
			}, array_keys( $meta_input ), $generate_random_array() );

			if ( ! empty( $meta_query ) ) {
				$meta_query['relationship'] = 'OR';
			}

			$args = [
				'posts_per_page'   => 1,
				'post_type'        => 'benchmark',
				'post_status'      => 'draft',
				'meta_query'       => $meta_query,
				'cache_results'    => false,
				'suppress_filters' => true,
				'fields'           => 'ids',
			];

			\WP_CLI::log( "Fetching 1 benchmark entry with args: " . wp_json_encode( $args, JSON_PRETTY_PRINT ) );

			$posts = get_posts( $args );

			global $wpdb;

			\WP_CLI::log( 'Last query: ' . wp_json_encode( $wpdb->last_query, JSON_PRETTY_PRINT ) );
			\WP_CLI::log( 'Last error: ' . $wpdb->last_error );
			\WP_CLI::log( 'Fetched posts: ' . count( $posts ) );

			$expected_posts_found = empty( $meta_query ) ? 1 : 0;

			if ( count( $posts ) !== $expected_posts_found ) {
				WP_CLI::error( "Fetched posts is not $expected_posts_found." );
			}

			$benchmark[ $meta_k ][ $post_m ]['get_posts'] = microtime( true ) - $start;

			$elapsed_time = microtime( true ) - $elapsed_time_reference;

			if ( $elapsed_time > $time_limit ) {
				\WP_CLI::warning( "Bailing benchmark as it is taking longer than the time limit." );
				goto finish;
			}
		}
		finish:

		return $benchmark;
	}

	protected function delete_all_benchmark() {
		global $wpdb;

		$count_posts = static function () use ( $wpdb ) {
			return $wpdb->get_var( "SELECT COUNT(*) from `$wpdb->posts`" );
		};

		$count_postmeta = static function () use ( $wpdb ) {
			return $wpdb->get_var( "SELECT COUNT(*) from `$wpdb->postmeta`" );
		};

		\WP_CLI::log( sprintf( "Deleting posts (found: %d) and postmeta (found: %d)...", $count_posts(), $count_postmeta() ) );

		$wpdb->query( "TRUNCATE TABLE {$wpdb->posts}" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->postmeta}" );
		$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE ('_transient_%')" );
		$wpdb->query( "DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE ('_site_transient_%')" );

		sleep( 1 );

		\WP_CLI::log( sprintf( "Posts (found after delete: %d). Postmeta (found after delete: %d)...", $count_posts(), $count_postmeta() ) );
	}

	protected function generate_html( array $benchmark ) {
		$dir  = trailingslashit( WP_CONTENT_DIR ) . 'benchmark';
		$url  = trailingslashit( content_url() ) . 'benchmark';
		$file = wp_generate_uuid4() . '.html';

		wp_mkdir_p( $dir );

		$insert_chart_labels = [];
		$insert_chart_data   = [];

		$get_posts_chart_labels = [];
		$get_posts_chart_data   = [];

		foreach ( $benchmark as $meta_label => $post_modes ) {
			foreach ( $post_modes as $post_count => $operation ) {
				foreach ( $operation as $op => $time ) {
					switch ( $op ) {
						case 'wp_insert_post':
							$insert_chart_labels[] = "{$meta_label}_{$post_count}";
							$insert_chart_data[]   = $time;
							break;
						case 'get_posts':
							$get_posts_chart_labels[] = "{$meta_label}_1_post";
							$get_posts_chart_data[]   = $time;
							break;
						default:
							\WP_CLI::error( 'Invalid operation: ' . wp_json_encode( $op ) );
					}

				}
			}
		}

		$insert_chart_labels = json_encode( $insert_chart_labels );
		$insert_chart_data   = json_encode( $insert_chart_data );

		$get_posts_chart_labels = json_encode( $get_posts_chart_labels );
		$get_posts_chart_data   = json_encode( $get_posts_chart_data );

		$contents = <<<HTML
<html>
<head>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
</head>
<body>
<canvas id="insert_chart" width="400" height="400" style="float:left;"></canvas>
<canvas id="get_posts_chart" width="400" height="400"></canvas>
<script>
const insert_chart = new Chart(document.getElementById('insert_chart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: $insert_chart_labels,
        datasets: [{
            label: 'Time in seconds to insert',
            data: $insert_chart_data,
            backgroundColor: [
                'rgba(255, 99, 132, 0.2)',
                'rgba(54, 162, 235, 0.2)',
                'rgba(255, 206, 86, 0.2)',
                'rgba(75, 192, 192, 0.2)',
                'rgba(153, 102, 255, 0.2)',
                'rgba(255, 159, 64, 0.2)'
            ],
            borderColor: [
                'rgba(255, 99, 132, 1)',
                'rgba(54, 162, 235, 1)',
                'rgba(255, 206, 86, 1)',
                'rgba(75, 192, 192, 1)',
                'rgba(153, 102, 255, 1)',
                'rgba(255, 159, 64, 1)'
            ],
            borderWidth: 1
        }]
    },
    options: {
        responsive: false,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

const get_posts_chart = new Chart(document.getElementById('get_posts_chart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: $get_posts_chart_labels,
        datasets: [{
            label: 'Time in seconds to get 1 post',
            data: $get_posts_chart_data,
            backgroundColor: [
                'rgba(255, 99, 132, 0.2)',
                'rgba(54, 162, 235, 0.2)',
                'rgba(255, 206, 86, 0.2)',
                'rgba(75, 192, 192, 0.2)',
                'rgba(153, 102, 255, 0.2)',
                'rgba(255, 159, 64, 0.2)'
            ],
            borderColor: [
                'rgba(255, 99, 132, 1)',
                'rgba(54, 162, 235, 1)',
                'rgba(255, 206, 86, 1)',
                'rgba(75, 192, 192, 1)',
                'rgba(153, 102, 255, 1)',
                'rgba(255, 159, 64, 1)'
            ],
            borderWidth: 1
        }]
    },
    options: {
        responsive: false,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
</script>
</body>
</html>
HTML;

		file_put_contents( trailingslashit( $dir ) . $file, $contents );

		\WP_CLI::success( "To see the benchmark: " . trailingslashit( $url ) . $file );
	}
}
