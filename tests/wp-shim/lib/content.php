<?php
/**
 * WP shim — posts / terms / comments with real hook sequences.
 *
 * Mirrors the production hook contract the invalidation matrix depends on:
 *   wp_insert_post / wp_update_post → save_post (skips auto-draft + revision)
 *   wp_trash_post                   → save_post with status=trash
 *   wp_delete_post                  → delete_post BEFORE row removal
 *   wp_insert_comment               → wp_insert_comment
 *   wp_set_comment_status           → transition_comment_status (+ hook alias)
 *   wp_delete_comment               → delete_comment BEFORE row removal
 *   wp_update_term                  → edit_term; wp_delete_term → delete_term
 *
 * State is file-backed so child processes (concurrency producers) observe the
 * same content "DB".
 *
 * @package UltimatePerformance\Tests\Shim
 */

namespace UltimatePerformance\Tests\Shim;

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

// ---------------------------------------------------------------------------
// Posts
// ---------------------------------------------------------------------------

function posts_all() {
        return state_get( 'posts.json' );
}

function get_post( $id ) {
        $all = posts_all();
        if ( ! isset( $all[ (int) $id ] ) ) {
                return null;
        }
        return (object) $all[ (int) $id ];
}

function wp_insert_post( $arr, $wp_error = false ) {
        $post_type   = isset( $arr['post_type'] ) ? (string) $arr['post_type'] : 'post';
        $post_status = isset( $arr['post_status'] ) ? (string) $arr['post_status'] : 'draft';
        $title       = isset( $arr['post_title'] ) ? (string) $arr['post_title'] : '';
        $name        = isset( $arr['post_name'] ) && '' !== (string) $arr['post_name']
                ? sanitize_title_shim( (string) $arr['post_name'] )
                : sanitize_title_shim( $title . '-' . getmypid() );

        $id = 0;
        state_update(
                'posts.json',
                static function ( $all ) use ( &$id, $arr, $post_type, $post_status, $title, $name ) {
                        // Monotonic id counter (mirrors WP AUTO_INCREMENT): deleted
                        // ids are never reused, so tag files like post:<id> cannot
                        // collide across suite runs sharing the cache tree.
                        $seq = isset( $all['__seq'] ) ? (int) $all['__seq'] : 0;
                        $id  = $seq + 1;
                        $all['__seq'] = $id;
                        unset( $all[ '__seq' ] );
                        $all[ $id ] = array(
                                'ID'           => $id,
                                'post_title'   => $title,
                                'post_name'    => $name,
                                'post_status'  => $post_status,
                                'post_type'    => $post_type,
                                'post_content' => isset( $arr['post_content'] ) ? (string) $arr['post_content'] : '',
                                'post_date'    => current_time( 'mysql' ),
                        );
                        return $all;
                }
        );
        if ( 'auto-draft' !== $post_status && 'inherit' !== $post_status ) {
                shim_do_action( 'save_post', $id, get_post( $id ) );
                shim_do_action( 'wp_insert_post', $id, get_post( $id ) );
        }
        return $id;
}

function wp_update_post( $arr, $wp_error = false ) {
        $id = (int) ( isset( $arr['ID'] ) ? $arr['ID'] : 0 );
        if ( $id <= 0 ) {
                return 0;
        }
        // Capture OLD status for transition_post_status (WP parity: fires when
        // status actually changes, with old + new + post object).
        $old_post = get_post( $id );
        $old_status = $old_post ? (string) $old_post->post_status : 'new';

        state_update(
                'posts.json',
                static function ( $all ) use ( $id, $arr ) {
                        if ( ! isset( $all[ $id ] ) ) {
                                return $all;
                        }
                        foreach ( array( 'post_title', 'post_name', 'post_status', 'post_type', 'post_content' ) as $f ) {
                                if ( isset( $arr[ $f ] ) ) {
                                        $all[ $id ][ $f ] = 'post_name' === $f ? sanitize_title_shim( (string) $arr[ $f ] ) : (string) $arr[ $f ];
                                }
                        }
                        return $all;
                }
        );
        $p = get_post( $id );
        if ( $p && 'auto-draft' !== $p->post_status && 'revision' !== $p->post_type && 'inherit' !== $p->post_status ) {
                $new_status = (string) $p->post_status;
                // WP parity: transition_post_status fires when status actually changes.
                if ( $new_status !== $old_status ) {
                        shim_do_action( 'transition_post_status', $new_status, $old_status, $p );
                }
                shim_do_action( 'save_post', $id, $p );
        }
        return $id;
}

function wp_trash_post( $id ) {
        $id = (int) $id;
        $p  = get_post( $id );
        if ( ! $p ) {
                return false;
        }
        $previous_status = (string) $p->post_status;
        // WP parity: wp_trash_post fires BEFORE the status changes to trash.
        shim_do_action( 'wp_trash_post', $id, $previous_status );
        wp_update_post( array( 'ID' => $id, 'post_status' => 'trash' ) );
        // WP parity: trashed_post fires AFTER the status change.
        shim_do_action( 'trashed_post', $id, $previous_status );
        return $p;
}

function wp_delete_post( $id, $force = false ) {
        $id = (int) $id;
        $p  = get_post( $id );
        if ( ! $p ) {
                return false;
        }
        // WP parity: before_delete_post fires BEFORE the DB DELETE (post still available).
        shim_do_action( 'before_delete_post', $id, $p );
        // WP parity: delete_post fires BEFORE the DB DELETE (post still available).
        shim_do_action( 'delete_post', $id, $p );
        state_update(
                'posts.json',
                static function ( $all ) use ( $id ) {
                        unset( $all[ $id ] );
                        return $all;
                }
        );
        // WP parity: deleted_post + after_delete_post fire AFTER the row is gone.
        shim_do_action( 'deleted_post', $id, $p );
        shim_do_action( 'after_delete_post', $id, $p );
        return $p;
}

function get_permalink( $id ) {
        if ( is_object( $id ) && isset( $id->ID ) ) {
                $id = (int) $id->ID;
        }
        $p = get_post( (int) $id );
        if ( ! $p ) {
                return false;
        }
        // WP parity: product post_type uses /product/<slug>/ permalink structure.
        if ( isset( $p->post_type ) && 'product' === $p->post_type ) {
                return home_url( '/product/' . $p->post_name . '/' );
        }
        return home_url( '/' . $p->post_name . '/' );
}

function post_type_exists( $type ) {
        return in_array( (string) $type, array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'product', 'product_variation' ), true );
}

function sanitize_title_shim( $s ) {
        $s = strtolower( trim( (string) $s ) );
        $s = preg_replace( '/[^a-z0-9\-_]+/', '-', $s );
        return trim( preg_replace( '/-+/', '-', $s ), '-' );
}

// ---------------------------------------------------------------------------
// Terms
// ---------------------------------------------------------------------------

function get_object_taxonomies( $type, $output = 'names' ) {
        if ( is_object( $type ) && isset( $type->post_type ) ) {
                $type = $type->post_type;
        }
        if ( 'post' === $type ) {
                return array( 'category', 'post_tag' );
        }
        if ( 'product' === $type ) {
                return array( 'product_cat', 'product_tag' );
        }
        return array();
}

function wp_insert_term( $term, $taxonomy, $args = array() ) {
        $id = 0;
        state_update(
                'terms.json',
                static function ( $all ) use ( &$id, $term, $taxonomy, $args ) {
                        foreach ( $all as $t ) {
                                if ( isset( $t['name'] ) && $t['name'] === (string) $term && $t['taxonomy'] === (string) $taxonomy ) {
                                        $id = (int) $t['term_id'];
                                        return $all;
                                }
                        }
                        $seq = isset( $all['__seq'] ) ? (int) $all['__seq'] : 0;
                        $id  = $seq + 1;
                        $all['__seq'] = $id;
                        unset( $all[ '__seq' ] );
                        $all[ $id ] = array( 'term_id' => $id, 'name' => (string) $term, 'taxonomy' => (string) $taxonomy );
                        return $all;
                }
        );
        shim_do_action( 'created_term', $id, $id, (string) $taxonomy );
        return array( 'term_id' => $id, 'term_taxonomy_id' => $id );
}

function wp_update_term( $term_id, $taxonomy, $args = array() ) {
        $term_id = (int) $term_id;
        state_update(
                'terms.json',
                static function ( $all ) use ( $term_id, $args, $taxonomy ) {
                        if ( isset( $all[ $term_id ] ) && isset( $args['name'] ) ) {
                                $all[ $term_id ]['name'] = (string) $args['name'];
                        }
                        return $all;
                }
        );
        shim_do_action( 'edit_term', $term_id, $term_id, (string) $taxonomy );
        return array( 'term_id' => $term_id, 'term_taxonomy_id' => $term_id );
}

function wp_delete_term( $term_id, $taxonomy ) {
        $term_id = (int) $term_id;
        state_update(
                'terms.json',
                static function ( $all ) use ( $term_id ) {
                        unset( $all[ $term_id ] );
                        return $all;
                }
        );
        shim_do_action( 'delete_term', $term_id, $term_id, (string) $taxonomy, '' );
        return true;
}

function wp_set_object_terms( $post_id, $terms, $taxonomy, $append = false ) {
        $post_id = (int) $post_id;
        $terms   = array_map( 'intval', (array) $terms );
        state_update(
                'objterms.json',
                static function ( $all ) use ( $post_id, $terms, $taxonomy, $append ) {
                        if ( ! $append ) {
                                $all[ $post_id ][ $taxonomy ] = array();
                        }
                        foreach ( $terms as $t ) {
                                if ( ! in_array( $t, $all[ $post_id ][ $taxonomy ], true ) ) {
                                        $all[ $post_id ][ $taxonomy ][] = $t;
                                }
                        }
                        return $all;
                }
        );
        shim_do_action( 'set_object_terms', $post_id, $terms, $taxonomy, $append );
        return true;
}

function wp_remove_object_terms( $post_id, $terms, $taxonomy ) {
        $post_id = (int) $post_id;
        $terms   = array_map( 'intval', (array) $terms );
        state_update(
                'objterms.json',
                static function ( $all ) use ( $post_id, $terms, $taxonomy ) {
                        if ( isset( $all[ $post_id ][ $taxonomy ] ) ) {
                                $all[ $post_id ][ $taxonomy ] = array_values( array_diff( $all[ $post_id ][ $taxonomy ], $terms ) );
                        }
                        return $all;
                }
        );
        return true;
}

function wp_get_object_terms( $post_id, $taxonomies, $args = array() ) {
        $all = state_get( 'objterms.json' );
        $ids = array();
        foreach ( (array) $taxonomies as $tax ) {
                if ( isset( $all[ (int) $post_id ][ $tax ] ) ) {
                        foreach ( $all[ (int) $post_id ][ $tax ] as $tid ) {
                                if ( 'ids' === ( isset( $args['fields'] ) ? $args['fields'] : 'all' ) ) {
                                        $ids[] = (int) $tid;
                                } else {
                                        $ids[] = (object) array( 'term_id' => (int) $tid );
                                }
                        }
                }
        }
        return $ids;
}

function get_term_link( $term, $taxonomy = '' ) {
        if ( is_object( $term ) && isset( $term->term_id ) ) {
                $term = $term->term_id;
        }
        return home_url( '/term-' . (int) $term . '/' );
}

// ---------------------------------------------------------------------------
// Comments
// ---------------------------------------------------------------------------

function wp_insert_comment( $arr ) {
        $id = 0;
        state_update(
                'comments.json',
                static function ( $all ) use ( &$id, $arr ) {
                        $seq = isset( $all['__seq'] ) ? (int) $all['__seq'] : 0;
                        $id  = $seq + 1;
                        $all['__seq'] = $id;
                        unset( $all[ '__seq' ] );
                        $all[ $id ] = array(
                                'comment_ID'          => $id,
                                'comment_post_ID'     => (int) ( isset( $arr['comment_post_ID'] ) ? $arr['comment_post_ID'] : 0 ),
                                'comment_approved'    => isset( $arr['comment_approved'] ) ? $arr['comment_approved'] : 1,
                                'comment_author'      => isset( $arr['comment_author'] ) ? (string) $arr['comment_author'] : '',
                                'comment_author_email'=> isset( $arr['comment_author_email'] ) ? (string) $arr['comment_author_email'] : '',
                                'comment_content'     => isset( $arr['comment_content'] ) ? (string) $arr['comment_content'] : '',
                                'comment_date'        => isset( $arr['comment_date'] ) ? (string) $arr['comment_date'] : current_time( 'mysql' ),
                                'comment_date_gmt'    => isset( $arr['comment_date_gmt'] ) ? (string) $arr['comment_date_gmt'] : current_time( 'mysql' ),
                        );
                        return $all;
                }
        );
        shim_do_action( 'wp_insert_comment', $id, get_comment( $id ) );
        return $id;
}

function get_comment( $id ) {
        $all = state_get( 'comments.json' );
        return isset( $all[ (int) $id ] ) ? (object) $all[ (int) $id ] : null;
}

function wp_set_comment_status( $id, $status ) {
        $id   = (int) $id;
        $c    = get_comment( $id );
        if ( ! $c ) {
                return false;
        }
        $old  = ( 1 === (int) $c->comment_approved || '1' === (string) $c->comment_approved ) ? 'approved' : 'hold';
        $new  = ( 'approve' === (string) $status || '1' === (string) $status ) ? 'approved' : 'hold';
        state_update(
                'comments.json',
                static function ( $all ) use ( $id, $new ) {
                        if ( isset( $all[ $id ] ) ) {
                                $all[ $id ]['comment_approved'] = 'approved' === $new ? 1 : 0;
                        }
                        return $all;
                }
        );
        shim_do_action( 'wp_set_comment_status', $id, $new );
        shim_do_action( 'transition_comment_status', $new, $old, get_comment( $id ) );
        return true;
}

function wp_delete_comment( $id, $force = false ) {
        $id = (int) $id;
        $c  = get_comment( $id );
        if ( ! $c ) {
                return false;
        }
        shim_do_action( 'delete_comment', $id, $c ); // BEFORE removal (WP parity).
        state_update(
                'comments.json',
                static function ( $all ) use ( $id ) {
                        unset( $all[ $id ] );
                        return $all;
                }
        );
        return true;
}
