<?php
/**
 * Cluster invalidation — M5 + N1. Propagator (producer side) + Consumer
 * (consumer side). Local-first: the deciding node purges locally BEFORE
 * publishing; consumers execute the same validated purge on their own tree.
 *
 * N1 (docs/PHASE-N-EPOCH-DESIGN.md): every invalidation operation bumps a
 * DURABLE shared purge-generation authority (Cluster\Epoch) BEFORE the
 * local purge runs (producer boundary §4.7 — the bump-then-purge-then-
 * publish order turns a lost publish into a recoverable gap instead of
 * TTL-bounded staleness). Consumers track a per-node checkpoint of
 * PROVEN coverage; any unaccounted generation (mid-batch gap, lost tail,
 * authority outage/reset) downgrades to a local reconcile —
 * over-invalidation is safe, under-invalidation never happens (I10).
 *
 * Anti-thundering-herd: option (c) origin-only warmup — consumers do NOT
 * schedule warmup; they repopulate lazily on first MISS (M5 design §5).
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Cluster;

use UltimatePerformance\CacheTag\Registry;
use UltimatePerformance\Core\Installer;
use UltimatePerformance\Core\SafeFs;

defined( 'ABSPATH' ) || exit;

final class Propagator {

        /**
         * N2: max encoded payload per event, comfortably under the shared
         * table's TEXT column (64 KB on MySQL/MariaDB). Filterable via
         * `ultimate_cache_cluster_payload_max` (hard bounds 1024..65000).
         */
        const PAYLOAD_MAX = 60000;

        /** @var EventStore */
        private $store;

        /** @var Epoch N1: shared purge-generation authority */
        private $authority;

        /** @var Checkpoint N1: per-node proven-coverage floor */
        private $checkpoint;

        /** @var int|null Generation remembered by the before-purge bump (per operation). */
        private $pending_gen;

        public function __construct( EventStore $store, ?Epoch $authority = null, ?Checkpoint $checkpoint = null ) {
                $this->store      = $store;
                $this->authority  = $authority ?: new Epoch();
                $this->checkpoint = $checkpoint ?: new Checkpoint();
                $this->pending_gen = null;
        }

        /** Register the producer-side listeners (called from late_boot). */
        public function register() {
                add_action( 'ultimate_performance_before_purge_tags', array( $this, 'on_before_purge_tags' ), 10, 2 );
                add_action( 'ultimate_performance_after_purge_tags', array( $this, 'on_purge_tags' ), 10, 3 );
                add_action( 'ultimate_performance_before_purge_all', array( $this, 'on_before_purge_all' ), 10, 0 );
                add_action( 'ultimate_performance_after_purge_all', array( $this, 'on_purge_all' ), 10, 0 );
                add_action( 'ultimate_performance_tick', array( $this, 'consume' ), 20, 0 );
        }

        /**
         * Producer boundary step 3 (design §4.7): bump the shared authority
         * BEFORE the local purge executes. A crash anywhere between here and
         * the publish leaves a RECOVERABLE gap (generation without event →
         * consumers reconcile) instead of a silent TTL-staleness window.
         *
         * @param array<int,string>   $tags
         * @param array<int,string>   $dirs the decided workload
         */
        public function on_before_purge_tags( $tags, $dirs ) {
                if ( empty( $dirs ) ) {
                        return; // mirrors the after-hook early return — no operation, no bump
                }
                $this->pending_gen = $this->authority->bump(); // 0 on authority failure → v1-like row
        }

        /** Producer boundary step 3 for the purge_all scope. */
        public function on_before_purge_all() {
                $this->pending_gen = $this->authority->bump();
        }

        /**
         * Producer: publish one purge_dirs event for the cluster. Local purge
         * already ran / is running. The DECIDED dirs arrive as the third
         * argument (M5-D5 — authoritative; re-deriving them from the registry
         * here races with the local purge, which detaches them). The registry
         * derivation is kept ONLY as a fallback for callers of the action that
         * predate the third argument (unit suite covers both paths).
         *
         * @param array<int,string> $tags
         * @param int               $count dirs count (ignored)
         * @param array<int,string>|null $dirs the decided workload (authoritative)
         */
        public function on_purge_tags( $tags, $count = 0, $dirs = null ) {
                if ( ! is_array( $dirs ) || empty( $dirs ) ) {
                        // Fallback path (M5-D2 note: keys of the dedup map — the
                        // interrupted implementation published array_values(), booleans).
                        $dirs = array();
                        $registry = new Registry( new SafeFs() );
                        foreach ( (array) $tags as $tag ) {
                                foreach ( $registry->members( (string) $tag ) as $rel_dir ) {
                                        if ( '' !== (string) $rel_dir ) {
                                                $dirs[ (string) $rel_dir ] = true;
                                        }
                                }
                        }
                        $dirs = array_keys( $dirs );
                }
                // Normalize: keep only non-empty strings, dedup, preserve order.
                $clean = array();
                foreach ( (array) $dirs as $rel ) {
                        if ( '' !== (string) $rel ) {
                                $clean[ (string) $rel ] = true;
                        }
                }
                if ( empty( $clean ) ) {
                        $this->pending_gen = null;
                        return;
                }
                // N1: the generation remembered by the before-bump is authoritative;
                // legacy callers that fire the after-hook without the before-hook
                // fall back to bumping here (still correct — a gap at worst).
                $gen = ( null !== $this->pending_gen ) ? $this->pending_gen : $this->authority->bump();
                $this->pending_gen = null;
                // N2 durability: a single event whose payload exceeds the shared
                // table's TEXT column would FAIL to insert (lost targeted signal)
                // — the dir list is CHUNKED into size-bounded events, each with
                // its own generation (contiguity preserved; a crash between
                // chunks leaves a recoverable gap, never silent staleness).
                $this->publish_dirs_chunked( array_keys( $clean ), (array) $tags, $gen );
        }

        /**
         * N2: publish one purge_dirs operation as one-or-more size-bounded
         * events. The first chunk reuses the operation's remembered
         * generation; every additional chunk bumps its own (unique,
         * monotonic — the consumer's contiguity algorithm is preserved).
         * Each published chunk claims its own coverage; a crash after chunk
         * k leaves chunks k+1..n unclaimed → the floor reconciles (safe).
         *
         * @param array<int,string> $dirs
         * @param array<int,string> $tags
         * @param int               $first_gen the operation's remembered generation (0 = legacy fallback → bump per chunk)
         */
        private function publish_dirs_chunked( $dirs, $tags, $first_gen ) {
                $max = (int) apply_filters( 'ultimate_cache_cluster_payload_max', self::PAYLOAD_MAX );
                $max = max( 1024, min( 65000, $max ) ); // hard bounds: under the MySQL TEXT column, never below a sane floor
                $tags_json = (string) wp_json_encode( array_values( $tags ) );
                $fragments = array();
                foreach ( (array) $dirs as $rel ) {
                        // Measure each dir's encoded fragment (wp_json_encode of a
                        // 1-element array, minus the 2 bracket chars) — escaping
                        // included, so the packed length estimate is exact.
                        $fragments[] = strlen( (string) wp_json_encode( array( (string) $rel ) ) ) - 2;
                }
                $base_len = strlen( '{"dirs":[],"tags":' . $tags_json . '}' );
                $chunks   = array();
                $cur      = array();
                $cur_len  = $base_len;
                foreach ( $fragments as $i => $flen ) {
                        $add = $flen + ( empty( $cur ) ? 0 : 1 ); // comma between fragments
                        if ( ! empty( $cur ) && $cur_len + $add > $max ) {
                                $chunks[] = $cur;
                                $cur      = array();
                                $cur_len  = $base_len;
                                $add      = $flen;
                        }
                        $cur[]    = $dirs[ $i ];
                        $cur_len += $add;
                }
                if ( ! empty( $cur ) ) {
                        $chunks[] = $cur;
                }
                $gen = $first_gen;
                foreach ( $chunks as $chunk_dirs ) {
                        if ( $gen < 1 ) {
                                $gen = $this->authority->bump(); // legacy fallback or post-chunk bumps
                        }
                        $event_id = $this->store->publish( 'purge_dirs', array( 'dirs' => array_values( $chunk_dirs ), 'tags' => $tags ), $this->current_epoch(), $gen );
                        $this->claim_own_generation( $event_id, $gen );
                        $gen = 0; // force a fresh bump for the NEXT chunk
                }
        }

        /** Producer: cluster-wide full flush. */
        public function on_purge_all() {
                $gen = ( null !== $this->pending_gen ) ? $this->pending_gen : $this->authority->bump();
                $this->pending_gen = null;
                $event_id = $this->store->publish( 'purge_all', array(), $this->current_epoch(), $gen );
                $this->claim_own_generation( $event_id, $gen );
        }

        /**
         * N1: the producer CLAIMS coverage of its own generation at publish.
         * Local-first means the local purge already ran (sync queue) or is
         * durably queued on this node (async backends) — either way this node
         * does not need the checkpoint floor to re-cover its own generation,
         * and claiming prevents a FAKE lost-tail reconcile on the producer's
         * next tick (its own rows are excluded from its own consumption).
         * Crash windows stay recoverable: a crash after the bump but before
         * this claim leaves the generation unclaimed → the floor reconciles.
         *
         * @param string $event_id publish result ('' on failure — no claim)
         * @param int    $gen      the generation published
         */
        private function claim_own_generation( $event_id, $gen ) {
                if ( '' === (string) $event_id || $gen < 1 ) {
                        return; // publish failed → no coverage may be claimed (fail-closed)
                }
                $my = NodeIdentity::id();
                $C  = $this->checkpoint->read( $my );
                if ( $gen > $C ) {
                        $this->checkpoint->write( $gen, $my );
                }
        }

        /**
         * N1: reconcile floor — purge THIS node's whole page-cache tree and
         * rebase the checkpoint to the best-known shared epoch. Always safe
         * (over-invalidation), triggered only by proven-or-suspected coverage
         * gaps. Idempotent; local-only (no cross-node coordination, no warmup).
         *
         * Reconcile reasons (bounded enum): gap | lost-tail | authority-outage |
         * authority-reset | manual.
         *
         * @param string $reason
         * @return int the checkpoint value written (best-known coverage)
         */
        public function reconcile( $reason = 'manual' ) {
                $my = NodeIdentity::id();
                ( new SafeFs() )->delete_tree( Installer::cache_root() . '/v' );
                $S = null;
                if ( $this->authority->available() ) {
                        $S = $this->authority->current();
                }
                if ( null === $S ) {
                        // Authority unavailable: claim coverage only up to the last value
                        // we actually OBSERVED (bumps are impossible while it is down).
                        $S = $this->checkpoint->observed();
                }
                $this->checkpoint->write( $S, $my );
                $this->metric_bump( array( 'epoch_reconciliations' => 1 ) );
                return $S;
        }

        /**
         * Consumer (N1 algorithm, design §4.5): bounded batch of pending events
         * from OTHER nodes; every payload fully validated consumer-side (dirs
         * re-sanitized via Key::segment — the producer is never trusted);
         * deletions run synchronously on this node's own tree.
         *
         * v1 rows (Phase M / gen 0): targeted execution, M5 semantics, no
         * checkpoint involvement.
         * v2 rows: processed in GEN order; contiguity from checkpoint+1 proves
         * targeted coverage; the first discontinuity reconciles; the
         * end-of-round discriminator separates backlog from lost tail.
         *
         * Idempotent via mark_consumed + per-origin watermark. Bounded: batch
         * cap + janitor pruning happen here.
         */
        public function consume() {
                $store = $this->store;
                $my    = NodeIdentity::id();
                // N3: clone detection BEFORE any consumption decision. A cloned or
                // restored node regenerates its identity here; the node-bound
                // checkpoint becomes invalid (reads 0) and the node reconciles from
                // zero — fail-closed by construction.
                try {
                        $lease = new Lease();
                        if ( ! $lease->verify( $my, NodeIdentity::boot_secret(), NodeIdentity::instance_nonce(), (int) round( microtime( true ) * 1000 ) ) ) {
                                $my = NodeIdentity::regenerate(); // new id; old checkpoint now foreign → 0
                                $this->metric_bump( array( 'node_id_collisions' => 1 ) );
                        }
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                        // identity verification must never break consumption
                }
                $batch = (int) apply_filters( 'ultimate_cache_cluster_batch', EventStore::BATCH_CAP );
                $events = $store->pending_for( $my, $batch );

                $wm_file = Installer::cache_root() . '/meta/cluster-watermark.json';
                $wm      = array();
                if ( is_readable( $wm_file ) ) {
                        $wm = (array) json_decode( (string) file_get_contents( $wm_file ), true );
                }
                $ck_file = Installer::cache_root() . '/' . Checkpoint::FILENAME;
                // Watermark-reset detection (counter only — replay is harmless): a
                // node with cluster history (checkpoint present) but no watermark
                // file had its local state wiped (cache-root deletion / purge).
                $wm_reset_detected = ( ! is_readable( $wm_file ) ) && is_readable( $ck_file );

                $C      = $this->checkpoint->read( $my );
                $cur_epoch = $this->current_epoch();
                $now_ms = (int) round( microtime( true ) * 1000 );
                $n      = 0;
                // M5 §6 / N1 §6: per-ROUND deltas — written once at the end, never per event.
                $d_consumed = 0;
                $d_dupes    = 0;
                $d_stale    = 0;
                $d_failed   = 0;
                $d_gaps     = 0;
                $d_recon    = 0;
                $d_authfail = 0;
                $lag_ms     = null;
                $keygen      = null; // lazy-init inside execute_scope (shared across the round)
                $local_store = null;

                // ---- v1 rows (gen 0): id order, M5 semantics -----------------------
                foreach ( $events as $ev ) {
                        if ( (int) ( $ev['gen_epoch'] ?? 0 ) > 0 && (int) ( $ev['schema_version'] ?? 0 ) >= 2 ) {
                                continue; // handled in the v2 pass below
                        }
                        $origin = (string) ( $ev['origin'] ?? '' );
                        $id     = (int) ( $ev['id'] ?? 0 );
                        if ( isset( $wm[ $origin ] ) && $id <= (int) $wm[ $origin ] ) {
                                $store->mark_consumed( $id ); // duplicate: already executed
                                ++$d_dupes;
                                continue;
                        }
                        if ( ! in_array( (int) ( $ev['schema_version'] ?? 0 ), array( 1, EventStore::SCHEMA_VERSION ), true ) ) {
                                $store->mark_consumed( $id ); // unknown schema: skip, never execute (v1 pass accepts Phase M schema 1 + N1 schema 2)
                                continue;
                        }
                        // M5 §4.6 chain-epoch guard (resurrection protection).
                        if ( (int) ( $ev['epoch'] ?? 0 ) < $cur_epoch ) {
                                $store->mark_consumed( $id );
                                ++$d_stale;
                                continue;
                        }
                        $exec = $this->execute_scope( $ev, $store, $keygen, $local_store, $d_failed );
                        $store->mark_consumed( $id );
                        if ( $exec ) {
                                $wm[ $origin ] = $id;
                                $lag_ms        = max( 0, $now_ms - (int) ( $ev['created'] ?? $now_ms ) );
                                ++$d_consumed;
                                ++$n;
                        }
                }

                // ---- v2 rows (gen > 0): GEN order, checkpoint algorithm -------------
                $v2 = array();
                foreach ( $events as $ev ) {
                        if ( (int) ( $ev['gen_epoch'] ?? 0 ) > 0 && (int) ( $ev['schema_version'] ?? 0 ) >= 2 ) {
                                $v2[] = $ev;
                        }
                }
                usort( $v2, function ( $a, $b ) {
                        return (int) ( $a['gen_epoch'] ?? 0 ) <=> (int) ( $b['gen_epoch'] ?? 0 );
                } );
                $expected = $C + 1;
                foreach ( $v2 as $ev ) {
                        $origin = (string) ( $ev['origin'] ?? '' );
                        $id     = (int) ( $ev['id'] ?? 0 );
                        $gen    = (int) ( $ev['gen_epoch'] ?? 0 );
                        // N2-D1 (release-blocking-grade, caught by audit-event-recovery
                        // R8): the M5 per-origin LAST-ID watermark is UNSOUND for the
                        // gen-sorted v2 pass — id order ≠ gen order, so a lower-id /
                        // higher-gen row was flagged "already executed" and silently
                        // DEDUPED WITHOUT EVER EXECUTING (a false duplicate = lost
                        // targeted purge). v2 dedup therefore relies on the durable
                        // checkpoint (gen ≤ C = proven covered — sound); the id
                        // watermark remains ONLY for the v1 pass (id-ordered, M5
                        // semantics, where the assumption holds). The file is still
                        // advanced on v2 execution for observability/back-compat.
                        // Structural validation FIRST (future/corrupt rows never
                        // execute AND never trigger gap processing — their gen stays
                        // unaccounted and the end-of-round floor covers the whole
                        // span with a single reconcile).
                        if ( (int) ( $ev['schema_version'] ?? 0 ) !== EventStore::SCHEMA_VERSION ) {
                                $store->mark_consumed( $id ); // unknown schema: skip
                                continue;
                        }
                        if ( (int) ( $ev['epoch'] ?? 0 ) < $cur_epoch ) {
                                // M5 chain guard: never advance the checkpoint past a skipped
                                // generation — the end-of-round floor re-covers it if needed
                                // (one bounded reconcile per promotion+stale-drain, safe).
                                $store->mark_consumed( $id );
                                ++$d_stale;
                                continue;
                        }
                        if ( $gen <= $C ) {
                                // Generation already PROVEN covered (contiguous chain or
                                // reconcile) — executing again would over-purge; skip+count.
                                $store->mark_consumed( $id );
                                ++$d_stale;
                                continue;
                        }
                        if ( $gen > $expected ) {
                                // GAP: generations (expected .. gen-1) are unaccounted — the
                                // fail-closed floor: reconcile locally, claim coverage up to
                                // gen-1, then process this event normally (no-op deletes).
                                $this->reconcile( 'gap' );
                                ++$d_recon;
                                ++$d_gaps;
                                $C        = $gen - 1;
                                $expected = $gen;
                        }
                        $exec = $this->execute_scope( $ev, $store, $keygen, $local_store, $d_failed );
                        $store->mark_consumed( $id );
                        if ( $exec ) {
                                $wm[ $origin ] = $id;
                                $lag_ms        = max( 0, $now_ms - (int) ( $ev['created'] ?? $now_ms ) );
                                ++$d_consumed;
                                ++$n;
                        }
                        // N2 soundness fix: coverage (C) is claimed ONLY on real
                        // execution — a corrupt/empty/unknown-scope row must NOT
                        // claim its generation (its dirs were never purged; the
                        // end-of-round floor re-covers it with one reconcile).
                        // The SEQUENCE expectation always advances so a broken
                        // row never cascades mid-batch gap reconciles.
                        $expected = $gen + 1;
                        if ( $exec ) {
                                $C = $gen; // targeted execution PROVES coverage of this gen
                        }
                }

                // ---- end-of-round authority floor (design §4.5) ---------------------
                $observed = $this->checkpoint->observed();
                $S        = null;
                if ( $this->authority->available() ) {
                        $S = $this->authority->current();
                }
                if ( null === $S ) {
                        // Authority outage: bumps are impossible while it is down, so the
                        // last OBSERVED value is the true frontier. Fail-closed: if we
                        // have not proven coverage up to it, reconcile once — never
                        // silently claim or defer coverage we cannot prove.
                        if ( $C < $observed ) {
                                $this->reconcile( 'authority-outage' );
                                ++$d_recon;
                                ++$d_authfail;
                                $C = $observed;
                        }
                } elseif ( $S < $C ) {
                        // AUTHORITY RESET/REGRESSION (DB restored / table dropped):
                        // forced reconcile + rebaseline to the new authority era.
                        $this->reconcile( 'authority-reset' );
                        ++$d_recon;
                        ++$d_authfail;
                        $C = $S;
                } elseif ( $S > $C ) {
                        $pending_future = $store->count_pending_future( $my, $C );
                        if ( 0 === $pending_future ) {
                                // Generations (C, S] have NO surviving rows → lost events
                                // (manual deletion, crash between bump and insert, DB
                                // restore) → reconcile the whole span.
                                $this->reconcile( 'lost-tail' );
                                ++$d_recon;
                                ++$d_gaps;
                                $C = $S;
                        }
                        // else: normal backlog — next ticks drain it targeted (burst
                        // contract intact); the checkpoint stays at proven coverage.
                }
                if ( null !== $S ) {
                        $observed = max( $observed, $S );
                }

                if ( $n > 0 ) {
                        if ( ! is_dir( dirname( $wm_file ) ) ) {
                                wp_mkdir_p( dirname( $wm_file ) );
                        }
                        file_put_contents( $wm_file, wp_json_encode( $wm ), LOCK_EX );
                }
                // N1: persist the checkpoint / observation frontier (single atomic
                // write per round, only when something moved).
                if ( $d_recon > 0 || $C !== $this->checkpoint->read( $my ) || $observed > $this->checkpoint->observed() ) {
                        $this->checkpoint->write_all( $C, $observed, $my );
                }
                // M5 §6: one aggregated metrics write per round (never per event).
                // NOTE: epoch_reconciliations is NOT part of the round deltas —
                // reconcile() owns its counter (it also fires on manual/outside-round
                // paths); counting it here too would double-count every reconcile.
                $deltas = array_filter(
                        array(
                                'consumed'                 => $d_consumed,
                                'duplicates'               => $d_dupes,
                                'stale'                    => $d_stale,
                                'failures'                 => $d_failed,
                                'event_gaps'               => $d_gaps,
                                'epoch_authority_failures' => $d_authfail,
                                'watermark_resets'         => $wm_reset_detected ? 1 : 0,
                        )
                );
                try {
                        if ( ! empty( $deltas ) ) {
                                ( new State( Installer::cache_root() ) )->bump( $deltas );
                        }
                        if ( null !== $lag_ms ) {
                                ( new State( Installer::cache_root() ) )->lag( $lag_ms );
                        }
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                        // telemetry must never throw into the tick path
                }
                $store->prune( $my ); // bounded janitor: consumed rows + own claimed rows past retention; pending foreign rows NEVER pruned
                return $n;
        }

        /**
         * Execute one event's scope against THIS node's tree (shared by the v1
         * and v2 passes). Returns true when the event was executed (consumed
         * accounting), false when skipped as structurally empty.
         *
         * @param array  $ev
         * @param EventStore $store
         * @param \UltimatePerformance\CacheKey\Key $keygen
         * @param \UltimatePerformance\PageCache\Store $local_store
         * @param int    $d_failed failed-deletion counter (by ref)
         * @return bool
         */
        private function execute_scope( $ev, $store, &$keygen, &$local_store, &$d_failed ) {
                if ( null === $keygen ) {
                        $keygen      = new \UltimatePerformance\CacheKey\Key( \UltimatePerformance\Core\Settings::instance() );
                        $local_store = new \UltimatePerformance\PageCache\Store( new SafeFs(), $keygen );
                }
                $scope = (string) ( $ev['scope'] ?? '' );
                if ( 'purge_all' === $scope ) {
                        ( new SafeFs() )->delete_tree( Installer::cache_root() . '/v' );
                        return true;
                }
                if ( 'purge_dirs' === $scope ) {
                        $payload = json_decode( (string) ( $ev['payload'] ?? '' ), true );
                        $dirs    = array();
                        foreach ( (array) ( $payload['dirs'] ?? array() ) as $rel ) {
                                // Consumer-side full validation: every segment is re-mapped
                                // through Key::segment — hostile/traversal segments are
                                // hash-mapped to unreachable names INSIDE the tree (they
                                // never escape it; Store::purge then deletes a nonexistent
                                // hashed path harmlessly). Containment is by construction.
                                $clean = $keygen->absolute( (string) $rel );
                                if ( '' !== (string) $rel && false !== $clean ) {
                                        $dirs[] = (string) $rel;
                                }
                        }
                        if ( empty( $dirs ) ) {
                                return false; // nothing executable — marked consumed by caller
                        }
                        foreach ( $dirs as $rel ) {
                                if ( ! $local_store->purge( $rel ) ) { // refuses hostile dirs (false)
                                        ++$d_failed; // deletion did not complete — counted, never fatal
                                }
                        }
                        return true;
                }
                return false; // unknown scope — caller marks consumed
        }

        /** Best-effort metrics bump (telemetry must never throw into the tick). */
        private function metric_bump( array $deltas ) {
                try {
                        ( new State( Installer::cache_root() ) )->bump( $deltas );
                } catch ( \Throwable $e ) { // phpcs:ignore Squiz.Commenting
                }
        }

        /**
         * Current chain epoch (promotion fencing). Source of truth is the
         * object-cache Manager's PERSISTED backend epoch (cross-process);
         * the filter is the test seam (audit suite) — production behavior
         * passes through it unchanged. N1 note: this is the M5 resurrection
         * guard's source ONLY; the durable purge-generation floor lives in
         * Cluster\Epoch (design §4.1) — the two are orthogonal.
         */
        private function current_epoch() {
                try {
                        $epoch = 0;
                        if ( class_exists( '\UltimatePerformance\ObjectCache\Manager' ) ) {
                                $epoch = (int) \UltimatePerformance\ObjectCache\Manager::instance()->current_epoch();
                        }
                        return (int) apply_filters( 'ultimate_cache_cluster_epoch', $epoch );
                } catch ( \Throwable $e ) {
                        return 0;
                }
        }
}
