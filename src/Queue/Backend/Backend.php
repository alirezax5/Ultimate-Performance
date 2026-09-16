<?php
/**
 * Queue job value object + backend contract.
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Queue\Backend;

defined( 'ABSPATH' ) || exit;

/**
 * Job value object.
 */
final class Job {

	/** @var string */
	public $id;

	/** @var string */
	public $type;

	/** @var array<string,mixed> */
	public $payload;

	/** @var int attempts so far */
	public $attempts = 0;

	/** @var int max attempts before discard */
	public $max_attempts = 3;

	public function __construct( $type, $payload, $id = '' ) {
		$this->id      = '' !== $id ? (string) $id : \UltimatePerformance\Core\Uuid7::generate();
		$this->type    = (string) $type;
		$this->payload = (array) $payload;
	}
}

/**
 * Contract every queue backend implements. available() must be cheap,
 * timeout-bounded, and never throw.
 */
interface Backend {

	/**
	 * Human name for diagnostics.
	 *
	 * @return string
	 */
	public function name();

	/**
	 * Capability test — cached per instance.
	 *
	 * @return bool
	 */
	public function available();

	/**
	 * Whether claim()/complete() loop is supported locally.
	 *
	 * @return bool
	 */
	public function supports_worker();

	/**
	 * Push a job. Throws only on hard failure (manager falls back).
	 *
	 * @param Job $job
	 * @return string job id
	 */
	public function enqueue( Job $job );

	/**
	 * Claim next runnable job or null.
	 *
	 * @return Job|null
	 */
	public function claim();

	/**
	 * Mark job done; re-queue with backoff on failure up to max_attempts.
	 *
	 * @param Job  $job
	 * @param bool $success
	 */
	public function complete( Job $job, $success );
}
