<?php
/**
 * Warmup runner (Phase J) — reuses the EXISTING queue, epoch-guarded.
 *
 * Guarantees:
 *  - NO second queue: every URL becomes a `preload_url` job on the plugin's
 *    one-and-only QueueManager (its own SSRF guard re-validates at dispatch).
 *  - EPOCH GUARD: the run captures the settings snapshot hash at start; if
 *    settings change mid-run the remaining plan is aborted and the state
 *    records `aborted` — stale warmups never finish half-applied.
 *  - BUDGET: hard cap on enqueues per run (bounded work, bounded time).
 *  - DEDUP: each URL is enqueued at most once per run.
 *  - BOUNDED OBSERVABILITY: results land in a fixed-schema JSON state file
 *    (atomic temp+rename). The file carries COUNTS ONLY — no URL list, no
 *    cardinality growth, no credentials, no paths beyond the cache-relative
 *    state file location that already exists.
 *
 * @package UltimatePerformance\Warmup
 */

namespace UltimatePerformance\Warmup;

use UltimatePerformance\Core\Settings;
use UltimatePerformance\Queue\QueueManager;

defined( 'ABSPATH' ) || exit;

final class Runner {

        /** @var string|null test override root */
        private $root;

        /** @var callable|null epoch source override (test seam; default = settings snapshot) */
        private $epoch_provider;

        /**
         * @param string|null   $root  Cache root override (tests).
         * @param callable|null $epoch_provider Epoch source override (tests).
         */
        public function __construct( $root = null, $epoch_provider = null ) {
                $this->root           = $root;
                $this->epoch_provider = $epoch_provider;
        }

        /**
         * Plan + enqueue a warmup run.
         *
         * @param int|null $budget Override budget (null = planner default).
         * @return array{status:string,total:int,enqueued:int,skipped_dup:int,skipped_foreign:int,aborted_at:int,epoch:string}
         */
        public function run( $budget = null ) {
                $epoch = $this->current_epoch();
                $state = new State( $this->root );

                $planner = new Planner( $this->root );
                $plan    = $planner->plan( $budget );

                $enqueued    = 0;
                $aborted_at  = 0;
                $status      = 'completed';
                $queue       = QueueManager::instance();

                foreach ( $plan['urls'] as $i => $url ) {
                        // EPOCH GUARD: settings changed mid-run → abort the remainder.
                        if ( $this->current_epoch() !== $epoch ) {
                                $status     = 'aborted';
                                $aborted_at = $i;
                                break;
                        }
                        $ok = $queue->enqueue( 'preload_url', array( 'url' => $url ) );
                        if ( $ok ) {
                                ++$enqueued;
                        }
                }

                $report = array(
                        'status'          => $status,
                        'total'           => count( $plan['urls'] ),
                        'enqueued'        => $enqueued,
                        'skipped_dup'     => (int) $plan['skipped_dup'],
                        'skipped_foreign' => (int) $plan['skipped_foreign'],
                        'capped'          => (bool) $plan['capped'],
                        'aborted_at'      => $aborted_at,
                        'epoch'           => $epoch,
                );
                $state->write( $report );
                return $report;
        }

        /**
         * Settings snapshot hash. Any settings mutation mid-run changes this hash
         * and aborts the remaining plan (the plan may name URLs that a changed
         * configuration would classify differently — never finish stale).
         *
         * @return string
         */
        private function current_epoch() {
                if ( null !== $this->epoch_provider ) {
                        return (string) call_user_func( $this->epoch_provider );
                }
                try {
                        $raw = Settings::instance()->raw();
                } catch ( \Throwable $e ) {
                        $raw = array();
                }
                return substr( sha1( (string) wp_json_encode( $raw ) ), 0, 16 );
        }
}
