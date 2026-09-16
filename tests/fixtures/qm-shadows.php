<?php
/**
 * QueueManager test doubles — behavior-driven backend shadows (Phase H).
 *
 * These classes SHADOW the real transport backends inside the production
 * namespace so QueueManager's own chain-building code
 * (UltimatePerformance\Queue\BackendImpl\{Studly} + instanceof Backend +
 * available()/enqueue()/receipt handling) is exercised UNMODIFIED. The
 * shadows are declared before the autoloader ever fires for these names,
 * so the real transport files are never loaded in this process.
 *
 * Behavior control:
 *
 *   $GLOBALS['UCQ_RMQ_MODE'], ['UCQ_AS_MODE'], ['UCQ_WPCRON_MODE']
 *       Static mode: 'dead'|'ok'|'throw'|'zero'|'empty'|'int0'|'false'|'null'.
 *       'dead' → available()=false. All other modes manifest in enqueue().
 *
 *   $GLOBALS['UCQ_SEQ'][<backend>] = list of modes, one per ENQUEUE ATTEMPT,
 *       consumed front-to-back (per-backend independent queues). While a
 *       list is non-empty its HEAD also drives available(). When exhausted
 *       the static MODE global applies again.
 *
 *   $GLOBALS['UCQ_COMPLETE_THROW'] = bool — complete() throws on failure.
 *   $GLOBALS['UCQ_CLAIMED']        = Job[] handed out by claim().
 *
 * Every enqueue attempt is recorded into $GLOBALS['UCQ_ENQUEUED'][<backend>]
 * (including failed ones) for exact call-count/set assertions.
 *
 * NOTE: requiring this file REPLACES the real backends for the whole
 * process lifetime (PHP cannot unload classes). Suites must finish all
 * real-transport tests BEFORE requiring it.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Queue\BackendImpl;

use UltimatePerformance\Queue\Backend\Backend;
use UltimatePerformance\Queue\Backend\Job;
use UltimatePerformance\Queue\EnqueueException;

defined( 'ABSPATH' ) || exit;

/*
 * The REAL production exception (declared beside QueueManager) is exercised —
 * never a fake. Load QueueManager.php explicitly so the namespaced reference
 * below resolves deterministically regardless of autoloader timing.
 */
if ( ! class_exists( '\\UltimatePerformance\\Queue\\QueueManager' ) ) {
	require_once __DIR__ . '/../../src/Queue/QueueManager.php';
}

if ( isset( $GLOBALS['UCQ_SHADOWS_LOADED'] ) ) {
	return;
}
$GLOBALS['UCQ_SHADOWS_LOADED'] = true;

if ( ! isset( $GLOBALS['UCQ_ENQUEUED'] ) ) {
	$GLOBALS['UCQ_ENQUEUED'] = array( 'rabbitmq' => array(), 'action-scheduler' => array(), 'wp-cron' => array() );
}

/**
 * Static-mode global key for a backend slug.
 *
 * @param string $backend
 * @return string
 */
function ucq_mode_key( $backend ) {
	switch ( $backend ) {
		case 'rabbitmq':
			return 'UCQ_RMQ_MODE';
		case 'action-scheduler':
			return 'UCQ_AS_MODE';
		default:
			return 'UCQ_WPCRON_MODE';
	}
}

/**
 * Current effective mode WITHOUT consuming anything (drives available()).
 *
 * @param string $backend
 * @return string
 */
function ucq_peek_mode( $backend ) {
	if ( isset( $GLOBALS['UCQ_SEQ'][ $backend ] ) && is_array( $GLOBALS['UCQ_SEQ'][ $backend ] ) && count( $GLOBALS['UCQ_SEQ'][ $backend ] ) > 0 ) {
		return (string) $GLOBALS['UCQ_SEQ'][ $backend ][0];
	}
	return (string) ( $GLOBALS[ ucq_mode_key( $backend ) ] ?? 'dead' );
}

/**
 * Consume the mode for THIS enqueue attempt.
 *
 * @param string $backend
 * @return string
 */
function ucq_take_mode( $backend ) {
	if ( isset( $GLOBALS['UCQ_SEQ'][ $backend ] ) && is_array( $GLOBALS['UCQ_SEQ'][ $backend ] ) && count( $GLOBALS['UCQ_SEQ'][ $backend ] ) > 0 ) {
		return (string) array_shift( $GLOBALS['UCQ_SEQ'][ $backend ] );
	}
	return ucq_peek_mode( $backend );
}

/**
 * Produce the receipt implied by a mode.
 *
 * @param string $mode
 * @return mixed Receipt value ('throw'/'dead' throw instead).
 */
function ucq_receipt_for( $mode ) {
	switch ( $mode ) {
		case 'ok':
			return 'ucq-ok-' . uniqid();
		case 'zero':
			return '0';
		case 'int0':
			return 0;
		case 'empty':
			return '';
		case 'false':
			return false;
		case 'null':
			return null;
		case 'throw':
			throw new EnqueueException( 'ucq injected enqueue failure' ); // phpcs:ignore
		case 'dead':
		default:
			throw new EnqueueException( 'ucq backend dead' ); // phpcs:ignore
	}
}

/**
 * Shared shadow engine.
 */
trait UcqShadowEngine {

	public function supports_worker() {
		return true;
	}

	public function available() {
		return 'dead' !== ucq_peek_mode( $this->name() );
	}

	public function enqueue( Job $job ) {
		$GLOBALS['UCQ_ENQUEUED'][ $this->name() ][] = $job;
		return ucq_receipt_for( ucq_take_mode( $this->name() ) );
	}

	public function claim() {
		if ( empty( $GLOBALS['UCQ_CLAIMED'] ) || ! is_array( $GLOBALS['UCQ_CLAIMED'] ) ) {
			return null;
		}
		return array_shift( $GLOBALS['UCQ_CLAIMED'] );
	}

	public function complete( Job $job, $success ) {
		if ( ! empty( $GLOBALS['UCQ_COMPLETE_THROW'] ) && ! $success ) {
			throw new EnqueueException( 'ucq injected completion-tracking failure' ); // phpcs:ignore
		}
	}
}

final class RabbitMQ implements Backend {
	use UcqShadowEngine;
	public function name() {
		return 'rabbitmq';
	}
}

final class ActionScheduler implements Backend {
	use UcqShadowEngine;
	public function name() {
		return 'action-scheduler';
	}
}

final class WPCron implements Backend {
	use UcqShadowEngine;
	public function name() {
		return 'wp-cron';
	}
}
