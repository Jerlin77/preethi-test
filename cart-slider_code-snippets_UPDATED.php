<?php

/**
 * cart slider
 */

/**
 * Custom WooCommerce Side Cart & Single-Page Checkout Drawer
 *
 * ONE simple checkout flow (no tabs):
 *
 *   1. Everybody starts with a single box: email + OTP  (login / create account).
 *   2. Existing customer -> saved address card (Edit / Show less),
 *                           with the "Logged in & Verified" box right below it.
 *   3. New customer      -> "Email Verified" box, then the address form, then two
 *                           radio options: Create an account (default) / Checkout as guest.
 *
 * The saved-address card is filled ONLY from saved data. It never mirrors what
 * somebody is typing, and it is never shown to a brand-new customer.
 */

// 1. Register Shortcode for Cart Count
add_shortcode('akshara_cart_count', 'akshara_get_cart_count');
function akshara_get_cart_count() {
    return (function_exists('WC') && WC()->cart) ? WC()->cart->get_cart_contents_count() : 0;
}

// Safely fetch Razorpay Key ID from WooCommerce Settings
function akshara_get_razorpay_key() {
    $settings = get_option('woocommerce_razorpay_settings');
    if (!empty($settings['key_id'])) return $settings['key_id'];
    if (!empty($settings['test_key_id'])) return $settings['test_key_id'];
    return '';
}

// -----------------------------------------------------------------------------
// SMALL HELPERS
// -----------------------------------------------------------------------------

// Read a posted text value safely (handles missing keys, arrays and WordPress slashing).
function akshara_post($key) {
    return (isset($_POST[$key]) && is_scalar($_POST[$key])) ? sanitize_text_field(wp_unslash((string) $_POST[$key])) : '';
}

// Read a posted email, lower-cased so "A@x.com" and "a@x.com" are the same person.
function akshara_post_email($key) {
    return (isset($_POST[$key]) && is_scalar($_POST[$key])) ? strtolower(sanitize_email(wp_unslash((string) $_POST[$key]))) : '';
}

// Indian states as code => name. Taken from WooCommerce itself so the saved codes
// always match what WooCommerce shows in orders, emails, tax and shipping zones.
function akshara_get_states() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $states = array();
    if (function_exists('WC') && WC() && !empty(WC()->countries)) {
        $wc_states = WC()->countries->get_states('IN');
        if (is_array($wc_states) && !empty($wc_states)) {
            $states = $wc_states;
        }
    }

    if (empty($states)) {
        $states = array(
            'AN' => 'Andaman and Nicobar Islands', 'AP' => 'Andhra Pradesh', 'AR' => 'Arunachal Pradesh',
            'AS' => 'Assam', 'BR' => 'Bihar', 'CH' => 'Chandigarh', 'CT' => 'Chhattisgarh',
            'DH' => 'Dadra and Nagar Haveli and Daman and Diu', 'DL' => 'Delhi', 'GA' => 'Goa',
            'GJ' => 'Gujarat', 'HR' => 'Haryana', 'HP' => 'Himachal Pradesh', 'JK' => 'Jammu and Kashmir',
            'JH' => 'Jharkhand', 'KA' => 'Karnataka', 'KL' => 'Kerala', 'LA' => 'Ladakh',
            'LD' => 'Lakshadweep', 'MP' => 'Madhya Pradesh', 'MH' => 'Maharashtra', 'MN' => 'Manipur',
            'ML' => 'Meghalaya', 'MZ' => 'Mizoram', 'NL' => 'Nagaland', 'OR' => 'Odisha',
            'PY' => 'Puducherry', 'PB' => 'Punjab', 'RJ' => 'Rajasthan', 'SK' => 'Sikkim',
            'TN' => 'Tamil Nadu', 'TG' => 'Telangana', 'TR' => 'Tripura', 'UP' => 'Uttar Pradesh',
            'UT' => 'Uttarakhand', 'WB' => 'West Bengal',
        );
    }

    asort($states, SORT_NATURAL | SORT_FLAG_CASE);
    $cache = $states;
    return $cache;
}

// Turn whatever is saved as "state" (code, full name, or an older/alternate code) into
// this store's state code. Returns '' when it cannot be matched.
function akshara_normalize_state($raw) {
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }

    $states = akshara_get_states();
    $upper  = strtoupper($raw);
    if (isset($states[$upper])) {
        return $upper;
    }

    $lower = strtolower($raw);
    foreach ($states as $code => $name) {
        if (strtolower($name) === $lower) {
            return $code;
        }
    }

    // Older / alternate codes (WooCommerce and ISO sometimes differ) -> state name
    $aliases = array(
        'TG' => 'telangana', 'TS' => 'telangana',
        'UT' => 'uttarakhand', 'UK' => 'uttarakhand', 'UC' => 'uttarakhand',
        'OR' => 'odisha', 'OD' => 'odisha',
        'CG' => 'chhattisgarh', 'CT' => 'chhattisgarh',
        'DH' => 'dadra and nagar haveli', 'DN' => 'dadra and nagar haveli', 'DD' => 'daman and diu',
    );
    if (isset($aliases[$upper])) {
        foreach ($states as $code => $name) {
            if (strtolower($name) === $aliases[$upper]) {
                return $code;
            }
        }
    }

    return '';
}

// "+91 98765 43210" / "098765 43210" -> "9876543210"
function akshara_normalize_phone($raw) {
    $digits = preg_replace('/\D/', '', (string) $raw);
    if (strlen($digits) === 12 && strpos($digits, '91') === 0) {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 11 && $digits[0] === '0') {
        $digits = substr($digits, 1);
    }
    return $digits;
}

// A customer's saved billing address, cleaned up, plus a "complete" flag.
// "complete" = every required field is present AND valid, so the saved-address card
// can safely be shown and the order can be placed without opening the form.
function akshara_get_saved_address($user_id) {
    $meta = function ($key) use ($user_id) {
        return trim((string) get_user_meta($user_id, $key, true));
    };

    $user = get_userdata($user_id);
    $a = array(
        'fname'    => $meta('billing_first_name'),
        'lname'    => $meta('billing_last_name'),
        'addr1'    => $meta('billing_address_1'),
        'addr2'    => $meta('billing_address_2'),
        'city'     => $meta('billing_city'),
        'state'    => akshara_normalize_state($meta('billing_state')),
        'postcode' => preg_replace('/\s+/', '', $meta('billing_postcode')),
        'phone'    => akshara_normalize_phone($meta('billing_phone')),
    );

    // Fall back to the WordPress profile name if the billing name was never saved
    if ($a['fname'] === '' && $user) {
        $a['fname'] = trim((string) $user->first_name);
    }
    if ($a['lname'] === '' && $user) {
        $a['lname'] = trim((string) $user->last_name);
    }

    $a['complete'] = (
        $a['fname'] !== '' && $a['lname'] !== '' && $a['addr1'] !== '' && $a['city'] !== '' &&
        $a['state'] !== '' &&
        (bool) preg_match('/^[1-9][0-9]{5}$/', $a['postcode']) &&
        (bool) preg_match('/^[6-9][0-9]{9}$/', $a['phone'])
    );

    return $a;
}

// Create a WooCommerce "customer" account for a verified email. Returns user ID or WP_Error.
function akshara_create_customer($email, $fname, $lname) {
    $parts = explode('@', $email);
    $base  = sanitize_user($parts[0], true);
    if ($base === '') {
        $base = 'customer';
    }

    $username = $base;
    for ($i = 0; $i < 10 && username_exists($username); $i++) {
        $username = $base . wp_rand(100, 9999);
    }

    return wp_insert_user(array(
        'user_login'   => $username,
        'user_pass'    => wp_generate_password(16, true),
        'user_email'   => $email,
        'first_name'   => $fname,
        'last_name'    => $lname,
        'display_name' => trim($fname . ' ' . $lname),
        'role'         => 'customer',
    ));
}

// 2. Add Inline Styles & Side Cart HTML Structure to Footer
add_action('wp_footer', 'akshara_side_cart_html');
function akshara_side_cart_html() {
    if (is_cart() || is_checkout() || is_page('quick-checkout')) return;
    $rzp_key = akshara_get_razorpay_key();

    $is_logged_in = is_user_logged_in();
    $current_user = wp_get_current_user();

    // Everything the checkout script needs to know on page load.
    // (JSON_HEX_* keeps saved addresses from ever breaking out of the script tag.)
    $checkout_config = array(
        'ajaxUrl'  => admin_url('admin-ajax.php'),
        'loggedIn' => $is_logged_in,
        'email'    => $is_logged_in ? $current_user->user_email : '',
        'address'  => $is_logged_in ? akshara_get_saved_address($current_user->ID) : null,
        'states'   => akshara_get_states(),
    );
    ?>
    <script type="text/javascript">
        window.aksharaRzpKey = "<?php echo esc_js($rzp_key); ?>";
        window.checkoutLoadTime = Date.now();
        window.aksharaIsLoggedIn = <?php echo $is_logged_in ? 'true' : 'false'; ?>;
        window.aksharaCheckoutConfig = <?php echo wp_json_encode($checkout_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>

    <!-- Styles for the login box, saved-address card, verified box, account choice & form errors -->
    <style type="text/css">
        /* ---------- Login / create account (email OTP) ---------- */
        .login-otp-card {
            background: #f8f9fa;
            padding: 16px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-bottom: 18px;
        }
        .login-otp-card h5 {
            margin: 0 0 4px 0;
            font-size: 14px;
            font-weight: 700;
            color: #111;
        }
        .login-otp-card p {
            font-size: 12px;
            color: #666;
            margin: 0 0 12px 0;
            line-height: 1.4;
        }
        .email-otp-group { position: relative; }
        .otp-input-flex {
            display: flex;
            gap: 8px;
            margin-top: 4px;
        }
        .akc-login-input {
            flex: 1;
            min-width: 0;
            padding: 8px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
        }
        .send-otp-btn {
            white-space: nowrap;
            background: #44180D !important;
            color: #ffffff !important;
            border: none;
            padding: 0 14px;
            height: 36px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: background 0.2s ease;
        }
        .send-otp-btn:hover { opacity: 0.9; }
        .send-otp-btn:disabled { background: #ccc !important; cursor: not-allowed; }
        .otp-verify-card {
            display: none;
            background: #ffffff;
            border: 1px dashed #cbd5e1;
            padding: 12px;
            border-radius: 6px;
            margin-top: 10px;
        }
        .otp-verify-card label {
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            display: block;
            margin-bottom: 4px;
        }
        .field-otp-input {
            flex: 1;
            min-width: 0;
            letter-spacing: 4px;
            font-weight: 700;
            font-size: 16px;
            text-align: center;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 6px 8px;
        }
        .verify-otp-btn {
            background: #16a34a !important;
            color: #ffffff !important;
            border: none;
            padding: 0 16px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: background 0.2s ease;
        }
        .verify-otp-btn:hover { background: #15803d !important; }
        .verify-otp-btn:disabled { background: #9ca3af !important; cursor: not-allowed; }
        .otp-status-msg {
            font-size: 12px;
            margin-top: 4px;
            display: block;
            font-weight: 500;
            color: #666;
        }
        .otp-status-msg.is-ok  { color: #16a34a; }
        .otp-status-msg.is-err { color: #dc2626; }

        /* ---------- Verified box (always sits directly under the saved-address card) ---------- */
        #checkout-details-container #field-email {
            width: 100%;
            box-sizing: border-box;
            background: #f1f5f9;
            cursor: default;
        }
        .otp-verified-badge {
            background: #dcfce7;
            border: 1px solid #86efac;
            color: #166534;
            padding: 8px 10px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 12.5px;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
        }
        .akc-change-email {
            margin-left: auto;
            font-size: 12px;
            font-weight: 600;
            color: #166534;
            text-decoration: underline;
            cursor: pointer;
        }
        .akc-change-email:focus-visible,
        .edit-address-link:focus-visible {
            outline: 2px solid #800000;
            outline-offset: 2px;
            border-radius: 2px;
        }

        /* ---------- Saved address card (Edit / Show less) ---------- */
        .shipping-address-preview-box {
            background: #f9f9f9;
            padding: 12px;
            border-radius: 6px;
            font-size: 13px;
            line-height: 1.45;
            margin-bottom: 15px;
            border: 1px solid #e5e7eb;
        }
        .akc-addr-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }
        .akc-addr-head strong { min-width: 0; word-break: break-word; }
        .edit-address-link {
            flex: 0 0 auto;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            color: #800000;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            white-space: nowrap;
        }
        .edit-address-link:hover { text-decoration: underline; }
        #akc-toggle-ico { font-size: 12px; }

        /* ---------- Create account / checkout as guest ---------- */
        .akc-choice { margin: 4px 0 16px; }
        .akc-choice-title {
            font-size: 13px;
            font-weight: 700;
            color: #111;
            margin: 0 0 8px;
        }
        .akc-choice-option {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 12px;
            margin: 0 0 8px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            background: #ffffff;
            cursor: pointer;
            transition: border-color 0.15s ease, background-color 0.15s ease;
        }
        .akc-choice-option.is-selected {
            border-color: #44180D;
            background: #fdf8f5;
        }
        .akc-choice-option:has(input:focus-visible) {
            outline: 2px solid #44180D;
            outline-offset: 2px;
        }
        .akc-choice-option input[type="radio"] {
            flex: 0 0 auto;
            width: 16px !important;
            height: 16px !important;
            min-width: 16px;
            margin: 2px 0 0 !important;
            padding: 0 !important;
            accent-color: #44180D;
            cursor: pointer;
        }
        .akc-choice-text { display: block; min-width: 0; }
        .akc-choice-text strong {
            display: block;
            font-size: 13px;
            color: #111;
            line-height: 1.3;
        }
        .akc-choice-text small {
            display: block;
            margin-top: 2px;
            font-size: 12px;
            color: #666;
            line-height: 1.4;
        }

        /* ---------- Field errors, place-order hint ---------- */
        .field-error-notice {
            font-size: 11px;
            color: #dc2626;
            margin-top: 3px;
            display: none;
        }
        .field-error-notice.is-on { display: block; }
        .drawer-checkout-section input.akc-invalid { border-color: #dc2626 !important; }
        .akc-order-error {
            display: none;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            border-radius: 6px;
            padding: 8px 10px;
            font-size: 12.5px;
            line-height: 1.4;
            margin: 0 0 10px;
        }
        .akc-order-hint {
            display: none;
            font-size: 12px;
            color: #92400e;
            text-align: center;
            margin: 0 0 8px;
        }
        .drawer-place-order-btn:disabled {
            opacity: 0.55 !important;
            cursor: not-allowed !important;
            background-color: #94a3b8 !important;
        }

        @media (max-width: 768px) {
            .otp-input-flex { flex-direction: column; gap: 6px; }
            .send-otp-btn, .verify-otp-btn { width: 100%; padding: 10px 16px; }
            .field-otp-input { width: 100%; }
        }
        @media (prefers-reduced-motion: reduce) {
            .akc-choice-option, .send-otp-btn, .verify-otp-btn { transition: none; }
        }
    </style>

    <div id="side-cart-overlay" class="side-cart-overlay"></div>
    <div id="side-cart-drawer" class="side-cart-drawer">
        <!-- VIEW 1: CART ITEMS -->
        <div id="side-cart-view-cart" class="side-cart-view active-view">
            <div class="side-cart-header">
                <div class="side-cart-header-left">
                    <h3>My Cart <span class="side-cart-count"><?php echo WC()->cart ? WC()->cart->get_cart_contents_count() : 0; ?></span></h3>
                </div>
                <button id="side-cart-close" class="side-cart-close" aria-label="Close Cart">&times;</button>
            </div>
            <div class="side-cart-body" id="side-cart-body-content">
                <?php akshara_render_side_cart_content(); ?>
            </div>
        </div>

        <!-- VIEW 2: CHECKOUT -->
        <div id="side-cart-view-checkout" class="side-cart-view">
            <div class="side-cart-header">
                <div class="side-cart-header-left">
                    <button id="back-to-cart-btn" class="back-link" type="button" aria-label="Back to Cart">&#8592;</button>
                    <h3>Checkout</h3>
                </div>
                <button id="side-cart-close-2" class="side-cart-close" aria-label="Close Cart">&times;</button>
            </div>

            <div class="side-cart-body">
                <div class="drawer-checkout-section">

                    <!-- STEP 1: LOGIN / CREATE ACCOUNT (email OTP). Visible until the email is verified. -->
                    <div id="akc-login-card" class="login-otp-card">
                        <h5>Login or create account</h5>
                        <p>Enter your email and we'll send a one-time code. No password needed.</p>

                        <div class="form-group email-otp-group" style="margin-bottom:0;">
                            <label for="akc-email-input">Email address *</label>
                            <div class="otp-input-flex">
                                <input type="email" id="akc-email-input" class="akc-login-input" placeholder="Enter your email" autocomplete="email" inputmode="email" autocapitalize="off" spellcheck="false">
                                <button type="button" id="akc-send-otp-btn" class="send-otp-btn">Send OTP</button>
                            </div>
                            <span class="field-error-notice" id="akc-email-error">Please enter a valid email address.</span>
                            <span id="akc-otp-status" class="otp-status-msg" role="status" aria-live="polite"></span>
                        </div>

                        <div class="otp-verify-card" id="akc-otp-wrapper">
                            <label for="akc-otp-input">Enter the 6-digit OTP sent to your email *</label>
                            <div class="otp-input-flex">
                                <input type="text" id="akc-otp-input" class="field-otp-input" placeholder="123456" maxlength="6" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code">
                                <button type="button" id="akc-verify-otp-btn" class="verify-otp-btn">Verify</button>
                            </div>
                            <span id="akc-verify-status" class="otp-status-msg" role="status" aria-live="polite">Code expires in 15 minutes.</span>
                        </div>
                    </div>

                    <!-- STEP 2: SHIPPING & BILLING. Visible once the email is verified (or the user is logged in). -->
                    <div id="checkout-details-container" style="display: none;">
                        <h4>Shipping &amp; Billing Details</h4>

                        <!-- (a) SAVED ADDRESS CARD - existing customers only. Filled from saved data, never from typing. -->
                        <div id="akc-saved-address" class="shipping-address-preview-box" style="display: none;">
                            <div class="akc-addr-head">
                                <strong id="akc-prev-name"></strong>
                                <a href="javascript:void(0);" id="akc-toggle-edit" class="edit-address-link" role="button" tabindex="0" aria-expanded="false" aria-controls="akc-address-fields"><span id="akc-toggle-ico" aria-hidden="true">✏️</span><span id="akc-toggle-label">Edit</span></a>
                            </div>
                            <div id="akc-prev-line1"></div>
                            <div id="akc-prev-line2"></div>
                            <div>Phone: <span id="akc-prev-phone"></span></div>
                        </div>

                        <!-- (b) VERIFIED BOX - always right below the card; not part of the edit form -->
                        <div class="form-group" id="form-email-group">
                            <label for="field-email">Email address *</label>
                            <input type="email" name="billing_email" id="field-email" value="" readonly>
                            <div class="otp-verified-badge" id="akc-verified-badge">
                                <span id="akc-verified-text">✓ Email Verified</span>
                                <a href="javascript:void(0);" id="akc-change-email" class="akc-change-email" role="button" aria-label="Change email address" style="display: none;">Change</a>
                            </div>
                        </div>

                        <!-- (c) ADDRESS FORM - open for new customers; collapsed behind "Edit" for saved customers -->
                        <form id="drawer-address-form" novalidate>

                            <div style="display: none !important; visibility: hidden !important; position: absolute; left: -9999px;" aria-hidden="true">
                                <label for="field-website-url">Website URL</label>
                                <input type="text" name="website_url" id="field-website-url" value="" tabindex="-1" autocomplete="off">
                            </div>

                            <div id="akc-address-fields" style="display: none;">
                                <div class="form-row-flex">
                                    <div class="form-group">
                                        <label for="field-fname">First name *</label>
                                        <input type="text" name="billing_first_name" id="field-fname" value="" autocomplete="given-name" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="field-lname">Last name *</label>
                                        <input type="text" name="billing_last_name" id="field-lname" value="" autocomplete="family-name" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="field-country">Country / Region *</label>
                                    <input type="text" id="field-country" value="India" disabled style="background:#eee;">
                                </div>
                                <div class="form-group">
                                    <label for="field-addr1">Street address *</label>
                                    <input type="text" name="billing_address_1" id="field-addr1" placeholder="House number and street name" value="" autocomplete="address-line1" required style="margin-bottom:6px;">
                                    <input type="text" name="billing_address_2" id="field-addr2" placeholder="Apartment, suite, unit, etc. (optional)" value="" autocomplete="address-line2">
                                </div>
                                <div class="form-group">
                                    <label for="field-city">Town / City *</label>
                                    <input type="text" name="billing_city" id="field-city" value="" autocomplete="address-level2" required>
                                </div>

                                <div class="form-group searchable-select-wrapper">
                                    <label for="state-search-input">State *</label>
                                    <input type="text" id="state-search-input" class="searchable-select-input" placeholder="Type to search state..." value="" autocomplete="off" required>
                                    <input type="hidden" name="billing_state" id="billing_state_val" value="">
                                    <ul id="state-dropdown-list" class="searchable-dropdown-list"></ul>
                                </div>

                                <div class="form-group">
                                    <label for="field-postcode">PIN Code *</label>
                                    <input type="text" name="billing_postcode" id="field-postcode" maxlength="8" inputmode="numeric" autocomplete="postal-code" value="" required>
                                </div>

                                <div class="form-group">
                                    <label for="field-phone">Phone (10-digit mobile) *</label>
                                    <input type="tel" name="billing_phone" id="field-phone" maxlength="20" inputmode="numeric" autocomplete="tel-national" placeholder="e.g. 9876543210" value="" required>
                                </div>
                            </div>

                            <!-- (d) CREATE ACCOUNT / GUEST - new customers only (radio: exactly one is always selected) -->
                            <div id="akc-account-choice" class="akc-choice" role="radiogroup" aria-labelledby="akc-choice-title" style="display: none;">
                                <div id="akc-choice-title" class="akc-choice-title">How would you like to continue?</div>
                                <label class="akc-choice-option is-selected">
                                    <input type="radio" name="akc_account_choice" value="account" checked>
                                    <span class="akc-choice-text">
                                        <strong>Create an account</strong>
                                        <small>Save these details for faster checkout next time.</small>
                                    </span>
                                </label>
                                <label class="akc-choice-option">
                                    <input type="radio" name="akc_account_choice" value="guest">
                                    <span class="akc-choice-text">
                                        <strong>Checkout as guest</strong>
                                        <small>No account is created. Just this order.</small>
                                    </span>
                                </label>
                            </div>
                        </form>
                    </div>

                    <h4>Your Order</h4>
                    <div class="drawer-order-review" id="drawer-order-review-summary">
                        <?php akshara_render_drawer_order_summary(); ?>
                    </div>

                    <div class="drawer-payment-box">
                        <h4 style="border-bottom:none; margin-bottom:4px;">Payment Method</h4>
                        <label class="payment-option">
                            <input type="radio" checked name="drawer_payment"> <strong>Credit Card / Debit Card / NetBanking</strong>
                        </label>
                        <p class="payment-desc">Pay securely by Credit or Debit card or Internet Banking through Razorpay.</p>
                    </div>

                    <p class="privacy-notice">Your personal data will be used to process your order, support your experience throughout this website, and for other purposes described in our privacy policy.</p>

                    <div id="akc-order-error" class="akc-order-error" role="alert"></div>
                    <p id="akc-order-hint" class="akc-order-hint"></p>
                    <button type="button" id="drawer-place-order-btn" class="drawer-place-order-btn" disabled>Place order</button>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// Render Cart Content
function akshara_render_side_cart_content() {
    if (!WC()->cart || WC()->cart->is_empty()) {
        ?>
        <div class="side-cart-empty">
            <h3>Your Cart is Empty!</h3>
            <p>Start exploring books & more you'll love</p>
            <a href="https://paleturquoise-elk-191791.hostingersite.com/product-category/all-books/" class="shop-now-btn">Shop Now</a>
        </div>
        <?php
        return;
    }

    $min_amount = 300;
    $cart_total = WC()->cart->get_subtotal();
    $amount_left = $min_amount - $cart_total;

    echo '<div class="side-cart-shipping-bar">';
    if ($amount_left > 0) {
        $percent = min(100, round(($cart_total / $min_amount) * 100));
        echo '<p>Add ' . wc_price($amount_left) . ' more to unlock <strong>Free Shipping!</strong> <a href="javascript:void(0);" class="continue-shopping-link">Continue shopping</a></p>';
        echo '<div class="progress-bg"><div class="progress-fill" style="width: ' . $percent . '%;"></div></div>';
    } else {
        echo '<p class="free-shipping-unlocked">🎉 You unlocked <strong>Free Shipping!</strong> <a href="javascript:void(0);" class="continue-shopping-link">Continue shopping</a></p>';
    }
    echo '</div>';

    echo '<ul class="side-cart-items">';
    foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
        $_product = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);
        if ($_product && $_product->exists() && $cart_item['quantity'] > 0) {
            $product_permalink = apply_filters('woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink($cart_item) : '', $cart_item, $cart_item_key);
            ?>
            <li class="side-cart-item">
                <div class="cart-item-img"><?php echo $_product->get_image(); ?></div>
                <div class="cart-item-details">
                    <a href="<?php echo esc_url($product_permalink); ?>" class="item-title"><?php echo $_product->get_name(); ?></a>
                    <div class="item-price"><?php echo WC()->cart->get_product_price($_product); ?></div>
                    <div class="item-qty-changer">
                        <button type="button" class="qty-btn qty-minus" data-cart-key="<?php echo esc_attr($cart_item_key); ?>" data-qty="<?php echo $cart_item['quantity'] - 1; ?>">-</button>
                        <span class="qty-display"><?php echo $cart_item['quantity']; ?></span>
                        <button type="button" class="qty-btn qty-plus" data-cart-key="<?php echo esc_attr($cart_item_key); ?>" data-qty="<?php echo $cart_item['quantity'] + 1; ?>">+</button>
                    </div>
                </div>
                <a href="javascript:void(0);" class="remove-side-cart-item" data-cart-key="<?php echo esc_attr($cart_item_key); ?>" title="Remove item">&times;</a>
            </li>
            <?php
        }
    }
    echo '</ul>';

    $shipping_cost = ($cart_total < 300) ? 50.00 : 0.00;
    $grand_total = $cart_total + $shipping_cost;
    ?>
    <div class="side-cart-footer">
        <div class="side-cart-totals-list">
            <div class="totals-row"><span>Subtotal</span><strong><?php echo WC()->cart->get_cart_subtotal(); ?></strong></div>
            <div class="totals-row"><span>Shipment</span><strong><?php echo ($shipping_cost > 0) ? 'Flat rate: ₹50.00' : 'Free Shipping'; ?></strong></div>
            <div class="totals-row grand-total"><span>Total</span><strong><?php echo wc_price($grand_total); ?></strong></div>
        </div>
        <button type="button" id="proceed-to-checkout-btn" class="side-cart-checkout-btn">Express Checkout</button>
    </div>
    <?php
}

// Render Order Summary
function akshara_render_drawer_order_summary() {
    if (!WC()->cart) return;
    foreach (WC()->cart->get_cart() as $cart_item) {
        $_product = $cart_item['data'];
        echo '<div class="review-item"><span>' . $_product->get_name() . ' × ' . $cart_item['quantity'] . '</span><strong>' . wc_price($_product->get_price() * $cart_item['quantity']) . '</strong></div>';
    }
    $cart_subtotal = WC()->cart->get_subtotal();
    $shipping_cost = ($cart_subtotal < 300) ? 50.00 : 0.00;
    $grand_total = $cart_subtotal + $shipping_cost;
    ?>
    <div class="review-subtotal"><span>Subtotal</span><strong><?php echo WC()->cart->get_cart_subtotal(); ?></strong></div>
    <div class="review-shipping"><span>Shipment</span><strong><?php echo ($shipping_cost > 0) ? 'Flat rate: ₹50.00' : 'Free Shipping'; ?></strong></div>
    <div class="review-total"><span>Total</span><strong><?php echo wc_price($grand_total); ?></strong></div>
    <?php
}

// -----------------------------------------------------------------------------
// BACKEND AJAX HANDLERS
// -----------------------------------------------------------------------------

// A private "proof of verification" is kept as a hash, never as plain text.
function akshara_token_hash($token) {
    return hash_hmac('sha256', (string) $token, wp_salt('auth'));
}

// Step 1: email a 6-digit OTP. (One new code per email every 30 seconds.)
add_action('wp_ajax_akshara_send_otp', 'akshara_ajax_send_otp');
add_action('wp_ajax_nopriv_akshara_send_otp', 'akshara_ajax_send_otp');
function akshara_ajax_send_otp() {
    $email = akshara_post_email('email');
    if (!is_email($email)) {
        wp_send_json_error(array('message' => 'Please enter a valid email address first.'));
    }

    $cooldown_key = 'akshara_otp_cool_' . md5($email);
    if (get_transient($cooldown_key)) {
        wp_send_json_error(array('message' => 'Please wait a few seconds before asking for another code.'));
    }
    set_transient($cooldown_key, 1, 30);

    $otp     = (string) wp_rand(100000, 999999);
    $otp_key = 'akshara_otp_' . md5($email);
    set_transient($otp_key, array('code' => $otp, 'tries' => 0, 'exp' => time() + 900), 900);

    $site_name = get_bloginfo('name');
    $subject   = "[$site_name] Your Verification Code: $otp";
    $message   = "Hello,\n\nYour One-Time Password (OTP) is:\n\n👉  {$otp}\n\nValid for 15 minutes.";
    $headers   = array('Content-Type: text/plain; charset=UTF-8');

    global $phpmailer;
    $sent = wp_mail($email, $subject, $message, $headers);

    if ($sent) {
        wp_send_json_success(array('message' => 'OTP sent successfully to ' . $email));
    }

    // Sending failed: free the wait-timer so the customer can retry straight away
    delete_transient($cooldown_key);
    delete_transient($otp_key);
    $error_details = (isset($phpmailer->ErrorInfo) && !empty($phpmailer->ErrorInfo)) ? $phpmailer->ErrorInfo : 'Mail delivery failed.';
    wp_send_json_error(array('message' => $error_details));
}

// Step 2: check the OTP.
//   - email already has an account -> log them in and return their saved address
//   - email is new                 -> nothing is created yet; return a private token that proves
//                                     THIS browser verified the email (needed later to place the order)
add_action('wp_ajax_akshara_verify_otp', 'akshara_ajax_verify_otp');
add_action('wp_ajax_nopriv_akshara_verify_otp', 'akshara_ajax_verify_otp');
function akshara_ajax_verify_otp() {
    $email    = akshara_post_email('email');
    $user_otp = preg_replace('/\D/', '', akshara_post('otp'));

    if (!is_email($email) || strlen($user_otp) !== 6) {
        wp_send_json_error(array('message' => 'Please enter the 6-digit code.'));
    }

    $otp_key = 'akshara_otp_' . md5($email);
    $saved   = get_transient($otp_key);

    if (!is_array($saved) || empty($saved['code'])) {
        wp_send_json_error(array('message' => 'This code has expired. Please request a new OTP.', 'code' => 'otp_expired'));
    }

    if (!hash_equals((string) $saved['code'], (string) $user_otp)) {
        $saved['tries'] = (isset($saved['tries']) ? (int) $saved['tries'] : 0) + 1;

        // After 5 wrong guesses the code is destroyed (stops OTP guessing)
        if ($saved['tries'] >= 5) {
            delete_transient($otp_key);
            wp_send_json_error(array('message' => 'Too many wrong attempts. Please request a new OTP.', 'code' => 'otp_locked'));
        }

        $remaining = isset($saved['exp']) ? max(1, (int) $saved['exp'] - time()) : 900;
        set_transient($otp_key, $saved, $remaining);
        wp_send_json_error(array('message' => 'Incorrect code. Please check and try again.'));
    }

    // Correct code: it can never be used a second time
    delete_transient($otp_key);

    $existing_user = get_user_by('email', $email);

    if ($existing_user) {
        wp_set_current_user($existing_user->ID);
        wp_set_auth_cookie($existing_user->ID);

        wp_send_json_success(array(
            'user_exists' => true,
            'email'       => $existing_user->user_email,
            'address'     => akshara_get_saved_address($existing_user->ID),
        ));
    }

    $token = wp_generate_password(32, false, false);
    set_transient('akshara_vtoken_' . md5($email), akshara_token_hash($token), 1800);

    wp_send_json_success(array(
        'user_exists' => false,
        'email'       => $email,
        'token'       => $token,
    ));
}

add_action('wp_ajax_update_side_cart', 'akshara_ajax_side_cart_refresh');
add_action('wp_ajax_nopriv_update_side_cart', 'akshara_ajax_side_cart_refresh');
function akshara_ajax_side_cart_refresh() {
    ob_start();
    akshara_render_side_cart_content();
    $cart_html = ob_get_clean();

    ob_start();
    akshara_render_drawer_order_summary();
    $order_summary_html = ob_get_clean();

    wp_send_json_success(array(
        'html' => $cart_html,
        'order_summary' => $order_summary_html,
        'count' => WC()->cart ? WC()->cart->get_cart_contents_count() : 0
    ));
}

add_action('wp_ajax_update_side_cart_qty', 'akshara_ajax_update_side_cart_qty');
add_action('wp_ajax_nopriv_update_side_cart_qty', 'akshara_ajax_update_side_cart_qty');
function akshara_ajax_update_side_cart_qty() {
    $cart_key = sanitize_text_field($_POST['cart_key']);
    $new_qty = intval($_POST['quantity']);
    if ($cart_key) {
        if ($new_qty > 0) {
            WC()->cart->set_quantity($cart_key, $new_qty);
        } else {
            WC()->cart->remove_cart_item($cart_key);
        }
        WC()->cart->calculate_totals();
    }
    akshara_ajax_side_cart_refresh();
}

add_action('wp_ajax_remove_side_cart_item', 'akshara_ajax_remove_side_cart_item');
add_action('wp_ajax_nopriv_remove_side_cart_item', 'akshara_ajax_remove_side_cart_item');
function akshara_ajax_remove_side_cart_item() {
    $cart_key = sanitize_text_field($_POST['cart_key']);
    if ($cart_key && WC()->cart->remove_cart_item($cart_key)) {
        WC()->cart->calculate_totals();
        akshara_ajax_side_cart_refresh();
    } else {
        wp_send_json_error();
    }
}

// Backend Order Creation Handler.
//   - logged-in customer          -> order on their account, saved address updated
//   - new customer + "Create an account" -> account is created now, customer is logged in
//   - new customer + "Checkout as guest" -> guest order, NO account is created
add_action('wp_ajax_akshara_create_order', 'akshara_ajax_create_order');
add_action('wp_ajax_nopriv_akshara_create_order', 'akshara_ajax_create_order');
function akshara_ajax_create_order() {
    if (!WC()->cart || WC()->cart->is_empty()) {
        wp_send_json_error(array('message' => 'Your cart is empty.'));
    }

    $fname    = akshara_post('fname');
    $lname    = akshara_post('lname');
    $addr1    = akshara_post('addr1');
    $addr2    = akshara_post('addr2');
    $city     = akshara_post('city');
    $state    = akshara_normalize_state(akshara_post('state'));
    $country  = 'IN';
    $postcode = preg_replace('/\s+/', '', akshara_post('postcode'));
    $phone    = akshara_normalize_phone(akshara_post('phone'));
    $choice   = (akshara_post('choice') === 'guest') ? 'guest' : 'account'; // 'account' or 'guest'

    $is_logged_in = is_user_logged_in();
    $user_id      = $is_logged_in ? get_current_user_id() : 0;
    // A logged-in customer always orders with their own account email
    $email        = $is_logged_in ? wp_get_current_user()->user_email : akshara_post_email('email');

    if ($fname === '' || $lname === '' || $addr1 === '' || $city === '') {
        wp_send_json_error(array('message' => 'Please fill in all the required address details.'));
    }
    if ($state === '') {
        wp_send_json_error(array('message' => 'Please select your state from the list.'));
    }
    if (!preg_match('/^[1-9][0-9]{5}$/', $postcode)) {
        wp_send_json_error(array('message' => 'Please enter a valid 6-digit PIN code.'));
    }
    if (!preg_match('/^[6-9][0-9]{9}$/', $phone)) {
        wp_send_json_error(array('message' => 'Please enter a valid 10-digit mobile number.'));
    }
    if (!is_email($email)) {
        wp_send_json_error(array('message' => 'Please enter a valid email address.'));
    }

    // Visitors must have verified this email by OTP in THIS browser
    if (!$is_logged_in) {
        $token  = akshara_post('token');
        $stored = get_transient('akshara_vtoken_' . md5($email));

        if ($token === '' || !is_string($stored) || !hash_equals($stored, akshara_token_hash($token))) {
            wp_send_json_error(array(
                'message' => 'Your email verification has expired. Please verify your email again.',
                'code'    => 'verification_required',
            ));
        }

        // Rare: an account for this email appeared after verification. Never create a duplicate.
        if ($choice === 'account' && email_exists($email)) {
            wp_send_json_error(array(
                'message' => 'An account already exists for this email. Please verify it again to log in.',
                'code'    => 'account_exists',
            ));
        }
    }

    $address_meta = array(
        'first_name'           => $fname,
        'last_name'            => $lname,
        'billing_first_name'   => $fname,
        'billing_last_name'    => $lname,
        'billing_address_1'    => $addr1,
        'billing_address_2'    => $addr2,
        'billing_city'         => $city,
        'billing_state'        => $state,
        'billing_country'      => $country,
        'billing_postcode'     => $postcode,
        'billing_phone'        => $phone,
        'billing_email'        => $email,
        'shipping_first_name'  => $fname,
        'shipping_last_name'   => $lname,
        'shipping_address_1'   => $addr1,
        'shipping_address_2'   => $addr2,
        'shipping_city'        => $city,
        'shipping_state'       => $state,
        'shipping_country'     => $country,
        'shipping_postcode'    => $postcode,
        'shipping_phone'       => $phone,
    );

    // "Create an account" was ticked: create it now and log the customer in
    $account_created = false;
    if (!$is_logged_in && $choice === 'account') {
        $new_user_id = akshara_create_customer($email, $fname, $lname);
        if (is_wp_error($new_user_id)) {
            wp_send_json_error(array('message' => 'We could not create your account. Please try again, or choose "Checkout as guest".'));
        }
        $user_id         = $new_user_id;
        $account_created = true;
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
    }

    // Remember the address on the account (logged-in customers and freshly created accounts only)
    if ($user_id) {
        foreach ($address_meta as $k => $v) {
            update_user_meta($user_id, $k, wp_slash($v));
        }
    }

    $address = array(
        'first_name' => $fname, 'last_name' => $lname, 'email' => $email,
        'phone' => $phone, 'address_1' => $addr1, 'address_2' => $addr2,
        'city' => $city, 'state' => $state, 'country' => $country, 'postcode' => $postcode
    );

    try {
        $order = wc_create_order(array('customer_id' => $user_id));
        foreach (WC()->cart->get_cart() as $values) {
            $order->add_product($values['data'], $values['quantity']);
        }
        $order->set_address($address, 'billing');
        $order->set_address($address, 'shipping');

        if (WC()->cart->get_subtotal() < 300) {
            $shipping_item = new WC_Order_Item_Shipping();
            $shipping_item->set_method_title('Flat rate');
            $shipping_item->set_method_id('flat_rate');
            $shipping_item->set_total(50);
            $order->add_item($shipping_item);
        }

        $order->calculate_totals();
        $order->update_status('pending', 'Awaiting Razorpay Payment');

        wp_send_json_success(array(
            'order_id'          => $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'razorpay_order_id' => '',
            'amount'            => (int) round($order->get_total() * 100),
            'currency'          => 'INR',
            'account_created'   => $account_created,
        ));
    } catch (Exception $e) {
        wp_send_json_error(array('message' => $e->getMessage()));
    }
}

add_action('wp_ajax_akshara_load_order_to_cart', 'akshara_ajax_load_order_to_cart');
add_action('wp_ajax_nopriv_akshara_load_order_to_cart', 'akshara_ajax_load_order_to_cart');
function akshara_ajax_load_order_to_cart() {
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $order = wc_get_order($order_id);
    if (!$order) wp_send_json_error(array('message' => 'Order not found'));

    WC()->cart->empty_cart();
    foreach ($order->get_items() as $item) {
        WC()->cart->add_to_cart($item->get_product_id(), $item->get_quantity(), $item->get_variation_id());
    }
    WC()->cart->calculate_totals();

    ob_start(); akshara_render_side_cart_content(); $cart_html = ob_get_clean();
    ob_start(); akshara_render_drawer_order_summary(); $summary_html = ob_get_clean();

    wp_send_json_success(array('html' => $cart_html, 'order_summary' => $summary_html, 'count' => WC()->cart->get_cart_contents_count(), 'order_id' => $order_id));
}

add_filter('woocommerce_add_to_cart_fragments', function($fragments) {
    $count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
    $fragments['span.akshara-cart-count'] = '<span class="cart-badge akshara-cart-count side-cart-count">' . $count . '</span>';
    return $fragments;
});

// 3. Frontend JQuery Handler
add_action('wp_footer', 'akshara_checkout_ajax_script', 99);
function akshara_checkout_ajax_script() {
    if (is_cart() || is_checkout() || is_page('quick-checkout')) return;
    ?>
    <script type="text/javascript">
    jQuery(function ($) {
        'use strict';

        // =====================================================================
        //  SETTINGS
        // =====================================================================
        var CFG       = window.aksharaCheckoutConfig || {};
        var AJAX_URL  = CFG.ajaxUrl || '';
        var STATES    = CFG.states || {};
        var STORE_KEY = 'akshara_checkout_v2';
        var STORE_TTL = 28 * 60 * 1000;            // the server remembers a verification for 30 minutes
        var EMAIL_RE  = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        var PHONE_RE  = /^[6-9]\d{9}$/;
        var PIN_RE    = /^[1-9]\d{5}$/;
        var REDUCE    = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

        if (!$('#akc-login-card').length) { return; }   // drawer is not on this page

        // =====================================================================
        //  STATE - the ONE place that decides what the checkout box looks like.
        //  Change a value here, call render(), and the screen follows.
        // =====================================================================
        var S = {
            loggedIn:   !!CFG.loggedIn,                       // logged in to WordPress
            verified:   !!CFG.loggedIn,                       // email proven by OTP (always true when logged in)
            email:      CFG.loggedIn ? (CFG.email || '') : '',
            token:      '',                                   // proof of verification (visitors only)
            verifiedAt: 0,
            choice:     'account',                            // new customer: 'account' (default) or 'guest'
            hasSaved:   false,                                // a complete saved address exists
            editing:    false,                                // saved customer has opened the edit form
            otpEmail:   '',                                   // the email the current OTP was sent to
            busy:       false                                 // an order is being placed
        };
        var cooldownTimer = null;

        // =====================================================================
        //  SMALL HELPERS
        // =====================================================================
        function val(id) { return $.trim($('#' + id).val() || ''); }

        // Show / hide an element (with a short slide when something changes because of a click)
        function setShown($el, show, animate) {
            $el.stop(true, true);
            var hidden = $el.css('display') === 'none';
            if (show && hidden) {
                if (animate && !REDUCE) { $el.slideDown(220); } else { $el.show(); }
            } else if (!show && !hidden) {
                if (animate && !REDUCE) { $el.slideUp(200); } else { $el.hide(); }
            }
        }

        function setStatus(sel, type, text) {
            $(sel).removeClass('is-ok is-err').addClass(type ? 'is-' + type : '').text(text || '');
        }

        // Remember "email verified" for this browser tab only (so a page change doesn't ask for the OTP again)
        function readStore()  { try { return JSON.parse(sessionStorage.getItem(STORE_KEY) || 'null'); } catch (e) { return null; } }
        function clearStore() { try { sessionStorage.removeItem(STORE_KEY); } catch (e) {} }
        function saveStore() {
            if (S.loggedIn || !S.verified || !S.token) { return; }
            try {
                sessionStorage.setItem(STORE_KEY, JSON.stringify({ email: S.email, token: S.token, choice: S.choice, ts: S.verifiedAt }));
            } catch (e) {}
        }

        // =====================================================================
        //  FORM VALUES & VALIDATION
        // =====================================================================
        function readForm() {
            return {
                fname:    val('field-fname'),
                lname:    val('field-lname'),
                addr1:    val('field-addr1'),
                addr2:    val('field-addr2'),
                city:     val('field-city'),
                state:    $.trim($('#billing_state_val').val() || ''),
                postcode: val('field-postcode'),
                phone:    val('field-phone')
            };
        }

        var FIELD_ORDER = ['field-fname', 'field-lname', 'field-addr1', 'field-city', 'state-search-input', 'field-postcode', 'field-phone'];
        var RULES = {
            'field-fname':        { msg: 'Enter your first name.',                ok: function () { return val('field-fname') !== ''; } },
            'field-lname':        { msg: 'Enter your last name.',                 ok: function () { return val('field-lname') !== ''; } },
            'field-addr1':        { msg: 'Enter your street address.',            ok: function () { return val('field-addr1') !== ''; } },
            'field-city':         { msg: 'Enter your town / city.',               ok: function () { return val('field-city') !== ''; } },
            'state-search-input': { msg: 'Pick your state from the list.',        ok: function () { return !!STATES[$('#billing_state_val').val()]; } },
            'field-postcode':     { msg: 'Enter a valid 6-digit PIN code.',       ok: function () { return PIN_RE.test(val('field-postcode')); } },
            'field-phone':        { msg: 'Enter a valid 10-digit mobile number.', ok: function () { return PHONE_RE.test(val('field-phone')); } }
        };

        function showFieldError(id, msg) {
            var $input = $('#' + id);
            var $group = $input.closest('.form-group');
            var $note  = $group.find('.akc-err[data-for="' + id + '"]');
            if (!$note.length) {
                $note = $('<span class="field-error-notice akc-err"></span>').attr('data-for', id).appendTo($group);
            }
            $note.text(msg || '').toggleClass('is-on', !!msg);
            $input.toggleClass('akc-invalid', !!msg);
        }

        function checkField(id, show) {
            var ok = RULES[id].ok();
            if (show) { showFieldError(id, ok ? '' : RULES[id].msg); }
            return ok;
        }

        function firstInvalidId() {
            for (var i = 0; i < FIELD_ORDER.length; i++) {
                if (!RULES[FIELD_ORDER[i]].ok()) { return FIELD_ORDER[i]; }
            }
            return '';
        }

        function clearFieldErrors() {
            $('#drawer-address-form .akc-err').removeClass('is-on').text('');
            $('#drawer-address-form input').removeClass('akc-invalid').removeData('akcTouched');
        }

        // Keep phone / PIN to digits only ("+91 98765 43210" becomes 9876543210)
        function sanitizePhone(el) {
            var d = el.value.replace(/\D/g, '');
            if (d.length === 12 && d.indexOf('91') === 0) { d = d.slice(2); }
            else if (d.length === 11 && d.charAt(0) === '0') { d = d.slice(1); }
            d = d.slice(0, 10);
            if (el.value !== d) { el.value = d; }
        }
        function sanitizePin(el) {
            var d = el.value.replace(/\D/g, '').slice(0, 6);
            if (el.value !== d) { el.value = d; }
        }

        // =====================================================================
        //  STATE DROPDOWN (searchable)
        // =====================================================================
        function buildStateList() {
            var $ul = $('#state-dropdown-list').empty();
            $.each(STATES, function (code, name) {
                $('<li>').attr('data-val', code).text(name).appendTo($ul);
            });
        }
        function filterStates(term) {
            term = $.trim(term || '').toLowerCase();
            $('#state-dropdown-list li').each(function () {
                $(this).toggle(!term || $(this).text().toLowerCase().indexOf(term) > -1);
            });
        }
        function stateByName(name) {
            name = $.trim(name || '').toLowerCase();
            var found = '';
            $.each(STATES, function (code, n) {
                if (String(n).toLowerCase() === name) { found = code; return false; }
            });
            return found;
        }
        function setState(code) {
            if (code && STATES[code]) {
                $('#billing_state_val').val(code);
                $('#state-search-input').val(STATES[code]);
            } else {
                $('#billing_state_val').val('');
                $('#state-search-input').val('');
            }
        }
        function pickState(code) {
            setState(code);
            $('#state-dropdown-list').hide();
            showFieldError('state-search-input', '');
            updatePlaceOrder();
        }

        // =====================================================================
        //  SAVED ADDRESS CARD
        //  It is written in exactly TWO places: applyAddress() (saved data) and
        //  renderPreview() (when the customer presses "Show less").
        //  No typing event ever touches it, so it can never "live reflect".
        // =====================================================================
        function renderPreview() {
            var f         = readForm();
            var stateName = STATES[f.state] || '';
            var line1     = f.addr1 + (f.addr2 ? ', ' + f.addr2 : '');
            var line2     = f.city + (stateName ? ', ' + stateName : '') + (f.postcode ? ' - ' + f.postcode : '');
            $('#akc-prev-name').text($.trim(f.fname + ' ' + f.lname));
            $('#akc-prev-line1').text(line1);
            $('#akc-prev-line2').text(line2);
            $('#akc-prev-phone').text(f.phone);
        }

        // Load a saved address into the form + card
        function applyAddress(a) {
            a = a || {};
            $('#field-fname').val(a.fname || '');
            $('#field-lname').val(a.lname || '');
            $('#field-addr1').val(a.addr1 || '');
            $('#field-addr2').val(a.addr2 || '');
            $('#field-city').val(a.city || '');
            setState(a.state || '');
            $('#field-postcode').val(a.postcode || '');
            $('#field-phone').val(a.phone || '');
            clearFieldErrors();
            S.hasSaved = !!a.complete;     // card only appears when the saved address is complete
            S.editing  = false;
            if (S.hasSaved) { renderPreview(); }
        }

        function toggleEdit() {
            if (!S.hasSaved) { return; }

            if (!S.editing) {                       // Edit -> open the form (the card stays visible)
                S.editing = true;
                render(true);
                return;
            }

            // Show less -> close the form, but never with missing / invalid details
            var firstBad = firstInvalidId();
            if (firstBad) {
                $.each(FIELD_ORDER, function (i, id) { checkField(id, true); });
                $('#' + firstBad).trigger('focus');
                return;
            }
            renderPreview();                        // the card now shows what will be used for this order
            S.editing = false;
            render(true);
        }

        // =====================================================================
        //  RENDER - turns the state into what is visible on screen
        //
        //   Visitor, email not verified : login box only
        //   Existing customer           : saved card -> verified box -> (form after Edit)
        //   New customer                : verified box -> form -> [Create account | Guest]
        // =====================================================================
        function render(animate) {
            setShown($('#akc-login-card'),             !S.verified,                                animate);
            setShown($('#checkout-details-container'),  S.verified,                                animate);
            setShown($('#akc-saved-address'),           S.verified && S.hasSaved,                  animate);
            setShown($('#akc-address-fields'),          S.verified && (!S.hasSaved || S.editing),  animate);
            setShown($('#akc-account-choice'),          S.verified && !S.loggedIn,                 animate);
            $('#akc-change-email').toggle(S.verified && !S.loggedIn);

            // Edit <-> Show less
            $('#akc-toggle-edit').attr('aria-expanded', S.editing ? 'true' : 'false');
            $('#akc-toggle-label').text(S.editing ? 'Show less' : 'Edit');
            $('#akc-toggle-ico').text(S.editing ? '▲' : '✏️');

            // Verified box
            $('#field-email').val(S.email);
            $('#akc-verified-text').text(S.loggedIn ? '✓ Logged in & Verified' : '✓ Email Verified');

            // Create account / guest radios (exactly one is always selected)
            $('input[name="akc_account_choice"]').each(function () {
                var on = (this.value === S.choice);
                this.checked = on;
                $(this).closest('.akc-choice-option').toggleClass('is-selected', on);
            });

            updatePlaceOrder();
        }

        // Enable "Place order" only when everything is valid, and say what is missing
        function updatePlaceOrder() {
            if (S.busy) { return; }
            var hint = '';
            if (!S.verified) {
                hint = 'Verify your email to continue.';
            } else if (!EMAIL_RE.test(S.email)) {
                hint = 'Enter a valid email address.';
            } else {
                var bad = firstInvalidId();
                if (bad) { hint = RULES[bad].msg; }
            }
            $('#drawer-place-order-btn').prop('disabled', !!hint);
            $('#akc-order-hint').text(hint).toggle(!!hint);
        }

        // =====================================================================
        //  STEP 1 - EMAIL OTP (login / create account)
        // =====================================================================
        function stopCooldown() {
            if (cooldownTimer) { clearInterval(cooldownTimer); cooldownTimer = null; }
        }
        function startCooldown(seconds) {
            var $btn = $('#akc-send-otp-btn');
            var left = seconds;
            stopCooldown();
            $btn.prop('disabled', true).text('Resend in ' + left + 's');
            cooldownTimer = setInterval(function () {
                left--;
                if (left <= 0) {
                    stopCooldown();
                    $btn.prop('disabled', false).text('Resend OTP');
                } else {
                    $btn.text('Resend in ' + left + 's');
                }
            }, 1000);
        }

        function resetOtpUi() {
            stopCooldown();
            S.otpEmail = '';
            $('#akc-otp-input').val('');
            setShown($('#akc-otp-wrapper'), false, false);
            setStatus('#akc-otp-status', '', '');
            setStatus('#akc-verify-status', '', 'Code expires in 15 minutes.');
            $('#akc-email-error').removeClass('is-on');
            $('#akc-send-otp-btn').prop('disabled', false).text('Send OTP');
            $('#akc-verify-otp-btn').prop('disabled', false).text('Verify');
        }

        function sendOtp() {
            var $btn  = $('#akc-send-otp-btn');
            var email = $.trim($('#akc-email-input').val()).toLowerCase();
            if ($btn.prop('disabled')) { return; }

            if (!EMAIL_RE.test(email)) {
                $('#akc-email-error').addClass('is-on');
                return;
            }
            $('#akc-email-error').removeClass('is-on');
            setStatus('#akc-otp-status', '', '');
            $btn.prop('disabled', true).text('Sending...');

            $.ajax({ type: 'POST', url: AJAX_URL, dataType: 'json', data: { action: 'akshara_send_otp', email: email } })
                .done(function (res) {
                    if (res && res.success) {
                        // The email was edited while the request was running: ignore this answer
                        if ($.trim($('#akc-email-input').val()).toLowerCase() !== email) {
                            $btn.prop('disabled', false).text('Send OTP');
                            return;
                        }
                        S.otpEmail = email;
                        $('#akc-otp-input').val('');
                        setStatus('#akc-verify-status', '', 'Code expires in 15 minutes.');
                        setShown($('#akc-otp-wrapper'), true, true);
                        setStatus('#akc-otp-status', 'ok', 'OTP sent to ' + email);
                        startCooldown(30);
                        $('#akc-otp-input').trigger('focus');
                    } else {
                        setStatus('#akc-otp-status', 'err', (res && res.data && res.data.message) || 'Could not send the OTP. Please try again.');
                        $btn.prop('disabled', false).text(S.otpEmail ? 'Resend OTP' : 'Send OTP');
                    }
                })
                .fail(function () {
                    setStatus('#akc-otp-status', 'err', 'Network problem. Please check your connection and try again.');
                    $btn.prop('disabled', false).text(S.otpEmail ? 'Resend OTP' : 'Send OTP');
                });
        }

        function verifyOtp() {
            var $btn = $('#akc-verify-otp-btn');
            var otp  = $.trim($('#akc-otp-input').val());
            if ($btn.prop('disabled')) { return; }

            if (!S.otpEmail) {
                setStatus('#akc-verify-status', 'err', 'Please request an OTP first.');
                return;
            }
            if (!/^\d{6}$/.test(otp)) {
                setStatus('#akc-verify-status', 'err', 'Enter the 6-digit code from your email.');
                return;
            }
            $btn.prop('disabled', true).text('Verifying...');

            $.ajax({ type: 'POST', url: AJAX_URL, dataType: 'json', data: { action: 'akshara_verify_otp', email: S.otpEmail, otp: otp } })
                .done(function (res) {
                    if (res && res.success) {
                        onVerified(res.data || {});
                        return;
                    }
                    setStatus('#akc-verify-status', 'err', (res && res.data && res.data.message) || 'Invalid OTP. Please try again.');
                    $btn.prop('disabled', false).text('Verify');
                })
                .fail(function () {
                    setStatus('#akc-verify-status', 'err', 'Network problem. Please try again.');
                    $btn.prop('disabled', false).text('Verify');
                });
        }

        // The OTP was correct
        function onVerified(d) {
            var email = d.email || S.otpEmail;
            resetOtpUi();

            S.verified   = true;
            S.email      = email;
            S.verifiedAt = Date.now();

            if (d.user_exists) {
                // EXISTING customer: the server has logged them in -> saved card + verified box
                S.loggedIn = true;
                S.token    = '';
                window.aksharaIsLoggedIn = true;
                clearStore();
                applyAddress(d.address || {});
            } else {
                // NEW customer: email verified, nothing saved yet -> verified box + form + radios
                // (anything they already typed is kept, e.g. after re-verifying an expired code)
                S.loggedIn = false;
                S.token    = d.token || '';
                S.hasSaved = false;
                S.editing  = false;
                saveStore();
            }
            render(true);

            // Jump to the first thing that still needs filling in
            setTimeout(function () {
                var id = firstInvalidId();
                if (id && S.verified) { $('#' + id).trigger('focus'); }
            }, 260);
        }

        // Back to the login box (wrong email, expired verification, ...)
        function resetToLogin(message, prefillEmail) {
            var email = prefillEmail || S.email || '';
            S.verified   = false;
            S.loggedIn   = false;
            S.token      = '';
            S.verifiedAt = 0;
            S.hasSaved   = false;
            S.editing    = false;
            S.email      = '';
            clearStore();
            resetOtpUi();
            $('#akc-email-input').val(email);
            if (message) { setStatus('#akc-otp-status', 'err', message); }
            render(true);
            setTimeout(function () {
                var el = document.getElementById('akc-login-card');
                if (el && el.scrollIntoView) { el.scrollIntoView({ behavior: REDUCE ? 'auto' : 'smooth', block: 'center' }); }
            }, 260);
        }

        // =====================================================================
        //  PLACE ORDER
        // =====================================================================
        function showOrderError(msg) {
            $('#akc-order-error').text(msg || '').toggle(!!msg);
        }

        function finishBusy($btn, label) {
            S.busy = false;
            $btn.text(label);
            updatePlaceOrder();
        }

        function onOrderCreated(d, $btn, label) {
            if (d.account_created) {            // "Create an account" was ticked: they are logged in now
                S.loggedIn = true;
                S.token    = '';
                window.aksharaIsLoggedIn = true;
                render(false);
            }
            clearStore();

            if (typeof Razorpay !== 'undefined' && window.aksharaRzpKey) {
                var options = {
                    "key": window.aksharaRzpKey,
                    "amount": d.amount,
                    "currency": d.currency,
                    "order_id": d.razorpay_order_id,
                    "handler": function (payResponse) {
                        window.location.href = window.location.origin + '/checkout/order-received/' + d.order_id + '/?key=' + d.order_key;
                    },
                    "modal": { "ondismiss": function () { finishBusy($btn, label); } }
                };
                var rzp = new Razorpay(options);
                rzp.open();
            } else {
                alert('Order placed successfully!');
                location.reload();
            }
        }

        function onOrderFailed(d, $btn, label) {
            d = d || {};
            finishBusy($btn, label);
            if (d.code === 'verification_required') {
                resetToLogin('Your email verification has expired. Please verify your email again.');
            } else if (d.code === 'account_exists') {
                resetToLogin('An account already exists for this email. Verify it again to log in.');
            } else {
                showOrderError(d.message || 'We could not place your order. Please try again.');
            }
        }

        // =====================================================================
        //  EVENTS
        // =====================================================================

        // --- Step 1: login box ---
        $(document).on('click', '#akc-send-otp-btn', sendOtp);
        $(document).on('click', '#akc-verify-otp-btn', verifyOtp);
        $(document).on('keydown', '#akc-email-input', function (e) {
            if (e.which === 13) { e.preventDefault(); sendOtp(); }
        });
        $(document).on('keydown', '#akc-otp-input', function (e) {
            if (e.which === 13) { e.preventDefault(); verifyOtp(); }
        });
        $(document).on('input', '#akc-otp-input', function () {
            var d = this.value.replace(/\D/g, '').slice(0, 6);
            if (this.value !== d) { this.value = d; }
        });
        // Changing the email after an OTP was sent starts that step over (so the OTP never mismatches)
        $(document).on('input', '#akc-email-input', function () {
            $('#akc-email-error').removeClass('is-on');
            if (S.otpEmail && $.trim(this.value).toLowerCase() !== S.otpEmail) { resetOtpUi(); }
        });

        // "Change" link inside the verified box (new customers only)
        $(document).on('click', '#akc-change-email', function (e) {
            e.preventDefault();
            resetToLogin('', S.email);
        });

        // --- Saved address card: Edit <-> Show less ---
        $(document).on('click', '#akc-toggle-edit', function (e) { e.preventDefault(); toggleEdit(); });
        $(document).on('keydown', '#akc-toggle-edit', function (e) {
            if (e.which === 13 || e.which === 32) { e.preventDefault(); toggleEdit(); }
        });

        // --- Create account / checkout as guest ---
        $(document).on('change', 'input[name="akc_account_choice"]', function () {
            S.choice = (this.value === 'guest') ? 'guest' : 'account';
            $('.akc-choice-option').each(function () {
                $(this).toggleClass('is-selected', $(this).find('input').prop('checked'));
            });
            saveStore();
        });

        // --- Address form ---
        $(document).on('submit', '#drawer-address-form', function (e) { e.preventDefault(); });

        // input / change / keyup also catch browser autofill
        $(document).on('input change keyup', '#drawer-address-form input', function (e) {
            var id = this.id;
            if (e.type === 'input') { $(this).data('akcTouched', true); }   // "touched" = the customer typed here
            if (id === 'field-phone')         { sanitizePhone(this); }
            else if (id === 'field-postcode') { sanitizePin(this); }
            if (RULES[id] && $(this).hasClass('akc-invalid') && RULES[id].ok()) { showFieldError(id, ''); }
            updatePlaceOrder();
        });
        // Required-field errors only appear for fields the customer has typed in (no red boxes on untouched fields)
        $(document).on('blur', '#drawer-address-form input', function () {
            if (RULES[this.id] && this.id !== 'state-search-input' && $(this).data('akcTouched')) { checkField(this.id, true); }
        });

        // --- State dropdown ---
        $(document).on('focus', '#state-search-input', function () {
            filterStates('');
            $('#state-dropdown-list').show();
        });
        $(document).on('click', '#state-search-input', function () {
            $('#state-dropdown-list').show();
        });
        $(document).on('input', '#state-search-input', function () {
            var text = $(this).val();
            var exact = stateByName(text);
            $('#billing_state_val').val(exact);          // typed text is only a choice once it matches a state
            filterStates(text);
            $('#state-dropdown-list').show();
            if (exact) { showFieldError('state-search-input', ''); }
            updatePlaceOrder();
        });
        $(document).on('keydown', '#state-search-input', function (e) {
            if (e.which === 27) { $('#state-dropdown-list').hide(); }
            if (e.which === 13) {
                e.preventDefault();
                var $first = $('#state-dropdown-list li:visible').first();
                if ($first.length) { pickState($first.attr('data-val')); }
            }
        });
        $(document).on('mousedown', '#state-dropdown-list li', function (e) { e.preventDefault(); });  // keep focus in the input
        $(document).on('click', '#state-dropdown-list li', function () { pickState($(this).attr('data-val')); });
        $(document).on('blur', '#state-search-input', function () {
            setTimeout(function () {
                var $in = $('#state-search-input');
                if ($in.is(':focus')) { return; }
                var code = $('#billing_state_val').val();
                if (!code && $.trim($in.val())) {
                    var $vis = $('#state-dropdown-list li:visible');
                    if ($vis.length === 1) { code = $vis.attr('data-val'); }
                }
                if (code) {
                    pickState(code);
                } else {
                    $('#state-dropdown-list').hide();
                    if ($in.data('akcTouched')) { checkField('state-search-input', true); }
                }
            }, 120);
        });

        // --- Place order ---
        $(document).on('click', '#drawer-place-order-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            if (S.busy || $btn.prop('disabled')) { return; }
            if (!S.verified || firstInvalidId()) { updatePlaceOrder(); return; }   // safety net

            var label = $btn.text();
            S.busy = true;
            $btn.prop('disabled', true).text('Processing...');
            showOrderError('');

            var f = readForm();
            $.ajax({
                type: 'POST',
                url: AJAX_URL,
                dataType: 'json',
                data: {
                    action:   'akshara_create_order',
                    fname:    f.fname,
                    lname:    f.lname,
                    addr1:    f.addr1,
                    addr2:    f.addr2,
                    city:     f.city,
                    state:    f.state,
                    postcode: f.postcode,
                    phone:    f.phone,
                    email:    S.email,
                    choice:   S.choice,        // 'account' or 'guest' (ignored for logged-in customers)
                    token:    S.token
                }
            })
            .done(function (res) {
                if (res && res.success) { onOrderCreated(res.data || {}, $btn, label); }
                else { onOrderFailed(res && res.data, $btn, label); }
            })
            .fail(function () {
                onOrderFailed({ message: 'Server error. Please check your connection and try again.' }, $btn, label);
            });
        });

        // =====================================================================
        //  START-UP
        // =====================================================================
        buildStateList();
        try { sessionStorage.removeItem('akshara_verified_email'); sessionStorage.removeItem('akshara_auth_mode'); } catch (e) {}   // old version's keys

        if (S.loggedIn) {
            clearStore();
            applyAddress(CFG.address || {});
        } else {
            var saved = readStore();
            if (saved && saved.email && saved.token && (Date.now() - (saved.ts || 0)) < STORE_TTL) {
                S.verified   = true;                 // same browser tab, verified a few minutes ago
                S.email      = saved.email;
                S.token      = saved.token;
                S.verifiedAt = saved.ts;
                S.choice     = (saved.choice === 'guest') ? 'guest' : 'account';
            } else {
                clearStore();
            }
        }
        render(false);

        // Catches browser autofill that fills fields silently in the background
        setInterval(function () { if (S.verified) { updatePlaceOrder(); } }, 1000);
    });
    </script>
    <?php
}
