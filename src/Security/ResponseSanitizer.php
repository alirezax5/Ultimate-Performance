<?php
/**
 * Response sanitizer — final gate before publishing to public cache.
 *
 * ARCHITECTURE CONTRACT (Phase E):
 * The primary defenses are REQUEST CLASSIFICATION and EXPLICIT BYPASS RULES.
 * Unsafe request classes never reach this component. This scanner is
 * DEFENSE IN DEPTH at publish time, never the primary control.
 *
 * Contract promised: "this response contains no DETECTED indicators of
 * user/session/security-specific state." It does NOT promise the response
 * is guaranteed free of personalization — detection limits are documented
 * in docs/SECURITY.md §ResponseSanitizer. On ANY strong indicator, or on
 * internal scanner failure, the response is refused for caching (closed).
 *
 * NEVER strips/rewrites nonces or user data to make a response cacheable:
 * a page containing user-specific tokens is treated as personalized and
 * simply not cached. The cached entry must equal the original response.
 *
 * Patterns are bounded literal needles + bounded regular expressions with
 * no nested/unbounded quantifier interaction — no catastrophic-backtracking
 * shape. New threat shapes must justify a PATTERN CLASS, not copy-paste
 * needles (see Phase E classification A/B/C/D in SECURITY.md).
 *
 * @package UltimatePerformance
 */

namespace UltimatePerformance\Security;

defined( 'ABSPATH' ) || exit;

final class ResponseSanitizer {

        /**
         * Strong literal indicators of per-user content (case-insensitive
         * substring scan). Keep this list SMALL and class-based; prefer adding
         * to self::regexes() when a shape family is involved.
         *
         * @return array<int,string>
         */
        public static function patterns() {
                return apply_filters(
                        'ultimate_performance_unsafe_html_patterns',
                        array(
                                // WP form/query nonces (markup shapes).
                                'name="_wpnonce"',
                                "name='_wpnonce'",
                                'name="_woocommerce_process_checkout_nonce"',
                                'name="_wc_nonce"',
                                '_wp_rest_nonce',
                                // M2-D4 (real-Woo matrix, empirical): the bare 'rest-nonce'
                                // literal over-blocked every real Woo 8.2.2 page — the
                                // apiFetch bootstrap embeds the STATIC endpoint reference
                                // wp.apiFetch.nonceEndpoint = "...admin-ajax.php?action=rest-nonce"
                                // (an address, zero nonce material). Replaced by a scoped regex
                                // below: REST-nonce CARRIER elements (id/class/data-*) and
                                // URL-embedded 10-hex VALUES still fail closed; the static
                                // endpoint address passes.
                                '?_wpnonce=',
                                '&_wpnonce=',
                                'action=logout',
                                'wp-logout-url',
                                // Auth/session artifacts.
                                'wpapisettings', // JSON-encoded REST settings blob (heartbeat/REST nonce carrier).
                                'x-wp-nonce',    // fetch()/XHR custom header usage, any casing.
                                'howdy,',        // Personal greeting (per-user markup).
                                // BENCH-D4 (HARDEN-3): WooCommerce dynamic fragments — SEMANTIC,
                                // not broad substrings. Previous versions used broad literals
                                // like 'woocommerce-mini-cart-item' which substring-matched
                                // 'woocommerce-mini-cart-items-block' (the EMPTY cart container
                                // present on 100% of Woo pages). Now we only match RENDERED
                                // personalized content:
                                //   - woocommerce-cart-nonce / data-cart-nonce: per-session nonces
                                //   - woocommerce-order-overview: order confirmation (post-checkout)
                                //   - billing_email: checkout form user input
                                //   - customer-price: logged-in customer-specific pricing
                                // Structural containers (mini-cart-items-block, mini-cart__buttons,
                                // cart-count, cart-subtotal, woocommerce-checkout as a CSS class)
                                // are SAFE when EMPTY — they are placeholders filled by JS.
                                // The Classifier already bypasses cart/checkout/account pages
                                // via bypass_paths, so these patterns are defense-in-depth for
                                // pages that MIGHT contain personalized Woo fragments.
                                'woocommerce-cart-nonce',
                                'data-cart-nonce',
                                'woocommerce-order-overview',
                                'billing_email',
                                'customer-price',
                                // Session identifiers in URLs.
                                'phpsessid=',
                        )
                );
        }

        /**
         * Bounded regular-expression classes for indicator families that literal
         * needles cannot cover (attribute reordering, spacing, quoting, escaping,
         * pretty-printed JSON). Every pattern here must stay backtrack-safe:
         * literal alternations, character classes, bounded {0,N} — no nested
         * quantifiers, no (.*)+ shapes.
         *
         * @return array<string,string> regex => reason label
         */
        public static function regexes() {
                return apply_filters(
                        'ultimate_performance_unsafe_html_regexes',
                        array(
                                // Logged-in body/admin-bar markers, either quote style.
                                '/class=[\'"][^\'"]*\blogged-in\b[^\'"]*[\'"]/i'                              => 'logged-in-markup',
                                '/\bid=[\'"]wpadminbar[\'"]/i'                                               => 'admin-bar-markup',
                                // Personal greeting variants ("Howdy,&nbsp;" / "Howdy, Name").
                                '/\bhowdy[\s,&;#]+/i'                                                        => 'personal-greeting',
                                // Nonce-shaped token values on request-bearing elements (any attr
                                // order, either quote style). WP nonces are 10 hex chars.
                                //
                                // WC-ANON-NONCE (Finding D / Phase 6): the WooCommerce
                                // `add-to-cart-nonce` field is the WordPress ANONYMOUS-UNIFORM
                                // nonce artifact for the add-to-cart form on every public
                                // single-product page. wp_create_nonce() = substr(wp_hash(tick,
                                // action, uid, token), -12, 10): for anonymous requests uid=0
                                // and token='', so the value is EXACTLY 10 lowercase hex chars
                                // and IDENTICAL for every anonymous visitor within the nonce
                                // tick (12-24h >> page TTL 3600s). Caching it cannot cross
                                // sessions because every anonymous visitor receives the same
                                // value. Proven live on woolena.ir production: three independent
                                // anonymous requests to /product/handmade-womens-raffia-hat/
                                // all returned nonce=0879180088 — identical.
                                //
                                // The Classifier (src/Request/Classifier.php) keeps every
                                // session/cart/auth-cookie request DYNAMIC via the
                                // cookie_bypass_regex, so a logged-in user's nonce (which
                                // WOULD be per-user) never reaches this audit path. The
                                // exemption is therefore defense-in-depth safe: only anonymous
                                // requests reach here, and anonymous nonces are uniform.
                                //
                                // SCOPED exemption (not a broad whitelist): the negative
                                // lookahead (?!...name="woocommerce-add-to-cart-nonce"...) only
                                // fires for that EXACT field name. Any other input/a/form with
                                // a 10-hex value attribute still BLOCKS. The value still must
                                // be exactly 10 hex chars (case-insensitive, matching WP shape)
                                // — non-10-hex values in this field are not WP nonces and
                                // would never appear in real Woo output, so they fall through
                                // to the original BLOCK behavior (caught by other patterns if
                                // malicious).
                                '/<(?:input|a|form)\b(?![^>]{0,400}name=[\'"]woocommerce-add-to-cart-nonce[\'"])[^>]{0,400}(?:value|href|action)=[\'"][0-9a-f]{10}[\'"]/i' => 'nonce-shaped-token',
                                // Generic hidden security-token fields: CSRF/_token/authenticity.
                                '/name\s*=\s*[\'"](?:csrf[_-]?token?|_?csrf|_?token|xsrf[_-]?token?|authenticity_token)[\'"]/i' => 'generic-token-field',
                                // data-* security attributes (data-nonce/token/csrf/user-id/…).
                                '/\bdata-[a-z0-9-]*(?:nonce|token|csrf|user-id|session)[a-z0-9-]*[\s=>]/i'    => 'data-attribute-token',
                                // M2-D4: REST-nonce carriers only (element attribute shape or
                                // URL-embedded 10-hex value). The bare substring matched the
                                // static endpoint address on 100% of real Woo 8.2.2 pages.
                                // name=/property= meta carriers carry the same nonce material.
                                '/(?:[\s"\'](?:id|class|data-[a-z0-9_-]+)\s*=\s*["\']rest-nonce["\']|(?:name|property)\s*=\s*["\'][a-z0-9_-]*rest-nonce["\']|[?&]rest-nonce=[0-9a-f]{10}(?![0-9a-f]))/i' => 'rest-nonce-carrier',
                                // BENCH-D4 (HARDEN-3): RENDERED WooCommerce cart items — only present
                                // when a user has actual products in their cart. The empty cart
                                // container (class="...mini-cart-items-block...") is structural and
                                // safe; a RENDERED item is an <li> with class mini_cart_item AND
                                // a data-product_id attribute (the product the user added). This
                                // regex requires BOTH signals, so empty carts pass through.
                                '/<li[^>]{0,200}\bclass="[^"]*\bmini_cart_item\b[^"]*"[^>]{0,200}\bdata-product_id=/i' => 'rendered-cart-item',
                                // BENCH-D4: rendered cart TOTAL — only present when the cart has
                                // items. The mini-cart__total container is structural, but when
                                // it contains a woocommerce-Price-amount child with an actual
                                // currency value, it's a rendered total (session-bound).
                                // Allows up to ~300 chars of HTML between the two markers (tags, text, entities).
                                '/woocommerce-mini-cart__total[\s\S]{0,300}?woocommerce-Price-amount/i' => 'rendered-cart-total',
                                // BENCH-D4: rendered cart COUNT widget — a cart-count span with
                                // actual item count text followed by a Price-amount. This combination
                                // only appears when a user has items in their cart.
                                '/cart-count[\s\S]{0,200}?woocommerce-Price-amount/i' => 'rendered-cart-count',
                                /*
                                 * Security-relevant JSON/JS-object KEYS. Key presence alone is
                                 * the signal — values are attacker/format dependent. The key
                                 * name is matched WITHOUT requiring adjacent quotes (escaped
                                 * forms put a backslash between key and quote: "nonce\":);
                                 * the .{0,6} bridge then tolerates the quote/backslash/
                                 * whitespace run before the : or = separator. Bounded ⇒
                                 * backtrack-safe, no backslash literals in the match path
                                 * (portable across escaping layers). Unquoted minified
                                 * properties are handled by security-js-property below.
                                 *
                                 * M2-D1 (real-Woo matrix, empirical): the nonce-family branch
                                 * carries ONE exemption — the WordPress ANONYMOUS-UNIFORM
                                 * nonce artifact. wp_create_nonce() = substr(wp_hash(tick,
                                 * action, uid, token), -12, 10): for anonymous requests uid=0
                                 * and token='', so the value is EXACTLY 10 lowercase hex chars
                                 * and IDENTICAL for every anonymous visitor within the nonce
                                 * tick (12-24h ≫ page TTL 3600s). Proven live on real Woo
                                 * 10.2.2 (shop archive Store API config): four independent
                                 * renders — anonymous x2, session A, session B — all emitted
                                 * the same 10-hex nonce. Caching it cannot cross sessions.
                                 * The exemption requires the nonce-suffixed key to be followed
                                 * by a 10-LOWERCASE-HEX value (quoted or not; the check is
                                 * case-sensitive via a scoped (?-i:…) so non-WP uppercase or
                                 * mixed-case token shapes still fail closed); any other value
                                 * shape (longer hex, digits-only order ids under
                                 * other keys) still fails closed. Non-nonce keys (session_id,
                                 * user_id, cart_hash, order_id, token family) are NEVER
                                 * exempt regardless of value shape. Upstream, the classifier
                                 * keeps every session/cart/auth-cookie request DYNAMIC, so a
                                 * user-bound nonce can never reach the store path. Residual
                                 * risk, accepted and documented: a third-party plugin emitting
                                 * per-request RANDOM 10-lowercase-hex tokens under a
                                 * nonce-suffixed JSON key on a public page would be cached —
                                 * worst case is anonymous form-action breakage, never personal
                                 * data leakage (parity with the nonce handling of the major WP
                                 * page-cache plugins). URL-embedded nonces (?_wpnonce=,
                                 * &_wpnonce=) remain hard-blocked by the literal layer above.
                                 *
                                 * M2-D5 (real-Woo matrix, empirical): the user[_-]?id branch
                                 * carries ONE value-shape exemption — the literal ZERO value.
                                 * WordPress core's wp-data persistence bootstrap (every real
                                 * Woo 8.2.2 shop page) embeds `var userId = 0;` — the anonymous
                                 * user id, which carries zero per-user information by
                                 * construction. Any NON-zero value (42, "0123", "abc", 0x-pref)
                                 * still fails closed. customer_id / session_id / cart_hash /
                                 * order_id / token family remain unconditional.
                                 *
                                 * WC-ANON-NONCE (Finding D / Phase 6): the negative lookahead
                                 * also exempts two HTML attribute shapes that carry 10-hex
                                 * values: (a) the WC anonymous-uniform add-to-cart-nonce field
                                 * when an attribute with a 10-hex value follows, and (b) the
                                 * general HTML attribute syntax `nonce" name=` (closing quote,
                                 * space, attr name, `=`) which previously triggered false
                                 * positives when `nonce` was a literal value inside an HTML
                                 * attribute (e.g. `id="...add-to-cart-nonce" name="..."`).
                                 * The Classifier keeps every session/cart/auth-cookie request
                                 * DYNAMIC via cookie_bypass_regex, so logged-in nonces never
                                 * reach this audit path.
                                 */
                                '/(?:nonce(?!["\'\\\\\s]{0,6}[:=]["\'\\\\\s]{0,6}(?-i:[0-9a-f]{10}(?![0-9a-f]))|["\'\\\\\s]{0,6}[a-z0-9_-]{1,30}=["\'][0-9a-f]{10}["\']|["\'\\\\\s]{1,4}[a-z0-9_-]{1,30}=)|wp_rest|csrf[_-]?token?|_?csrf|_?token|xsrf[_-]?token?|auth[_-]?token|access[_-]?token|id[_-]?token|refresh[_-]?token|session[_-]?id|phpsessid|user[_-]?id(?!["\'\\\\\s]{0,6}[:=][\s"\']{0,4}0(?![0-9a-z_]))|customer[_-]?id|cart[_-]?hash|order[_-]?id|checkout[_-]?token).{0,6}[:=]/i' => 'security-json-key',
                        )
                );
        }

        /**
         * Audit full rendered response before it may enter public cache.
         *
         * FAIL-CLOSED: non-strings, undersized/oversized bodies, and ANY internal
         * scanner failure (PCRE error/backtrack/limit) refuse the cache write —
         * a scanner that cannot finish its verdict never answers "safe".
         *
         * @param string $html Raw response body (never modified).
         * @return array{safe:bool,reason:string}
         */
        public function audit( $html ) {
                if ( ! is_string( $html ) ) {
                        return array( 'safe' => false, 'reason' => 'non-string-response' );
                }
                $len = strlen( $html );
                if ( $len < 64 ) {
                        return array( 'safe' => false, 'reason' => 'empty-or-tiny-response' );
                }
                if ( $len > (int) apply_filters( 'ultimate_performance_max_audit_bytes', 8 * 1024 * 1024 ) ) {
                        return array( 'safe' => false, 'reason' => 'oversized-response' );
                }

                foreach ( self::patterns() as $needle ) {
                        if ( '' !== $needle && false !== stripos( $html, $needle ) ) {
                                return array( 'safe' => false, 'reason' => substr( (string) $needle, 0, 40 ) );
                        }
                }

                foreach ( self::regexes() as $rx => $label ) {
                        $hits = @preg_match( (string) $rx, $html );
                        if ( false === $hits ) {
                                // Scanner malfunction (bad pattern, backtrack/limit exhaustion)
                                // must never degrade to "safe".
                                return array( 'safe' => false, 'reason' => 'scanner-failure:' . substr( (string) $label, 0, 24 ) );
                        }
                        if ( 1 === $hits ) {
                                return array( 'safe' => false, 'reason' => substr( (string) $label, 0, 40 ) );
                        }
                }

                return array( 'safe' => true, 'reason' => '' );
        }

        /**
         * Serve-path double check (defense in depth on HIT): cached bodies that
         * would now fail this audit get purged instead of emitted.
         *
         * @param string $body
         * @return bool True when safe to emit from cache.
         */
        public function safe_to_serve( $body ) {
                return true === $this->audit( $body )['safe'];
        }
}
