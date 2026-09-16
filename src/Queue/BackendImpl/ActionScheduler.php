<?php
/**
 * Action Scheduler queue backend (optional; provided by AS plugin/Woo).
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Queue\BackendImpl;

use UltimatePerformance\Queue\Backend\Backend;
use UltimatePerformance\Queue\Backend\Job;
use UltimatePerformance\Queue\EnqueueException;

defined( 'ABSPATH' ) || exit;

/**
 * EXC-1 (Phase H): this file previously DECLARED its own BackendImpl\EnqueueException.
 * Unqualified `throw new EnqueueException` in the sibling backends then resolved to
 * it only when ActionScheduler.php happened to be loaded first — load-order-
 * dependent exception resolution. All backends now import the canonical
 * UltimatePerformance\Queue\EnqueueException (autoloaded from QueueManager.php) and the
 * duplicate declaration is removed.
 */

final class ActionScheduler implements Backend {

        public function name() {
                return 'action-scheduler';
        }

        public function supports_worker() {
                return false; // AS runs its own workers on its own schedule.
        }

        public function available() {
                return function_exists( 'as_enqueue_async_action' );
        }

        /**
         * H3-2 persistence rationale: as_enqueue_async_action() returns the action
         * ID only after ActionScheduler_DBStore::save_action_to_db() has executed
         * its INSERT — a synchronous, same-request write to the durable actions
         * table. There is no fire-and-forget path inside AS: if the row is not
         * committed, no ID exists. A positive int therefore IS the persistence
         * receipt; a per-enqueue verification query would double DB writes on
         * every purge without adding correctness (the failure mode we must catch
         * — exception swallowed into return 0 — is fully covered by the strict
         * >0 check below). Regression tests assert both shapes.
         *
         * @param Job $job
         * @return string job id
         * @throws EnqueueException When AS reports scheduling failure (0/false/null/negative).
         */
        public function enqueue( Job $job ) {
                if ( ! $this->available() ) {
                        throw new EnqueueException( 'action-scheduler unavailable' );
                }
                $id = as_enqueue_async_action(
                        'ultimate_performance_as_job',
                        array(
                                'type'    => $job->type,
                                'payload' => $job->payload,
                                'id'      => substr( $job->id, 0, 64 ),
                        ),
                        'ultimate-performance'
                );
                if ( ! is_int( $id ) || $id <= 0 ) {
                        throw new EnqueueException( 'action-scheduler enqueue rejected (id=' . var_export( $id, true ) . ')' );
                }
                return (string) $id;
        }

        public function claim() {
                return null; // AS dispatches jobs itself via the hooked callback.
        }

        public function complete( Job $job, $success ) {
                // no-op: AS tracks status/history.
        }
}
