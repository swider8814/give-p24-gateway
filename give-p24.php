<?php
/**
 * Plugin Name: Give Przelewy24 Gateway
 * Description: Przelewy24 payment gateway for GiveWP/Give donations.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.2
 * Text Domain: give-p24
 */

if (!defined('ABSPATH')) {
    exit;
}

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\PaymentGateway;

const GIVE_P24_OPTION = 'give_p24_options';

add_action('plugins_loaded', static function () {
    load_plugin_textdomain('give-p24', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

add_filter('give_get_sections_gateways', static function (array $sections): array {
    $sections['przelewy24'] = __('Przelewy24', 'give-p24');

    return $sections;
});

add_filter('give_get_settings_gateways', static function (array $settings): array {
    if (!function_exists('give_get_current_setting_section') || give_get_current_setting_section() !== 'przelewy24') {
        return $settings;
    }

    return give_p24_give_settings();
});

add_filter('give_admin_field_get_value', 'give_p24_get_give_setting_value', 10, 4);
add_filter('give_admin_settings_sanitize_option_' . GIVE_P24_OPTION, 'give_p24_sanitize_give_setting_value', 10, 3);
add_filter('give_save_options_gateways_przelewy24', '__return_false');
add_action('give_update_options_gateways_przelewy24', 'give_p24_save_give_settings');
add_action('admin_init', 'give_p24_handle_test_access');
add_action('give_admin_field_give_p24_test_access', 'give_p24_render_test_access_field', 10, 2);

function give_p24_default_options(): array
{
    return [
        'mode' => 'sandbox',
        'merchant_id' => '',
        'pos_id' => '',
        'api_key' => '',
        'crc_key' => '',
    ];
}

function give_p24_options(): array
{
    return array_merge(give_p24_default_options(), (array) get_option(GIVE_P24_OPTION, []));
}

function give_p24_sanitize_options($input): array
{
    $input = (array) $input;
    $current = give_p24_options();

    return [
        'mode' => (($input['mode'] ?? 'sandbox') === 'production') ? 'production' : 'sandbox',
        'merchant_id' => preg_replace('/\D+/', '', (string) ($input['merchant_id'] ?? '')),
        'pos_id' => preg_replace('/\D+/', '', (string) ($input['pos_id'] ?? '')),
        'api_key' => ($input['api_key'] ?? '') === '' ? $current['api_key'] : sanitize_text_field($input['api_key']),
        'crc_key' => ($input['crc_key'] ?? '') === '' ? $current['crc_key'] : sanitize_text_field($input['crc_key']),
    ];
}

function give_p24_give_settings(): array
{
    $options = give_p24_options();

    return [
        [
            'id' => 'give_p24_settings',
            'type' => 'title',
            'title' => __('Przelewy24 Settings', 'give-p24'),
            'desc' => __('Configure Przelewy24 credentials for sandbox or production payments.', 'give-p24'),
        ],
        [
            'id' => GIVE_P24_OPTION . '[mode]',
            'name' => __('Mode', 'give-p24'),
            'type' => 'select',
            'default' => $options['mode'],
            'options' => [
                'sandbox' => __('Sandbox', 'give-p24'),
                'production' => __('Production', 'give-p24'),
            ],
        ],
        [
            'id' => GIVE_P24_OPTION . '[merchant_id]',
            'name' => give_p24_required_label(__('Merchant ID', 'give-p24')),
            'type' => 'text',
            'default' => $options['merchant_id'],
            'attributes' => [
                'inputmode' => 'numeric',
                'required' => 'required',
            ],
        ],
        [
            'id' => GIVE_P24_OPTION . '[pos_id]',
            'name' => give_p24_required_label(__('POS ID', 'give-p24')),
            'type' => 'text',
            'default' => $options['pos_id'],
            'attributes' => [
                'inputmode' => 'numeric',
                'required' => 'required',
            ],
        ],
        [
            'id' => GIVE_P24_OPTION . '[api_key]',
            'name' => give_p24_required_label(__('API key / secretId', 'give-p24')),
            'type' => 'password',
            'default' => '',
            'desc' => $options['api_key'] ? __('Saved. Leave as *** to keep the current key.', 'give-p24') : '',
            'attributes' => [
                'required' => 'required',
            ],
        ],
        [
            'id' => GIVE_P24_OPTION . '[crc_key]',
            'name' => give_p24_required_label(__('CRC key', 'give-p24')),
            'type' => 'password',
            'default' => '',
            'desc' => $options['crc_key'] ? __('Saved. Leave as *** to keep the current key.', 'give-p24') : '',
            'attributes' => [
                'required' => 'required',
            ],
        ],
        [
            'id' => 'give_p24_test_access',
            'name' => __('Test connection', 'give-p24'),
            'type' => 'give_p24_test_access',
        ],
        [
            'id' => 'give_p24_settings',
            'type' => 'sectionend',
        ],
    ];
}

function give_p24_required_label(string $label): string
{
    return sprintf(
        '%s <span class="give-required-indicator" aria-hidden="true">*</span><span class="screen-reader-text">%s</span>',
        esc_html($label),
        esc_html__('required', 'give-p24')
    );
}

function give_p24_get_give_setting_value($value, string $option_name, string $field_id, $default)
{
    if (preg_match('/^' . preg_quote(GIVE_P24_OPTION, '/') . '\[([a-z_]+)\]$/', $field_id, $matches)) {
        $options = give_p24_options();

        if (in_array($matches[1], ['api_key', 'crc_key'], true)) {
            return $options[$matches[1]] !== '' ? '***' : '';
        }

        return $options[$matches[1]] ?? $default;
    }

    return $value;
}

function give_p24_sanitize_give_setting_value($value, array $option, $raw_value)
{
    if (empty($option['id']) || !preg_match('/^' . preg_quote(GIVE_P24_OPTION, '/') . '\[([a-z_]+)\]$/', $option['id'], $matches)) {
        return $value;
    }

    $key = $matches[1];
    $current = give_p24_options();

    if ($key === 'mode') {
        return $raw_value === 'production' ? 'production' : 'sandbox';
    }

    if (in_array($key, ['merchant_id', 'pos_id'], true)) {
        return preg_replace('/\D+/', '', (string) $raw_value);
    }

    if (in_array($key, ['api_key', 'crc_key'], true)) {
        return ($raw_value === '' || $raw_value === '***') ? $current[$key] : sanitize_text_field((string) $raw_value);
    }

    return null;
}

function give_p24_save_give_settings(): void
{
    $raw = isset($_POST[GIVE_P24_OPTION]) ? wp_unslash($_POST[GIVE_P24_OPTION]) : [];
    $options = give_p24_sanitize_options((array) $raw);

    foreach (['merchant_id', 'pos_id', 'api_key', 'crc_key'] as $key) {
        if ($options[$key] === '') {
            Give_Admin_Settings::add_error(
                'give-p24-required-fields',
                __('Przelewy24 settings were not saved. All Przelewy24 fields are required.', 'give-p24')
            );

            return;
        }
    }

    update_option(GIVE_P24_OPTION, $options, false);
}

add_action('givewp_register_payment_gateway', static function ($registrar) {
    give_p24_register_gateway_class();

    if (class_exists('GiveP24Gateway')) {
        $registrar->registerGateway(GiveP24Gateway::class);
    }
});

add_action('rest_api_init', static function () {
    register_rest_route('give-p24/v1', '/status', [
        'methods' => 'POST',
        'callback' => 'give_p24_handle_status',
        'permission_callback' => '__return_true',
    ]);
});

function give_p24_base_url(): string
{
    return give_p24_options()['mode'] === 'production'
        ? 'https://secure.przelewy24.pl'
        : 'https://sandbox.przelewy24.pl';
}

function give_p24_sign(array $params): string
{
    return hash('sha384', wp_json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function give_p24_request(string $method, string $path, array $body)
{
    $options = give_p24_options();
    $args = [
        'method' => $method,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($options['pos_id'] . ':' . $options['api_key']),
            'Content-Type' => 'application/json',
        ],
        'timeout' => 20,
    ];

    if ($body) {
        $args['body'] = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $response = wp_remote_request(give_p24_base_url() . $path, $args);

    if (is_wp_error($response)) {
        return $response;
    }

    $decoded = json_decode((string) wp_remote_retrieve_body($response), true);
    return is_array($decoded) ? $decoded : [];
}

function give_p24_test_access()
{
    return give_p24_request('GET', '/api/v1/testAccess', []);
}

function give_p24_handle_test_access(): void
{
    if (
        !is_admin()
        || !current_user_can('manage_options')
        || empty($_GET['give_p24_test_access'])
        || empty($_GET['_wpnonce'])
        || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'give_p24_test_access')
    ) {
        return;
    }

    $result = give_p24_test_access();
    $status = (!is_wp_error($result) && !empty($result['data'])) ? 'success' : 'failed';

    give_p24_log('TestAccess result.', [
        'status' => $status,
        'response' => is_wp_error($result) ? $result->get_error_message() : $result,
    ]);

    $redirect_url = remove_query_arg(['give_p24_test_access', '_wpnonce']);
    $redirect_url = add_query_arg('give_p24_test_access_result', $status, $redirect_url);

    wp_safe_redirect($redirect_url);
    exit;
}

function give_p24_render_test_access_field(array $field, $settings = null): void
{
    $result = isset($_GET['give_p24_test_access_result']) ? sanitize_key(wp_unslash($_GET['give_p24_test_access_result'])) : '';
    $url = wp_nonce_url(add_query_arg('give_p24_test_access', '1'), 'give_p24_test_access');
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <?php echo wp_kses_post($field['name']); ?>
        </th>
        <td class="give-forminp give-forminp-<?php echo esc_attr($field['type']); ?>">
            <a class="button-secondary" href="<?php echo esc_url($url); ?>">
                <?php esc_html_e('Test Przelewy24 API access', 'give-p24'); ?>
            </a>
            <?php if ($result === 'success') : ?>
                <p class="give-field-description" style="color:#2271b1;"><?php esc_html_e('Connection successful.', 'give-p24'); ?></p>
            <?php elseif ($result === 'failed') : ?>
                <p class="give-field-description" style="color:#b32d2e;"><?php esc_html_e('Connection failed. Check mode, POS ID and API key / secretId.', 'give-p24'); ?></p>
            <?php endif; ?>
        </td>
    </tr>
    <?php
}

function give_p24_log(string $message, array $context = []): void
{
    $line = $message . ($context ? ' ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');

    if (function_exists('give_record_log')) {
        give_record_log('Przelewy24', $line, 0, 'info');
        return;
    }

    error_log('[Give Przelewy24] ' . $line);
}

function give_p24_amount_to_minor($amount): int
{
    $decimal = is_object($amount) && method_exists($amount, 'formatToDecimal')
        ? $amount->formatToDecimal()
        : (string) $amount;

    return (int) round(((float) $decimal) * 100);
}

function give_p24_parse_donation_id(string $session_id): int
{
    return preg_match('/^give-([0-9]+)-/', $session_id, $matches) ? (int) $matches[1] : 0;
}

function give_p24_handle_status(WP_REST_Request $request): WP_REST_Response
{
    $payload = (array) $request->get_json_params();
    $options = give_p24_options();

    give_p24_log('Webhook received.', [
        'sessionId' => (string) ($payload['sessionId'] ?? ''),
        'orderId' => (int) ($payload['orderId'] ?? 0),
        'amount' => (int) ($payload['amount'] ?? 0),
        'currency' => (string) ($payload['currency'] ?? ''),
    ]);

    $expected_sign = give_p24_sign([
        'merchantId' => (int) ($payload['merchantId'] ?? 0),
        'posId' => (int) ($payload['posId'] ?? 0),
        'sessionId' => (string) ($payload['sessionId'] ?? ''),
        'amount' => (int) ($payload['amount'] ?? 0),
        'originAmount' => (int) ($payload['originAmount'] ?? 0),
        'currency' => (string) ($payload['currency'] ?? ''),
        'orderId' => (int) ($payload['orderId'] ?? 0),
        'methodId' => (int) ($payload['methodId'] ?? 0),
        'statement' => (string) ($payload['statement'] ?? ''),
        'crc' => $options['crc_key'],
    ]);

    if (empty($payload['sign']) || !hash_equals($expected_sign, (string) $payload['sign'])) {
        give_p24_log('Webhook rejected: invalid sign.', [
            'sessionId' => (string) ($payload['sessionId'] ?? ''),
            'orderId' => (int) ($payload['orderId'] ?? 0),
        ]);

        return new WP_REST_Response(['error' => 'Invalid sign'], 400);
    }

    $donation_id = give_p24_parse_donation_id((string) ($payload['sessionId'] ?? ''));
    if (!$donation_id || !class_exists(Donation::class)) {
        return new WP_REST_Response(['error' => 'Donation not found'], 404);
    }

    $verify_body = [
        'merchantId' => (int) $options['merchant_id'],
        'posId' => (int) $options['pos_id'],
        'sessionId' => (string) $payload['sessionId'],
        'amount' => (int) $payload['amount'],
        'currency' => (string) $payload['currency'],
        'orderId' => (int) $payload['orderId'],
    ];
    $verify_body['sign'] = give_p24_sign([
        'sessionId' => $verify_body['sessionId'],
        'orderId' => $verify_body['orderId'],
        'amount' => $verify_body['amount'],
        'currency' => $verify_body['currency'],
        'crc' => $options['crc_key'],
    ]);

    $verified = give_p24_request('PUT', '/api/v1/transaction/verify', $verify_body);
    if (is_wp_error($verified) || ((int) ($verified['responseCode'] ?? -1) !== 0)) {
        give_p24_log('Transaction verification failed.', [
            'sessionId' => $verify_body['sessionId'],
            'orderId' => $verify_body['orderId'],
            'response' => is_wp_error($verified) ? $verified->get_error_message() : $verified,
        ]);

        return new WP_REST_Response(['error' => 'Verification failed'], 400);
    }

    give_p24_log('Transaction verified.', [
        'donationId' => $donation_id,
        'sessionId' => $verify_body['sessionId'],
        'orderId' => $verify_body['orderId'],
    ]);

    $donation = Donation::find($donation_id);
    if (!$donation) {
        return new WP_REST_Response(['error' => 'Donation not found'], 404);
    }

    $donation->status = DonationStatus::COMPLETE();
    $donation->gatewayTransactionId = (string) $payload['orderId'];
    $donation->save();

    update_post_meta($donation_id, '_give_p24_session_id', (string) $payload['sessionId']);
    update_post_meta($donation_id, '_give_p24_order_id', (int) $payload['orderId']);

    DonationNote::create([
        'donationId' => $donation_id,
        'content' => sprintf('Przelewy24 payment verified. Order ID: %s', (string) $payload['orderId']),
    ]);

    return new WP_REST_Response(['status' => 'ok'], 200);
}

function give_p24_register_gateway_class(): void
{
    if (class_exists('GiveP24Gateway', false) || !class_exists(PaymentGateway::class)) {
        return;
    }

    class GiveP24Gateway extends PaymentGateway
    {
        public static function id(): string
        {
            return 'przelewy24';
        }

        public function getId(): string
        {
            return self::id();
        }

        public function getName(): string
        {
            return __('Przelewy24', 'give-p24');
        }

        public function getPaymentMethodLabel(): string
        {
            return __('Przelewy24', 'give-p24');
        }

        public function enqueueScript(int $formId)
        {
            wp_enqueue_script(
                'give-p24-gateway',
                plugin_dir_url(__FILE__) . 'assets/js/give-p24.js',
                ['react', 'wp-element'],
                '0.1.0',
                true
            );
        }

        public function formSettings(int $formId): array
        {
            return [
                'message' => __('You will be redirected to Przelewy24 to complete the donation.', 'give-p24'),
            ];
        }

        public function getLegacyFormFieldMarkup(int $formId, array $args): string
        {
            return '<div class="give-p24-help-text"><p>' . esc_html__('You will be redirected to Przelewy24 to complete the donation.', 'give-p24') . '</p></div>';
        }

        public function createPayment(Donation $donation, $gatewayData)
        {
            $options = give_p24_options();
            foreach (['merchant_id', 'pos_id', 'api_key', 'crc_key'] as $key) {
                if ($options[$key] === '') {
                    throw new Exception(__('Przelewy24 gateway is not configured.', 'give-p24'));
                }
            }

            $amount = give_p24_amount_to_minor($donation->amount);
            $currency = 'PLN';
            $session_id = sprintf('give-%d-%s', $donation->id, wp_generate_uuid4());

            $body = [
                'merchantId' => (int) $options['merchant_id'],
                'posId' => (int) $options['pos_id'],
                'sessionId' => $session_id,
                'amount' => $amount,
                'currency' => $currency,
                'description' => sprintf(__('Donation #%s', 'give-p24'), $donation->id),
                'email' => $donation->email,
                'client' => trim($donation->firstName . ' ' . $donation->lastName),
                'country' => 'PL',
                'language' => 'pl',
                'urlReturn' => give_get_success_page_uri(),
                'urlStatus' => rest_url('give-p24/v1/status'),
            ];
            $body['sign'] = give_p24_sign([
                'sessionId' => $session_id,
                'merchantId' => (int) $options['merchant_id'],
                'amount' => $amount,
                'currency' => $currency,
                'crc' => $options['crc_key'],
            ]);

            $registered = give_p24_request('POST', '/api/v1/transaction/register', $body);
            if (is_wp_error($registered) || empty($registered['data']['token'])) {
                give_p24_log('Transaction registration failed.', [
                    'donationId' => $donation->id,
                    'sessionId' => $session_id,
                    'response' => is_wp_error($registered) ? $registered->get_error_message() : $registered,
                ]);

                throw new Exception(__('Przelewy24 transaction registration failed.', 'give-p24'));
            }

            give_p24_log('Transaction registered.', [
                'donationId' => $donation->id,
                'sessionId' => $session_id,
                'amount' => $amount,
                'currency' => $currency,
            ]);

            update_post_meta($donation->id, '_give_p24_session_id', $session_id);

            return new RedirectOffsite(give_p24_base_url() . '/trnRequest/' . rawurlencode($registered['data']['token']));
        }

        public function refundDonation(Donation $donation): PaymentRefunded
        {
            return new PaymentRefunded();
        }
    }
}
