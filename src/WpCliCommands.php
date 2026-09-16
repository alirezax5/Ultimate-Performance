<?php
/**
 * WP-CLI command registration (Phase O §29-30).
 *
 * Wires the existing ClusterCli service class to the WP-CLI command surface.
 * Business logic lives in src/Cluster/ClusterCli.php — this file only does
 * the WP_CLI::add_command() plumbing.
 *
 * The class is loaded ONLY when WP_CLI is defined (i.e. running under wp-cli).
 * It is registered via the 'cli_init' hook (wp-cli's recommended pattern).
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Cli;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\WpCliCommands' ) ) {
        final class WpCliCommands {

                public static function register() {
                        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
                                return;
                        }
                        if ( ! class_exists( 'WP_CLI' ) ) {
                                return;
                        }

                        \WP_CLI::add_command( 'ultimate-performance cluster', __CLASS__ );
                }

                /**
                 * Display cluster status: node ID, epoch, checkpoint, lease, pending events.
                 *
                 * ## OPTIONS
                 *
                 * [--format=<format>]
                 * : Render output in a particular format.
                 * ---
                 * default: table
                 * options:
                 *   - table
                 *   - json
                 *   - csv
                 *   - yaml
                 * ---
                 *
                 * ## EXAMPLES
                 *
                 *     wp ultimate-performance cluster status
                 *     wp ultimate-performance cluster status --format=json
                 *
                 * @when after_wp_load
                 */
                public function status( $args, $assoc_args ) {
                        $cli = $this->build_cli();
                        $format = $assoc_args['format'] ?? 'json';
                        $json   = $cli->status();
                        $data   = json_decode( $json, true );
                        if ( 'json' === $format ) {
                                \WP_CLI::log( $json );
                        } else {
                                \WP_CLI\Utils\format_items( $format, array( $data ), array_keys( $data ) );
                        }
                }

                /**
                 * Show or bump the cluster epoch.
                 *
                 * ## OPTIONS
                 *
                 * [--bump]
                 * : Atomically bump the epoch (forces every consumer to reconcile).
                 *
                 * [--format=<format>]
                 * : Output format. Default: json.
                 *
                 * ## EXAMPLES
                 *
                 *     wp ultimate-performance cluster epoch
                 *     wp ultimate-performance cluster epoch --bump
                 *
                 * @when after_wp_load
                 */
                public function epoch( $args, $assoc_args ) {
                        $cli = $this->build_cli();
                        $bump = isset( $assoc_args['bump'] );
                        $json = $cli->epoch( $bump );
                        \WP_CLI::log( $json );
                        if ( $bump ) {
                                \WP_CLI::success( 'Epoch bumped — consumers will reconcile on next tick' );
                        }
                }

                /**
                 * List pending events for this node (bounded to 50).
                 *
                 * ## OPTIONS
                 *
                 * [--format=<format>]
                 * : Output format. Default: table.
                 *
                 * ## EXAMPLES
                 *
                 *     wp ultimate-performance cluster events
                 *     wp ultimate-performance cluster events --format=json
                 *
                 * @when after_wp_load
                 */
                public function events( $args, $assoc_args ) {
                        $cli = $this->build_cli();
                        $format = $assoc_args['format'] ?? 'table';
                        $json   = $cli->events();
                        $data   = json_decode( $json, true );
                        if ( 'json' === $format ) {
                                \WP_CLI::log( $json );
                        } elseif ( ! empty( $data['events'] ) ) {
                                \WP_CLI\Utils\format_items( $format, $data['events'], array( 'id', 'gen_epoch', 'schema', 'origin', 'scope', 'created_ms' ) );
                        } else {
                                \WP_CLI::log( "No pending events (count={$data['count']}, limit={$data['limit']})" );
                        }
                }

                /**
                 * Force a local reconcile. Idempotent. Scope = LOCAL only
                 * (NEVER silently purges every node).
                 *
                 * ## OPTIONS
                 *
                 * [--format=<format>]
                 * : Output format. Default: json.
                 *
                 * ## EXAMPLES
                 *
                 *     wp ultimate-performance cluster reconcile
                 *
                 * @when after_wp_load
                 */
                public function reconcile( $args, $assoc_args ) {
                        $cli = $this->build_cli();
                        $json = $cli->reconcile();
                        $data = json_decode( $json, true );
                        \WP_CLI::log( $json );
                        if ( isset( $data['result'] ) && 'ok' === $data['result'] ) {
                                \WP_CLI::success( "Reconciled local node — {$data['events_processed']} events processed in {$data['ms']}ms" );
                        } elseif ( isset( $data['result'] ) && 'skipped' === $data['result'] ) {
                                \WP_CLI::warning( "Reconcile skipped: {$data['reason']}" );
                        }
                }

                /**
                 * Build the ClusterCli service instance with its dependencies.
                 *
                 * @return \UltimatePerformance\Cluster\ClusterCli
                 */
                private function build_cli() {
                        $epoch      = new \UltimatePerformance\Cluster\Epoch();
                        $lease      = new \UltimatePerformance\Cluster\Lease();
                        $events     = new \UltimatePerformance\Cluster\EventStore();
                        $propagator = new \UltimatePerformance\Cluster\Propagator( $events );
                        $state      = new \UltimatePerformance\Cluster\State( \UltimatePerformance\Core\Installer::cache_root() );
                        return new \UltimatePerformance\Cluster\ClusterCli( $epoch, $lease, $events, $propagator, $state );
                }
        }
}

// Register on cli_init (wp-cli's recommended hook).
add_action( 'cli_init', array( __NAMESPACE__ . '\\WpCliCommands', 'register' ) );

// Also register immediately if we're already inside a wp-cli invocation
// (cli_init may have already fired if the plugin is loaded late).
if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
        WpCliCommands::register();
}
