<?php
/**
 * Cluster invalidation — M5 + N3 (see docs/PHASE-M-CLUSTER-INVALIDATION.md,
 * docs/PHASE-N-EPOCH-DESIGN.md §5).
 *
 * NodeIdentity: per-node uuid7 persisted INSIDE the per-node cache root
 * (meta/node-id.json). Independent cache roots ⇒ independent identities;
 * the id survives restarts. This is the event `origin`.
 *
 * N3 hardening: the file also carries a persistent random boot_secret
 * (32 hex). Together with the shared-DB lease (Cluster\Lease) and an
 * ephemeral per-process instance nonce this enables CLONE DETECTION that
 * does not depend on random probability: a cloned/restored filesystem is
 * detected by secret mismatch (post-registration clone) or by the
 * concurrent-instance probe (live duplicate), and the identity is safely
 * regenerated. regenerate() also invalidates the node-bound checkpoint
 * (a fresh identity reconciles from zero — fail-closed by construction).
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Cluster;

use UltimatePerformance\Core\Uuid7;

defined( 'ABSPATH' ) || exit;

final class NodeIdentity {

        /**
         * Canonical RFC 9562 dashed-uuid shape (36 chars INCLUDING the four
         * dashes). M5-D1: the interrupted implementation validated
         * `^[0-9a-f]{36}$`, which a dashed uuid can NEVER match — the node id
         * was silently regenerated in every process (unstable origin, broken
         * per-origin watermark dedup, unbounded watermark file). Kept as the
         * strict canonical-lowercase contract; anything else regenerates.
         */
        const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

        /** @var string|null */
        private static $cached;

        /** @var string|null ephemeral per-process instance nonce (clone probe) */
        private static $instance;

        /** @return string uuid7 node id (creates the file on first use). */
        public static function id() {
                if ( null !== self::$cached ) {
                        return self::$cached;
                }
                $file = \UltimatePerformance\Core\Installer::cache_root() . '/meta/node-id.json';
                if ( is_readable( $file ) && ! is_link( $file ) ) {
                        $meta = json_decode( (string) file_get_contents( $file ), true );
                        if ( is_array( $meta ) && preg_match( self::UUID_RE, (string) ( $meta['node_id'] ?? '' ) ) ) {
                                self::$cached = (string) $meta['node_id'];
                                return self::$cached;
                        }
                }
                return self::write_new( $file, null );
        }

        /** Persistent boot secret (32 hex), created alongside the id. */
        public static function boot_secret() {
                $file = \UltimatePerformance\Core\Installer::cache_root() . '/meta/node-id.json';
                $meta = is_readable( $file ) && ! is_link( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
                if ( is_array( $meta ) && preg_match( '/^[0-9a-f]{32}$/', (string) ( $meta['boot_secret'] ?? '' ) ) ) {
                        return (string) $meta['boot_secret'];
                }
                // Legacy M5 file (id without secret) — upgrade it in place, same id.
                $id     = is_array( $meta ) ? (string) ( $meta['node_id'] ?? '' ) : '';
                $secret = self::random_secret();
                if ( '' !== $id && preg_match( self::UUID_RE, $id ) ) {
                        self::write_file( $file, $id, $secret );
                        return $secret;
                }
                self::id(); // (re)create file with id+secret
                $meta2 = json_decode( (string) file_get_contents( $file ), true );
                return is_array( $meta2 ) ? (string) ( $meta2['boot_secret'] ?? $secret ) : $secret;
        }

        /**
         * Ephemeral per-process instance nonce for the concurrent-clone probe.
         * Memory-only: two processes sharing a cloned file still hold
         * DIFFERENT instance nonces, which is what makes the probe
         * deterministic given overlap.
         *
         * @return string
         */
        public static function instance_nonce() {
                if ( null === self::$instance ) {
                        self::$instance = self::random_secret();
                }
                return self::$instance;
        }

        /**
         * N3: safely regenerate the identity (clone detected). New uuid7 + new
         * boot secret, file rewritten, process cache reset. The node-bound
         * checkpoint file now belongs to the OLD id → reads 0 → the node
         * reconciles from zero (fail-closed by construction).
         *
         * @return string the new node id
         */
        public static function regenerate() {
                $file = \UltimatePerformance\Core\Installer::cache_root() . '/meta/node-id.json';
                return self::write_new( $file, null );
        }

        /** @internal shared writer for id() and regenerate(). */
        private static function write_new( $file, $secret ) {
                $id     = Uuid7::generate();
                $secret = null === $secret ? self::random_secret() : $secret;
                if ( ! is_dir( dirname( $file ) ) ) {
                        wp_mkdir_p( dirname( $file ) );
                }
                self::write_file( $file, $id, $secret );
                self::$cached = $id;
                return $id;
        }

        private static function write_file( $file, $id, $secret ) {
                file_put_contents(
                        $file,
                        wp_json_encode(
                                array(
                                        'node_id'     => $id,
                                        'boot_secret' => $secret,
                                        'created'     => time(),
                                )
                        ),
                        LOCK_EX
                );
                @chmod( $file, 0640 );
        }

        private static function random_secret() {
                $s = '';
                for ( $i = 0; $i < 32; $i++ ) {
                        // CSPRNG directly (no wp_rand dependency — shims and CLI safe).
                        $s .= dechex( random_int( 0, 15 ) );
                }
                return $s;
        }
}
