<?php
/**
 * Telemetry (Phase K) — bounded observability for the plugin's caches.
 *
 *  - FIXED schema: the snapshot keys below and nothing else — unknown keys
 *    are dropped by construction, so the stream cannot grow shapelessly,
 *  - LOW CARDINALITY: labels are enum-only (backend short names from a
 *    closed set, warmup status values from a closed set) — no URLs, no
 *    cache keys, no raw paths, no credentials can enter by construction,
 *  - two renderers: a JSON snapshot (atomic temp+rename, 0640, under the
 *    hardened HTTP-denied cache tree) and a Prometheus text exposition,
 *  - request/user identifiers are never emitted.
 *
 * @package UltimatePerformance\Core
 */

namespace UltimatePerformance\Core;

defined( 'ABSPATH' ) || exit;

final class Telemetry {

        const SCHEMA = 3;

        /** Closed label set for backend identification (nothing else may render). */
        const BACKEND_ENUM = array( 'redis', 'memcached', 'apcu', 'sqlite', 'file', 'memory', 'none' );

        /** @var string output dir under the hardened cache tree */
        private $base;

        /** @var callable|null fn(): array — object-cache stats provider (test seam) */
        private $provider;

        /**
         * @param string|null   $base     Output dir override (tests).
         * @param callable|null $provider Stats provider override (tests).
         */
        public function __construct( $base = null, $provider = null ) {
                $this->base = null === $base
                        ? ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content' ) . '/cache/ultimate-performance/meta'
                        : rtrim( (string) $base, '/' );
                $this->provider = $provider;
        }

        /**
         * Fixed-schema snapshot. Counts only.
         *
         * @return array<string,int|bool|array<int,string>>
         */
        public function snapshot() {
                $st  = $this->oc_stats();
                $row = array(
                        'schema'                  => self::SCHEMA,
                        'ts'                      => time(),
                        'oc_hits'                 => (int) ( isset( $st['hits'] ) ? $st['hits'] : 0 ),
                        'oc_misses'               => (int) ( isset( $st['misses'] ) ? $st['misses'] : 0 ),
                        'oc_fences'               => (int) ( isset( $st['fences'] ) ? $st['fences'] : 0 ),
                        'oc_backend_healthy'      => (bool) ( isset( $st['backend_healthy'] ) ? $st['backend_healthy'] : false ),
                        'oc_backends'             => $this->backend_enum( isset( $st['backend'] ) ? $st['backend'] : array() ),
                        'warmup_total'            => 0,
                        'warmup_enqueued'         => 0,
                        'warmup_skipped_dup'      => 0,
                        'warmup_skipped_foreign'  => 0,
                        'warmup_capped'           => 0,
                        'warmup_status'           => 'idle', // enum: idle|running|done|aborted
                        // M5 §6 cluster invalidation counters (counts only; no event
                        // ids, no dirs, no origins — cardinality-free by construction).
                        // N1 §6 adds the epoch/recovery counters (SCHEMA 3).
                        'cluster_events_published' => 0,
                        'cluster_events_consumed'  => 0,
                        'cluster_events_duplicates' => 0,
                        'cluster_events_failures'  => 0,
                        'cluster_events_stale'     => 0,
                        'cluster_epoch_reconciliations' => 0,
                        'cluster_epoch_authority_failures' => 0,
                        'cluster_event_gaps'       => 0,
                        'cluster_watermark_resets' => 0,
                        'cluster_node_id_collisions' => 0,
                        'cluster_lag_ms'           => 0,
                );
                // Warmup counts via the schema-locked State (Phase J); only the fixed
                // int keys are copied — never the raw state blob.
                try {
                        if ( class_exists( '\UltimatePerformance\Warmup\State' ) ) {
                                $data = ( new \UltimatePerformance\Warmup\State() )->read();
                                if ( is_array( $data ) ) {
                                        foreach ( array( 'total', 'enqueued', 'skipped_dup', 'skipped_foreign', 'capped' ) as $k ) {
                                                $row[ 'warmup_' . $k ] = (int) ( isset( $data[ $k ] ) ? $data[ $k ] : 0 );
                                        }
                                        $status = (string) ( isset( $data['status'] ) ? $data['status'] : 'idle' );
                                        $row['warmup_status'] = in_array( $status, array( 'idle', 'running', 'done', 'aborted' ), true ) ? $status : 'idle';
                                }
                        }
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                        // telemetry must never throw into the request/cron path
                }
                // M5 §6 cluster counters via the schema-locked Cluster\State; only
                // the fixed int keys are copied — never the raw state blob.
                try {
                        if ( class_exists( '\UltimatePerformance\Cluster\State' ) ) {
                                $cdata = ( new \UltimatePerformance\Cluster\State() )->read();
                                if ( is_array( $cdata ) ) {
                                        // Explicit map: Cluster\State key → snapshot key.
                                        foreach ( array(
                                                'published' => 'cluster_events_published',
                                                'consumed' => 'cluster_events_consumed',
                                                'duplicates' => 'cluster_events_duplicates',
                                                'failures' => 'cluster_events_failures',
                                                'stale' => 'cluster_events_stale',
                                                'epoch_reconciliations' => 'cluster_epoch_reconciliations',
                                                'epoch_authority_failures' => 'cluster_epoch_authority_failures',
                                                'event_gaps' => 'cluster_event_gaps',
                                                'watermark_resets' => 'cluster_watermark_resets',
                                                'node_id_collisions' => 'cluster_node_id_collisions',
                                        ) as $k => $snap_key ) {
                                                $row[ $snap_key ] = (int) ( isset( $cdata[ $k ] ) ? $cdata[ $k ] : 0 );
                                        }
                                        $row['cluster_lag_ms'] = (int) ( isset( $cdata['lag_ms'] ) ? $cdata['lag_ms'] : 0 );
                                }
                        }
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                        // telemetry must never throw into the request/cron path
                }
                return $row;
        }

        /**
         * @return array<string,mixed>
         */
        private function oc_stats() {
                try {
                        if ( null !== $this->provider ) {
                                return (array) call_user_func( $this->provider );
                        }
                        if ( class_exists( '\UltimatePerformance\ObjectCache\Manager' ) ) {
                                return (array) \UltimatePerformance\ObjectCache\Manager::instance()->stats();
                        }
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                }
                return array();
        }

        /**
         * Map backend class names onto the CLOSED enum. Anything unknown becomes
         * 'none' — a raw class name, URL, path or key can never be echoed.
         *
         * @param array<int,string> $classes
         * @return array<int,string>
         */
        private function backend_enum( $classes ) {
                $map = array(
                        'RedisBackend'     => 'redis',
                        'MemcachedBackend' => 'memcached',
                        'ApcuBackend'      => 'apcu',
                        'SqliteBackend'    => 'sqlite',
                        'FileBackend'      => 'file',
                        'MemoryBackend'    => 'memory',
                );
                $out = array();
                foreach ( (array) $classes as $c ) {
                        $enum = 'none';
                        if ( is_string( $c ) ) {
                                foreach ( $map as $suffix => $e ) {
                                        if ( '' !== $suffix && substr( $c, -strlen( $suffix ) ) === $suffix ) {
                                                $enum = $e;
                                                break;
                                        }
                                }
                        }
                        if ( ! in_array( $enum, $out, true ) ) {
                                $out[] = $enum;
                        }
                }
                return empty( $out ) ? array( 'none' ) : $out;
        }

        /**
         * Prometheus text exposition (v0.0.4). Metric names and labels are
         * fixed; the only label is backend=<enum>.
         *
         * @param array<string,mixed>|null $snap Pre-built snapshot (tests).
         * @return string
         */
        public function render_prometheus( $snap = null ) {
                $snap = null === $snap ? $this->snapshot() : $snap;
                $lines = array(
                        '# TYPE up_oc_hits_total counter',
                );
                foreach ( $snap['oc_backends'] as $b ) {
                        $lines[] = sprintf( 'up_oc_hits_total{backend="%s"} %d', $b, (int) $snap['oc_hits'] );
                }
                $lines[] = '# TYPE up_oc_misses_total counter';
                foreach ( $snap['oc_backends'] as $b ) {
                        $lines[] = sprintf( 'up_oc_misses_total{backend="%s"} %d', $b, (int) $snap['oc_misses'] );
                }
                $lines[] = '# TYPE up_oc_fences_total counter';
                $lines[] = sprintf( 'up_oc_fences_total %d', (int) $snap['oc_fences'] );
                $lines[] = '# TYPE up_oc_backend_healthy gauge';
                $lines[] = sprintf( 'up_oc_backend_healthy %d', true === $snap['oc_backend_healthy'] ? 1 : 0 );
                foreach ( array( 'total', 'enqueued', 'skipped_dup', 'skipped_foreign', 'capped' ) as $k ) {
                        $lines[] = sprintf( '# TYPE up_warmup_%s_total counter', str_replace( '_', '_', $k ) );
                        $lines[] = sprintf( 'up_warmup_%s_total %d', str_replace( '_', '_', $k ), (int) $snap[ 'warmup_' . $k ] );
                }
                $lines[] = '# TYPE up_warmup_status gauge';
                $status_codes = array( 'idle' => 0, 'running' => 1, 'done' => 2, 'aborted' => 3 );
                $lines[]      = sprintf( 'up_warmup_status %d', $status_codes[ $snap['warmup_status'] ] );
                // M5 §6 + N1 §6: cluster invalidation counters (fixed names, no labels).
                foreach ( array(
                        'published' => 'cluster_events_published',
                        'consumed' => 'cluster_events_consumed',
                        'duplicates' => 'cluster_events_duplicates',
                        'failures' => 'cluster_events_failures',
                        'stale' => 'cluster_events_stale',
                        'epoch_reconciliations' => 'cluster_epoch_reconciliations',
                        'epoch_authority_failures' => 'cluster_epoch_authority_failures',
                        'event_gaps' => 'cluster_event_gaps',
                        'watermark_resets' => 'cluster_watermark_resets',
                        'node_id_collisions' => 'cluster_node_id_collisions',
                ) as $k => $snap_key ) {
                        $lines[] = '# TYPE up_' . $snap_key . '_total counter';
                        $lines[] = sprintf( 'uc_%s_total %d', $snap_key, (int) $snap[ $snap_key ] );
                }
                $lines[] = '# TYPE up_cluster_lag_ms gauge';
                $lines[] = sprintf( 'up_cluster_lag_ms %d', (int) $snap['cluster_lag_ms'] );
                return implode( "\n", $lines ) . "\n";
        }

        /**
         * Atomic JSON snapshot write (temp + rename, 0640) under the hardened
         * cache tree's meta dir.
         *
         * @return bool
         */
        public function write_snapshot() {
                if ( ! is_dir( $this->base ) ) {
                        @mkdir( $this->base, 0775, true );
                }
                $json  = (string) wp_json_encode( $this->snapshot() );
                $tmp   = $this->base . '/.telemetry.tmp-' . getmypid();
                $final = $this->base . '/telemetry.json';
                if ( false === @file_put_contents( $tmp, $json, LOCK_EX ) ) {
                        return false;
                }
                @chmod( $tmp, 0640 );
                if ( ! @rename( $tmp, $final ) ) {
                        @unlink( $tmp );
                        return false;
                }
                return is_file( $final );
        }
}
