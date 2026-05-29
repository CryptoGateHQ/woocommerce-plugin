<?php
defined( 'ABSPATH' ) || exit;

class CryptoGate_Webhook {

    /**
     * Entry point — hooked to woocommerce_api_cryptogate.
     * URL: https://yoursite.com/?wc-api=cryptogate
     */
    public static function handle() {
        $raw_body = file_get_contents( 'php://input' );

        // Verify HMAC-SHA256 signature.
        $gateway = new CryptoGate_Gateway();
        $secret  = $gateway->get_option( 'webhook_secret' );

        if ( empty( $secret ) ) {
            self::respond( 400, 'Webhook secret not configured.' );
        }

        $sig_header = $_SERVER['HTTP_X_CRYPTOGATE_SIGNATURE'] ?? '';
        // Header format: "sha256=<hex>"
        $expected = 'sha256=' . hash_hmac( 'sha256', $raw_body, $secret );

        if ( ! hash_equals( $expected, $sig_header ) ) {
            self::respond( 401, 'Invalid signature.' );
        }

        $event = json_decode( $raw_body, true );
        if ( ! $event || empty( $event['event'] ) ) {
            self::respond( 400, 'Invalid payload.' );
        }

        switch ( $event['event'] ) {
            case 'payment.completed':
                self::on_completed( $event );
                break;

            case 'payment.partial':
                self::on_partial( $event );
                break;

            case 'payment.expired':
                self::on_expired( $event );
                break;
        }

        self::respond( 200, 'OK' );
    }

    // ── Event handlers ────────────────────────────────────────────────────────

    private static function on_completed( array $event ) {
        $order = self::find_order( $event );
        if ( ! $order ) return;

        // Avoid double-processing.
        if ( $order->has_status( [ 'processing', 'completed' ] ) ) return;

        $order->payment_complete( $event['transaction_id'] ?? '' );
        $order->add_order_note( sprintf(
            __( 'CryptoGate payment confirmed. TX: %s | Crypto: %s | Amount: %s %s', 'cryptogate-woocommerce' ),
            $event['transaction_id'] ?? '—',
            $event['currency_crypto'] ?? '—',
            $event['amount_crypto'] ?? '—',
            $event['currency_crypto'] ?? ''
        ) );
    }

    private static function on_partial( array $event ) {
        $order = self::find_order( $event );
        if ( ! $order ) return;

        $order->update_status( 'on-hold', sprintf(
            __( 'CryptoGate partial payment received. TX: %s — customer paid less than required. Awaiting resolution.', 'cryptogate-woocommerce' ),
            $event['transaction_id'] ?? '—'
        ) );
    }

    private static function on_expired( array $event ) {
        $order = self::find_order( $event );
        if ( ! $order ) return;

        if ( $order->has_status( 'pending' ) ) {
            $order->update_status( 'cancelled', sprintf(
                __( 'CryptoGate payment window expired. TX: %s', 'cryptogate-woocommerce' ),
                $event['transaction_id'] ?? '—'
            ) );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Resolve the WooCommerce order from the webhook event.
     * Uses order_id from the event payload (set as metadata at transaction creation).
     */
    private static function find_order( array $event ): ?\WC_Order {
        // order_id is the WC order ID we passed as metadata at transaction creation.
        $order_id = $event['order_id'] ?? null;

        if ( $order_id ) {
            $order = wc_get_order( (int) $order_id );
            if ( $order ) return $order;
        }

        // Fallback: search by the stored CryptoGate txid.
        $txid = $event['transaction_id'] ?? '';
        if ( ! $txid ) return null;

        $orders = wc_get_orders( [
            'meta_key'   => '_cryptogate_txid',
            'meta_value' => $txid,
            'limit'      => 1,
        ] );

        return $orders[0] ?? null;
    }

    private static function respond( int $status, string $message ): void {
        http_response_code( $status );
        echo esc_html( $message );
        exit;
    }
}
