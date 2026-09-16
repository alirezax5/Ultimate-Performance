<?php
/**
 * Object-Cache tab save-flow audit.
 *
 * Walks every form-ownership scenario through Settings::save_from_admin()
 * and verifies the password preservation contract:
 *
 *   1. Submit Object Cache general form (up_subsection=general)
 *      → redis.auth MUST be preserved (form does not own redis.*)
 *
 *   2. Submit Redis form with BLANK password (no clear)
 *      → redis.auth MUST be preserved (blank → preserve, never clear)
 *
 *   3. Submit Redis form with NEW password
 *      → redis.auth MUST be replaced with the new value
 *
 *   4. Submit Redis form with auth_clear=1 (and blank password)
 *      → redis.auth MUST be cleared to '' (explicit clear wins)
 *
 *   5. Submit Memcached form
 *      → redis.auth MUST be preserved (different form, doesn't own redis)
 *
 *   6. Submit AMQP form
 *      → redis.auth MUST be preserved
 *
 *   7. Submit Redis form WITHOUT the auth field at all (impossible in
 *      the real form, but defensive)
 *      → redis.auth MUST be preserved (field omitted → preserve)
 *
 *   8. Submit Redis form with whitespace-only password ('   ')
 *      → redis.auth MUST be preserved (trim() yields '' → preserve)
 *
 * Run with: php tests/audit-object-cache-save-flow.php
 */

// Stub WordPress functions the Settings class expects.
namespace {
    if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
    if ( ! defined( 'MONTH_IN_SECONDS' ) ) define( 'MONTH_IN_SECONDS', 2592000 );
    if ( ! defined( 'DAY_IN_SECONDS' ) ) define( 'DAY_IN_SECONDS', 86400 );
    if ( ! defined( 'HOUR_IN_SECONDS' ) ) define( 'HOUR_IN_SECONDS', 3600 );

    // In-memory option store (simulates wp_options).
    $GLOBALS['__uc_options'] = array();

    function get_option( $name, $default = false ) {
        return isset( $GLOBALS['__uc_options'][ $name ] ) ? $GLOBALS['__uc_options'][ $name ] : $default;
    }
    function update_option( $name, $value, $autoload = null ) {
        $GLOBALS['__uc_options'][ $name ] = $value;
        return true;
    }
    function delete_option( $name ) {
        unset( $GLOBALS['__uc_options'][ $name ] );
        return true;
    }
    function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $s ) ); }
    function sanitize_text_field( $s ) { return is_string( $s ) ? trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( $s ) ) ) : ''; }
    function absint( $v ) { return abs( (int) $v ); }
    function __( $s, $domain = '' ) { return $s; }
    function esc_html__( $s, $domain = '' ) { return $s; }
    function esc_attr__( $s, $domain = '' ) { return $s; }
    function esc_html_e( $s, $domain = '' ) { echo $s; }
    function esc_attr_e( $s, $domain = '' ) { echo $s; }
    function esc_attr( $s ) { return $s; }
    function do_action( $tag, ...$args ) { /* no-op */ }
    function apply_filters( $tag, $value, ...$args ) { return $value; }
}

namespace UltimatePerformance\Core {
    require __DIR__ . '/../src/Core/Settings.php';

    class ObjectCacheSaveFlowAudit {
        const EXPECTED_PASSWORD = 'mypassword123';
        const NEW_PASSWORD      = 'newpassword456';

        public function run() {
            $results = array();
            $scenarios = array(
                '1. OC-general form (subsection=general) — must preserve redis.auth' =>
                    array( $this, 'scenario_oc_general' ),
                '2. Redis form, BLANK password (no clear) — must preserve' =>
                    array( $this, 'scenario_redis_blank' ),
                '3. Redis form, NEW password — must replace' =>
                    array( $this, 'scenario_redis_new_password' ),
                '4. Redis form, BLANK + auth_clear=1 — must CLEAR' =>
                    array( $this, 'scenario_redis_clear' ),
                '5. Memcached form — must preserve redis.auth' =>
                    array( $this, 'scenario_memcached' ),
                '6. AMQP form — must preserve redis.auth' =>
                    array( $this, 'scenario_amqp' ),
                '7. Redis form, auth field OMITTED — must preserve' =>
                    array( $this, 'scenario_redis_omitted' ),
                '8. Redis form, whitespace-only password — must preserve' =>
                    array( $this, 'scenario_redis_whitespace' ),
            );

            foreach ( $scenarios as $label => $cb ) {
                $GLOBALS['__uc_options'] = array();
                // Pre-seed: password is set in stored settings.
                $stored = \UltimatePerformance\Core\Settings::defaults();
                $stored['redis']['auth'] = self::EXPECTED_PASSWORD;
                $GLOBALS['__uc_options'][ \UltimatePerformance\Core\Settings::OPTION ] = $stored;

                // Force a fresh Settings instance.
                $ref = new \ReflectionProperty( \UltimatePerformance\Core\Settings::class, 'instance' );
                $ref->setAccessible( true );
                $ref->setValue( null, null );

                $errors = $cb();
                $actual = \UltimatePerformance\Core\Settings::instance()->get( 'redis.auth', '<missing>' );

                $expected = $this->expected_for( $cb );
                $pass = ( $actual === $expected );

                $results[] = array(
                    'label'    => $label,
                    'input'     => $this->last_input,
                    'expected'  => $this->repr( $expected ),
                    'actual'    => $this->repr( $actual ),
                    'errors'    => $errors,
                    'pass'      => $pass,
                );
            }

            $this->report( $results );
            $failed = count( array_filter( $results, function( $r ) { return ! $r['pass']; } ) );
            return $failed;
        }

        private function expected_for( $cb ) {
            $map = array(
                'scenario_oc_general'        => self::EXPECTED_PASSWORD,  // preserved
                'scenario_redis_blank'       => self::EXPECTED_PASSWORD,  // preserved
                'scenario_redis_new_password' => self::NEW_PASSWORD,        // replaced
                'scenario_redis_clear'       => '',                          // cleared
                'scenario_memcached'         => self::EXPECTED_PASSWORD,  // preserved
                'scenario_amqp'              => self::EXPECTED_PASSWORD,  // preserved
                'scenario_redis_omitted'     => self::EXPECTED_PASSWORD,  // preserved
                'scenario_redis_whitespace' => self::EXPECTED_PASSWORD,  // preserved (whitespace trims to blank)
            );
            $name = (new \ReflectionMethod( $cb[0], $cb[1] ))->getName();
            return isset( $map[ $name ] ) ? $map[ $name ] : '<unknown>';
        }

        private function repr( $v ) {
            if ( is_string( $v ) ) {
                return '' === $v ? "'' (empty string)" : "'$v'";
            }
            return var_export( $v, true );
        }

        // ===== Scenarios =====

        public function scenario_oc_general() {
            $input = array(
                'up_section'        => 'object-cache',
                'up_subsection'     => 'general',
                'object_cache_enabled' => '1',
                'object_cache_prefix'  => 'mysite',
            );
            return $this->save( $input );
        }

        public function scenario_redis_blank() {
            $input = array(
                'up_section'    => 'object-cache',
                'up_subsection' => 'redis',
                'redis' => array(
                    'host'  => '127.0.0.1',
                    'port'  => '6379',
                    'db'    => '0',
                    'auth'  => '',         // BLANK — should preserve
                    'tls'   => '1',
                ),
            );
            return $this->save( $input );
        }

        public function scenario_redis_new_password() {
            $input = array(
                'up_section'    => 'object-cache',
                'up_subsection' => 'redis',
                'redis' => array(
                    'host'  => '127.0.0.1',
                    'port'  => '6379',
                    'db'    => '0',
                    'auth'  => self::NEW_PASSWORD,
                    'tls'   => '1',
                ),
            );
            return $this->save( $input );
        }

        public function scenario_redis_clear() {
            $input = array(
                'up_section'    => 'object-cache',
                'up_subsection' => 'redis',
                'redis' => array(
                    'host'       => '127.0.0.1',
                    'port'       => '6379',
                    'db'         => '0',
                    'auth'       => '',           // blank
                    'auth_clear' => '1',           // explicit clear
                    'tls'        => '1',
                ),
            );
            return $this->save( $input );
        }

        public function scenario_memcached() {
            $input = array(
                'up_section'    => 'object-cache',
                'up_subsection' => 'memcached',
                'memcached' => array(
                    'host' => '10.0.0.5',
                    'port' => '11211',
                ),
            );
            return $this->save( $input );
        }

        public function scenario_amqp() {
            $input = array(
                'up_section'    => 'queue',
                'up_subsection' => 'rabbitmq',
                'amqp' => array(
                    'host'     => '127.0.0.1',
                    'port'     => '5672',
                    'user'     => 'guest',
                    'vhost'    => '/',
                    'exchange' => 'ultimate-performance',
                    'pass'     => '',  // also blank — must preserve
                ),
            );
            return $this->save( $input );
        }

        public function scenario_redis_omitted() {
            $input = array(
                'up_section'    => 'object-cache',
                'up_subsection' => 'redis',
                'redis' => array(
                    'host'  => '127.0.0.1',
                    'port'  => '6379',
                    'db'    => '0',
                    // 'auth' deliberately NOT in the array
                    'tls'   => '1',
                ),
            );
            return $this->save( $input );
        }

        public function scenario_redis_whitespace() {
            $input = array(
                'up_section'    => 'object-cache',
                'up_subsection' => 'redis',
                'redis' => array(
                    'host'  => '127.0.0.1',
                    'port'  => '6379',
                    'db'    => '0',
                    'auth'  => '     ',  // whitespace only
                    'tls'   => '1',
                ),
            );
            return $this->save( $input );
        }

        // ===== Helpers =====

        private $last_input = null;
        private function save( array $input ) {
            $this->last_input = $input;
            return \UltimatePerformance\Core\Settings::instance()->save_from_admin( $input );
        }

        private function report( array $results ) {
            echo str_repeat( '=', 78 ) . "\n";
            echo "OBJECT-CACHE TAB SAVE-FLOW AUDIT\n";
            echo "Scenarios: " . count( $results ) . "\n";
            echo str_repeat( '=', 78 ) . "\n\n";

            $pass_count = 0;
            foreach ( $results as $i => $r ) {
                $num = $i + 1;
                $status = $r['pass'] ? 'PASS' : 'FAIL';
                if ( $r['pass'] ) $pass_count++;
                echo "[" . $num . "/" . count( $results ) . "] " . $status . " — " . $r['label'] . "\n";
                echo "    expected: redis.auth = " . $r['expected'] . "\n";
                echo "    actual:   redis.auth = " . $r['actual'] . "\n";
                if ( ! empty( $r['errors'] ) ) {
                    echo "    errors:   " . json_encode( $r['errors'] ) . "\n";
                }
                echo "\n";
            }

            echo str_repeat( '=', 78 ) . "\n";
            echo "SUMMARY: $pass_count/" . count( $results ) . " PASS";
            if ( $pass_count < count( $results ) ) {
                echo " — " . ( count( $results ) - $pass_count ) . " FAILED";
            }
            echo "\n";
        }
    }
}

namespace {
    $exit = ( new \UltimatePerformance\Core\ObjectCacheSaveFlowAudit() )->run();
    exit( $exit );
}
