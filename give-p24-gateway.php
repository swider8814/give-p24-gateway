<?php
/**
 * Plugin Name: Give Przelewy24 Gateway
 * Description: Przelewy24 payment gateway for GiveWP/Give donations.
 * Version: 0.1.9
 * Requires at least: 6.0
 * Requires PHP: 7.2
 * Requires Plugins: give
 * Author: Daniel Świderski
 * Author URI: https://8814.pl
 * Text Domain: give-p24-gateway
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

const GIVE_P24_GATEWAY_OPTION = 'give_p24_gateway_options';
const GIVE_P24_GATEWAY_LEGACY_OPTION = 'give_p24_options';
const GIVE_P24_GATEWAY_VERSION = '0.1.9';

register_activation_hook(__FILE__, 'give_p24_gateway_activate');

function give_p24_gateway_is_give_active(): bool
{
    return class_exists('Give') || function_exists('Give') || defined('GIVE_VERSION');
}

function give_p24_gateway_activate(): void
{
    if (give_p24_gateway_is_give_active()) {
        return;
    }

    deactivate_plugins(plugin_basename(__FILE__));
    wp_die(
        esc_html__('Give Przelewy24 Gateway requires the Give plugin to be active.', 'give-p24-gateway'),
        esc_html__('Plugin dependency missing', 'give-p24-gateway'),
        ['back_link' => true]
    );
}

add_action('plugins_loaded', static function () {
    load_plugin_textdomain('give-p24-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages');

    if (!give_p24_gateway_is_give_active()) {
        return;
    }

    add_filter('give_get_sections_gateways', static function (array $sections): array {
        $sections['przelewy24'] = __('Przelewy24', 'give-p24-gateway');

        return $sections;
    });

    add_filter('give_get_settings_gateways', static function (array $settings): array {
        if (!function_exists('give_get_current_setting_section') || give_get_current_setting_section() !== 'przelewy24') {
            return $settings;
        }

        return give_p24_gateway_give_settings();
    });

    add_filter('give_admin_field_get_value', 'give_p24_gateway_get_give_setting_value', 10, 4);
    add_filter('give_admin_settings_sanitize_option_' . GIVE_P24_GATEWAY_OPTION, 'give_p24_gateway_sanitize_give_setting_value', 10, 3);
    add_filter('give_save_options_gateways_przelewy24', '__return_false');
    add_action('give_update_options_gateways_przelewy24', 'give_p24_gateway_save_give_settings');
    add_action('admin_init', 'give_p24_gateway_handle_test_access');
    add_action('give_admin_field_give_p24_gateway_test_access', 'give_p24_gateway_render_test_access_field', 10, 2);

    add_action('rest_api_init', static function () {
        register_rest_route('give-p24-gateway/v1', '/status', [
            'methods' => 'POST',
            'callback' => 'give_p24_gateway_handle_status',
            'permission_callback' => '__return_true',
        ]);
    });
});

function give_p24_gateway_default_options(): array
{
    return [
        'mode' => 'sandbox',
        'merchant_id' => '',
        'pos_id' => '',
        'api_key' => '',
        'crc_key' => '',
    ];
}

function give_p24_gateway_options(): array
{
    $options = get_option(GIVE_P24_GATEWAY_OPTION, false);

    if ($options === false) {
        $legacy_options = get_option(GIVE_P24_GATEWAY_LEGACY_OPTION, false);

        if (is_array($legacy_options)) {
            update_option(GIVE_P24_GATEWAY_OPTION, $legacy_options, false);
            delete_option(GIVE_P24_GATEWAY_LEGACY_OPTION);
            $options = $legacy_options;
        }
    }

    return array_merge(give_p24_gateway_default_options(), (array) $options);
}

function give_p24_gateway_sanitize_options($input): array
{
    $input = (array) $input;
    $current = give_p24_gateway_options();

    return [
        'mode' => (($input['mode'] ?? 'sandbox') === 'production') ? 'production' : 'sandbox',
        'merchant_id' => preg_replace('/\D+/', '', (string) ($input['merchant_id'] ?? '')),
        'pos_id' => preg_replace('/\D+/', '', (string) ($input['pos_id'] ?? '')),
        'api_key' => in_array(($input['api_key'] ?? ''), ['', '***'], true) ? $current['api_key'] : sanitize_text_field($input['api_key']),
        'crc_key' => in_array(($input['crc_key'] ?? ''), ['', '***'], true) ? $current['crc_key'] : sanitize_text_field($input['crc_key']),
    ];
}

function give_p24_gateway_give_settings(): array
{
    $options = give_p24_gateway_options();

    return [
        [
            'id' => 'give_p24_gateway_settings',
            'type' => 'title',
            'title' => __('Przelewy24 Settings', 'give-p24-gateway'),
            'desc' => __('Configure Przelewy24 credentials for sandbox or production payments.', 'give-p24-gateway'),
        ],
        [
            'id' => GIVE_P24_GATEWAY_OPTION . '[mode]',
            'name' => __('Mode', 'give-p24-gateway'),
            'type' => 'select',
            'default' => $options['mode'],
            'options' => [
                'sandbox' => __('Sandbox', 'give-p24-gateway'),
                'production' => __('Production', 'give-p24-gateway'),
            ],
        ],
        [
            'id' => GIVE_P24_GATEWAY_OPTION . '[merchant_id]',
            'name' => give_p24_gateway_required_label(__('Merchant ID', 'give-p24-gateway')),
            'type' => 'text',
            'default' => $options['merchant_id'],
            'attributes' => [
                'inputmode' => 'numeric',
                'required' => 'required',
            ],
        ],
        [
            'id' => GIVE_P24_GATEWAY_OPTION . '[pos_id]',
            'name' => give_p24_gateway_required_label(__('POS ID', 'give-p24-gateway')),
            'type' => 'text',
            'default' => $options['pos_id'],
            'attributes' => [
                'inputmode' => 'numeric',
                'required' => 'required',
            ],
        ],
        [
            'id' => GIVE_P24_GATEWAY_OPTION . '[api_key]',
            'name' => give_p24_gateway_required_label(__('API key / secretId', 'give-p24-gateway')),
            'type' => 'password',
            'default' => '',
            'desc' => $options['api_key'] ? __('Saved. Leave as *** to keep the current key.', 'give-p24-gateway') : '',
            'attributes' => [
                'required' => 'required',
            ],
        ],
        [
            'id' => GIVE_P24_GATEWAY_OPTION . '[crc_key]',
            'name' => give_p24_gateway_required_label(__('CRC key', 'give-p24-gateway')),
            'type' => 'password',
            'default' => '',
            'desc' => $options['crc_key'] ? __('Saved. Leave as *** to keep the current key.', 'give-p24-gateway') : '',
            'attributes' => [
                'required' => 'required',
            ],
        ],
        [
            'id' => 'give_p24_gateway_test_access',
            'name' => __('Test connection', 'give-p24-gateway'),
            'type' => 'give_p24_gateway_test_access',
        ],
        [
            'id' => 'give_p24_gateway_settings',
            'type' => 'sectionend',
        ],
    ];
}

function give_p24_gateway_required_label(string $label): string
{
    return sprintf(
        '%s <span class="give-required-indicator" aria-hidden="true">*</span><span class="screen-reader-text">%s</span>',
        esc_html($label),
        esc_html__('required', 'give-p24-gateway')
    );
}

function give_p24_gateway_get_give_setting_value($value, string $option_name, string $field_id, $default)
{
    if (preg_match('/^' . preg_quote(GIVE_P24_GATEWAY_OPTION, '/') . '\[([a-z_]+)\]$/', $field_id, $matches)) {
        $options = give_p24_gateway_options();

        if (in_array($matches[1], ['api_key', 'crc_key'], true)) {
            return $options[$matches[1]] !== '' ? '***' : '';
        }

        return $options[$matches[1]] ?? $default;
    }

    return $value;
}

function give_p24_gateway_sanitize_give_setting_value($value, array $option, $raw_value)
{
    if (empty($option['id']) || !preg_match('/^' . preg_quote(GIVE_P24_GATEWAY_OPTION, '/') . '\[([a-z_]+)\]$/', $option['id'], $matches)) {
        return $value;
    }

    $key = $matches[1];
    $current = give_p24_gateway_options();

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

function give_p24_gateway_save_give_settings(): void
{
    $raw = isset($_POST[GIVE_P24_GATEWAY_OPTION]) ? wp_unslash($_POST[GIVE_P24_GATEWAY_OPTION]) : [];
    $options = give_p24_gateway_sanitize_options((array) $raw);

    foreach (['merchant_id', 'pos_id', 'api_key', 'crc_key'] as $key) {
        if ($options[$key] === '') {
            Give_Admin_Settings::add_error(
                'give-p24-gateway-required-fields',
                __('Przelewy24 settings were not saved. All Przelewy24 fields are required.', 'give-p24-gateway')
            );

            return;
        }
    }

    update_option(GIVE_P24_GATEWAY_OPTION, $options, false);
}

add_action('givewp_register_payment_gateway', static function ($registrar) {
    give_p24_gateway_register_gateway_class();

    if (class_exists('GiveP24Gateway')) {
        $registrar->registerGateway(GiveP24Gateway::class);
    }
});

function give_p24_gateway_base_url(): string
{
    return give_p24_gateway_options()['mode'] === 'production'
        ? 'https://secure.przelewy24.pl'
        : 'https://sandbox.przelewy24.pl';
}

function give_p24_gateway_sign(array $params): string
{
    return hash('sha384', wp_json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function give_p24_gateway_request(string $method, string $path, array $body)
{
    $options = give_p24_gateway_options();
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

    $response = wp_remote_request(give_p24_gateway_base_url() . $path, $args);

    if (is_wp_error($response)) {
        return $response;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    $body = (string) wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);

    if ($status_code < 200 || $status_code >= 300) {
        return new WP_Error('give_p24_gateway_http_error', 'Przelewy24 API returned an HTTP error.', [
            'statusCode' => $status_code,
            'response' => is_array($decoded) ? $decoded : $body,
        ]);
    }

    if (!is_array($decoded)) {
        return new WP_Error('give_p24_gateway_invalid_json', 'Przelewy24 API returned an invalid JSON response.', [
            'statusCode' => $status_code,
            'response' => $body,
        ]);
    }

    return $decoded;
}

function give_p24_gateway_test_access()
{
    return give_p24_gateway_request('GET', '/api/v1/testAccess', []);
}

function give_p24_gateway_error_context(WP_Error $error): array
{
    return [
        'message' => $error->get_error_message(),
        'data' => $error->get_error_data(),
    ];
}

function give_p24_gateway_handle_test_access(): void
{
    if (
        !is_admin()
        || !current_user_can('manage_options')
        || empty($_GET['give_p24_gateway_test_access'])
        || empty($_GET['_wpnonce'])
        || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'give_p24_gateway_test_access')
    ) {
        return;
    }

    $result = give_p24_gateway_test_access();
    $status = (!is_wp_error($result) && !empty($result['data'])) ? 'success' : 'failed';

    give_p24_gateway_log('TestAccess result.', [
        'status' => $status,
        'response' => is_wp_error($result) ? give_p24_gateway_error_context($result) : $result,
    ], $status === 'success' ? 'success' : 'warning');

    $redirect_url = give_p24_gateway_settings_url();
    $redirect_url = add_query_arg('give_p24_gateway_test_access_result', $status, $redirect_url);

    wp_safe_redirect($redirect_url);
    exit;
}

function give_p24_gateway_render_test_access_field(array $field, $settings = null): void
{
    $result = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['give_p24_gateway_test_access_result'])
        ? sanitize_key(wp_unslash($_GET['give_p24_gateway_test_access_result']))
        : '';
    $url = wp_nonce_url(add_query_arg('give_p24_gateway_test_access', '1', give_p24_gateway_settings_url()), 'give_p24_gateway_test_access');
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <?php echo wp_kses_post($field['name']); ?>
        </th>
        <td class="give-forminp give-forminp-<?php echo esc_attr($field['type']); ?>">
            <a class="button-secondary" href="<?php echo esc_url($url); ?>">
                <?php esc_html_e('Test Przelewy24 API access', 'give-p24-gateway'); ?>
            </a>
            <?php if ($result === 'success') : ?>
                <p class="give-field-description" style="color:#2271b1;"><?php esc_html_e('Connection successful.', 'give-p24-gateway'); ?></p>
            <?php elseif ($result === 'failed') : ?>
                <p class="give-field-description" style="color:#b32d2e;"><?php esc_html_e('Connection failed. Check mode, POS ID and API key / secretId.', 'give-p24-gateway'); ?></p>
            <?php endif; ?>
        </td>
    </tr>
    <?php
}

function give_p24_gateway_settings_url(): string
{
    return add_query_arg(
        [
            'post_type' => 'give_forms',
            'page' => 'give-settings',
            'tab' => 'gateways',
            'section' => 'przelewy24',
        ],
        admin_url('edit.php')
    );
}

function give_p24_gateway_log(string $message, array $context = [], string $type = 'info'): void
{
    $line = $message . ($context ? ' ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');

    if (class_exists('\Give\Log\LogFactory') && in_array($type, ['error', 'warning', 'notice', 'success', 'info', 'debug'], true)) {
        \Give\Log\LogFactory::make($type, $message, 'Payment', 'Przelewy24', $context)->save();

        return;
    }

    if (function_exists('give_record_log')) {
        give_record_log('Przelewy24', $line, 0, $type);
        return;
    }

    error_log('[Give Przelewy24] ' . $line);
}

function give_p24_gateway_amount_to_minor($amount): int
{
    $decimal = is_object($amount) && method_exists($amount, 'formatToDecimal')
        ? $amount->formatToDecimal()
        : (string) $amount;

    return (int) round(((float) $decimal) * 100);
}

function give_p24_gateway_parse_donation_id(string $session_id): int
{
    return preg_match('/^give-([0-9]+)-/', $session_id, $matches) ? (int) $matches[1] : 0;
}

function give_p24_gateway_transaction_description(Donation $donation): string
{
    $form_title = trim(wp_strip_all_tags((string) ($donation->formTitle ?? '')));

    if ($form_title !== '') {
        return mb_substr(sprintf(__('Donation - %s', 'give-p24-gateway'), $form_title), 0, 128);
    }

    return mb_substr(sprintf(__('Donation #%s', 'give-p24-gateway'), $donation->id), 0, 128);
}

function give_p24_gateway_payment_note(string $transaction_id): string
{
    if ($transaction_id !== '') {
        return sprintf(__('Przelewy24 payment verified (transaction %s).', 'give-p24-gateway'), $transaction_id);
    }

    return __('Przelewy24 payment verified.', 'give-p24-gateway');
}

function give_p24_gateway_donation_status_value(Donation $donation): string
{
    return is_object($donation->status) && method_exists($donation->status, 'getValue')
        ? (string) $donation->status->getValue()
        : (string) $donation->status;
}

function give_p24_gateway_acquire_webhook_lock(int $donation_id): bool
{
    $lock_key = '_give_p24_gateway_webhook_lock';

    if (add_post_meta($donation_id, $lock_key, time(), true)) {
        return true;
    }

    $locked_at = (int) get_post_meta($donation_id, $lock_key, true);
    if ($locked_at && $locked_at < time() - 10 * MINUTE_IN_SECONDS) {
        delete_post_meta($donation_id, $lock_key);
        return add_post_meta($donation_id, $lock_key, time(), true);
    }

    return false;
}

function give_p24_gateway_handle_status(WP_REST_Request $request): WP_REST_Response
{
    $payload = (array) $request->get_json_params();
    $options = give_p24_gateway_options();

    give_p24_gateway_log('Webhook received.', [
        'sessionId' => (string) ($payload['sessionId'] ?? ''),
        'orderId' => (int) ($payload['orderId'] ?? 0),
        'amount' => (int) ($payload['amount'] ?? 0),
        'currency' => (string) ($payload['currency'] ?? ''),
    ]);

    $expected_sign = give_p24_gateway_sign([
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
        give_p24_gateway_log('Webhook rejected: invalid sign.', [
            'sessionId' => (string) ($payload['sessionId'] ?? ''),
            'orderId' => (int) ($payload['orderId'] ?? 0),
        ], 'error');

        return new WP_REST_Response(['error' => 'Invalid sign'], 400);
    }

    if ((int) ($payload['merchantId'] ?? 0) !== (int) $options['merchant_id'] || (int) ($payload['posId'] ?? 0) !== (int) $options['pos_id']) {
        give_p24_gateway_log('Webhook rejected: merchant or POS mismatch.', [
            'expectedMerchantId' => (int) $options['merchant_id'],
            'receivedMerchantId' => (int) ($payload['merchantId'] ?? 0),
            'expectedPosId' => (int) $options['pos_id'],
            'receivedPosId' => (int) ($payload['posId'] ?? 0),
        ], 'error');

        return new WP_REST_Response(['error' => 'Merchant or POS mismatch'], 400);
    }

    $donation_id = give_p24_gateway_parse_donation_id((string) ($payload['sessionId'] ?? ''));
    if (!$donation_id || !class_exists(Donation::class)) {
        return new WP_REST_Response(['error' => 'Donation not found'], 404);
    }

    $donation = Donation::find($donation_id);
    if (!$donation) {
        return new WP_REST_Response(['error' => 'Donation not found'], 404);
    }

    $expected_session_id = (string) get_post_meta($donation_id, '_give_p24_gateway_session_id', true);
    if ($expected_session_id === '' || !hash_equals($expected_session_id, (string) $payload['sessionId'])) {
        give_p24_gateway_log('Webhook rejected: session mismatch.', [
            'donationId' => $donation_id,
            'expectedSessionId' => $expected_session_id,
            'receivedSessionId' => (string) ($payload['sessionId'] ?? ''),
        ], 'error');

        return new WP_REST_Response(['error' => 'Session mismatch'], 400);
    }

    $expected_amount = give_p24_gateway_amount_to_minor($donation->amount);
    if ($expected_amount !== (int) ($payload['amount'] ?? 0) || (string) ($payload['currency'] ?? '') !== 'PLN') {
        give_p24_gateway_log('Webhook rejected: amount or currency mismatch.', [
            'donationId' => $donation_id,
            'expectedAmount' => $expected_amount,
            'receivedAmount' => (int) ($payload['amount'] ?? 0),
            'receivedCurrency' => (string) ($payload['currency'] ?? ''),
        ], 'error');

        return new WP_REST_Response(['error' => 'Amount or currency mismatch'], 400);
    }

    if (give_p24_gateway_donation_status_value($donation) === DonationStatus::COMPLETE) {
        give_p24_gateway_log('Webhook ignored: donation already complete.', [
            'donationId' => $donation_id,
            'sessionId' => (string) $payload['sessionId'],
            'orderId' => (int) $payload['orderId'],
        ], 'warning');

        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    if (!give_p24_gateway_acquire_webhook_lock($donation_id)) {
        give_p24_gateway_log('Webhook ignored: donation is already being processed.', [
            'donationId' => $donation_id,
            'sessionId' => (string) $payload['sessionId'],
            'orderId' => (int) $payload['orderId'],
        ], 'warning');

        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    $verify_body = [
        'merchantId' => (int) $options['merchant_id'],
        'posId' => (int) $options['pos_id'],
        'sessionId' => (string) $payload['sessionId'],
        'amount' => (int) $payload['amount'],
        'currency' => (string) $payload['currency'],
        'orderId' => (int) $payload['orderId'],
    ];
    $verify_body['sign'] = give_p24_gateway_sign([
        'sessionId' => $verify_body['sessionId'],
        'orderId' => $verify_body['orderId'],
        'amount' => $verify_body['amount'],
        'currency' => $verify_body['currency'],
        'crc' => $options['crc_key'],
    ]);

    $verified = give_p24_gateway_request('PUT', '/api/v1/transaction/verify', $verify_body);
    if (is_wp_error($verified) || ((int) ($verified['responseCode'] ?? -1) !== 0)) {
        give_p24_gateway_log('Transaction verification failed.', [
            'sessionId' => $verify_body['sessionId'],
            'orderId' => $verify_body['orderId'],
            'response' => is_wp_error($verified) ? give_p24_gateway_error_context($verified) : $verified,
        ], 'error');
        delete_post_meta($donation_id, '_give_p24_gateway_webhook_lock');

        return new WP_REST_Response(['error' => 'Verification failed'], 400);
    }

    give_p24_gateway_log('Transaction verified.', [
        'donationId' => $donation_id,
        'sessionId' => $verify_body['sessionId'],
        'orderId' => $verify_body['orderId'],
    ], 'success');

    $donation->status = DonationStatus::COMPLETE();
    $donation->gatewayTransactionId = (string) $payload['orderId'];
    $donation->save();

    $transaction = give_p24_gateway_request('GET', '/api/v1/transaction/by/sessionId/' . rawurlencode((string) $payload['sessionId']), []);
    $transaction_id = is_wp_error($transaction) ? '' : sanitize_text_field((string) ($transaction['data']['statement'] ?? ''));

    update_post_meta($donation_id, '_give_p24_gateway_session_id', (string) $payload['sessionId']);
    update_post_meta($donation_id, '_give_p24_gateway_order_id', (int) $payload['orderId']);
    update_post_meta($donation_id, '_give_p24_gateway_transaction_id', $transaction_id);

    DonationNote::create([
        'donationId' => $donation_id,
        'content' => give_p24_gateway_payment_note($transaction_id),
    ]);

    return new WP_REST_Response(['status' => 'ok'], 200);
}

function give_p24_gateway_register_gateway_class(): void
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
            return __('Przelewy24', 'give-p24-gateway');
        }

        public function getPaymentMethodLabel(): string
        {
            return __('Przelewy24', 'give-p24-gateway');
        }

        public function enqueueScript(int $formId)
        {
            wp_enqueue_script(
                'give-p24-gateway',
                plugin_dir_url(__FILE__) . 'assets/js/give-p24-gateway.js',
                ['react', 'wp-element'],
                GIVE_P24_GATEWAY_VERSION,
                true
            );
        }

        public function formSettings(int $formId): array
        {
            return [
                'message' => __('You will be redirected to Przelewy24 to complete the donation.', 'give-p24-gateway'),
            ];
        }

        public function getLegacyFormFieldMarkup(int $formId, array $args): string
        {
            return '<div class="give-p24-gateway-help-text"><p>' . esc_html__('You will be redirected to Przelewy24 to complete the donation.', 'give-p24-gateway') . '</p></div>';
        }

        public function createPayment(Donation $donation, $gatewayData)
        {
            $options = give_p24_gateway_options();
            foreach (['merchant_id', 'pos_id', 'api_key', 'crc_key'] as $key) {
                if ($options[$key] === '') {
                    throw new Exception(__('Przelewy24 gateway is not configured.', 'give-p24-gateway'));
                }
            }

            $amount = give_p24_gateway_amount_to_minor($donation->amount);
            $currency = 'PLN';
            $session_id = sprintf('give-%d-%s', $donation->id, wp_generate_uuid4());

            $body = [
                'merchantId' => (int) $options['merchant_id'],
                'posId' => (int) $options['pos_id'],
                'sessionId' => $session_id,
                'amount' => $amount,
                'currency' => $currency,
                'description' => give_p24_gateway_transaction_description($donation),
                'email' => $donation->email,
                'client' => trim($donation->firstName . ' ' . $donation->lastName),
                'country' => 'PL',
                'language' => 'pl',
                'urlReturn' => give_get_success_page_uri(),
                'urlStatus' => rest_url('give-p24-gateway/v1/status'),
            ];
            $body['sign'] = give_p24_gateway_sign([
                'sessionId' => $session_id,
                'merchantId' => (int) $options['merchant_id'],
                'amount' => $amount,
                'currency' => $currency,
                'crc' => $options['crc_key'],
            ]);

            $registered = give_p24_gateway_request('POST', '/api/v1/transaction/register', $body);
            if (is_wp_error($registered) || empty($registered['data']['token'])) {
                give_p24_gateway_log('Transaction registration failed.', [
                    'donationId' => $donation->id,
                    'sessionId' => $session_id,
                    'response' => is_wp_error($registered) ? give_p24_gateway_error_context($registered) : $registered,
                ], 'error');

                throw new Exception(__('Przelewy24 transaction registration failed.', 'give-p24-gateway'));
            }

            give_p24_gateway_log('Transaction registered.', [
                'donationId' => $donation->id,
                'sessionId' => $session_id,
                'amount' => $amount,
                'currency' => $currency,
            ], 'success');

            update_post_meta($donation->id, '_give_p24_gateway_session_id', $session_id);

            return new RedirectOffsite(give_p24_gateway_base_url() . '/trnRequest/' . rawurlencode($registered['data']['token']));
        }

        public function refundDonation(Donation $donation): PaymentRefunded
        {
            return new PaymentRefunded();
        }
    }
}
