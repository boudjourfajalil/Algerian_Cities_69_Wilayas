<?php
/**
 * Algeria Localities Checkout Overrides.
 *
 * Replaces WooCommerce state/city fields for Algeria with wilayas/communes,
 * and exposes an AJAX endpoint for loading communes dynamically.
 *
 * @package Algeria_Localities_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Algeria_Localities_Checkout' ) ) :

class Algeria_Localities_Checkout {

    /**
     * Singleton instance.
     *
     * @var Algeria_Localities_Checkout
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return Algeria_Localities_Checkout
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        // Override states for DZ.
        add_filter( 'woocommerce_states', array( $this, 'filter_woocommerce_states' ) );

        // Replace checkout fields.
        add_filter( 'woocommerce_checkout_fields', array( $this, 'filter_checkout_fields' ) );

        // AJAX endpoints for communes.
        add_action( 'wp_ajax_load_algeria_communes', array( $this, 'ajax_load_communes' ) );
        add_action( 'wp_ajax_nopriv_load_algeria_communes', array( $this, 'ajax_load_communes' ) );

        // Front-end assets.
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Get importer instance.
     *
     * @return Algeria_Localities_Importer
     */
    protected function importer() {
        return Algeria_Localities_Importer::instance();
    }

    /**
     * Override WooCommerce states only for Algeria (DZ).
     *
     * @param array $states States array indexed by country code.
     *
     * @return array
     */
    public function filter_woocommerce_states( $states ) {
        $wilayas = $this->importer()->get_wilayas_raw();

        if ( empty( $wilayas ) ) {
            // No data, do not override anything.
            return $states;
        }

        $dz_states = array();

        foreach ( $wilayas as $id => $data ) {
            $code  = $this->build_wilaya_code( $id );
            $label = $this->importer()->build_label(
                isset( $data['latin'] ) ? $data['latin'] : '',
                isset( $data['arabic'] ) ? $data['arabic'] : ''
            );
            $dz_states[ $code ] = $label;
        }

        $states['DZ'] = $dz_states;

        return $states;
    }

    /**
     * Modify checkout fields for billing/shipping state and city for Algeria only.
     *
     * @param array $fields Checkout fields.
     *
     * @return array
     */
    public function filter_checkout_fields( $fields ) {
        $wilayas = $this->importer()->get_wilayas_raw();

        if ( empty( $wilayas ) ) {
            // Fall back to default WooCommerce behavior if no data is present.
            return $fields;
        }

        $dz_states = array();
        foreach ( $wilayas as $id => $data ) {
            $dz_states[ $this->build_wilaya_code( $id ) ] = $this->importer()->build_label(
                isset( $data['latin'] ) ? $data['latin'] : '',
                isset( $data['arabic'] ) ? $data['arabic'] : ''
            );
        }

        // Billing state & city.
        if ( isset( $fields['billing']['billing_state'] ) ) {
            $fields['billing']['billing_state']['type']    = 'select';
            $fields['billing']['billing_state']['options'] = $dz_states;
            $fields['billing']['billing_state']['class'][] = 'algeria-wilaya-field';
        }

        if ( isset( $fields['billing']['billing_city'] ) ) {
            $fields['billing']['billing_city']['type']        = 'select';
            $fields['billing']['billing_city']['options']     = array(
                '' => __( 'Select a commune…', 'algeria-localities-woocommerce' ),
            );
            $fields['billing']['billing_city']['class'][]     = 'algeria-commune-field';
            $fields['billing']['billing_city']['custom_attributes']['data-placeholder'] = __( 'Select a commune…', 'algeria-localities-woocommerce' );
        }

        // Shipping state & city.
        if ( isset( $fields['shipping']['shipping_state'] ) ) {
            $fields['shipping']['shipping_state']['type']    = 'select';
            $fields['shipping']['shipping_state']['options'] = $dz_states;
            $fields['shipping']['shipping_state']['class'][] = 'algeria-wilaya-field';
        }

        if ( isset( $fields['shipping']['shipping_city'] ) ) {
            $fields['shipping']['shipping_city']['type']        = 'select';
            $fields['shipping']['shipping_city']['options']     = array(
                '' => __( 'Select a commune…', 'algeria-localities-woocommerce' ),
            );
            $fields['shipping']['shipping_city']['class'][]     = 'algeria-commune-field';
            $fields['shipping']['shipping_city']['custom_attributes']['data-placeholder'] = __( 'Select a commune…', 'algeria-localities-woocommerce' );
        }

        return $fields;
    }

    /**
     * AJAX handler to load communes for a given wilaya.
     *
     * Expects POST:
     * - security (nonce)
     * - wilaya_id (integer)
     */
    public function ajax_load_communes() {
        check_ajax_referer( 'algeria_localities_checkout', 'security' );

        $wilaya_raw = isset( $_POST['wilaya_id'] ) ? sanitize_text_field( wp_unslash( $_POST['wilaya_id'] ) ) : '';

        // Accept either numeric ID or full DZ-XX code; normalize to ID.
        $wilaya_id = $this->parse_wilaya_identifier( $wilaya_raw );

        if ( $wilaya_id <= 0 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid wilaya.', 'algeria-localities-woocommerce' ) ) );
        }

        $communes = $this->importer()->get_communes_by_wilaya( $wilaya_id );

        if ( empty( $communes ) ) {
            wp_send_json_error( array( 'message' => __( 'No communes found for selected wilaya.', 'algeria-localities-woocommerce' ) ) );
        }

        $options = array();

        foreach ( $communes as $commune_id => $data ) {
            $options[] = array(
                'id'    => (int) $commune_id,
                'value' => $this->importer()->build_label(
                    isset( $data['latin'] ) ? $data['latin'] : '',
                    isset( $data['arabic'] ) ? $data['arabic'] : ''
                ),
            );
        }

        wp_send_json_success(
            array(
                'options' => $options,
            )
        );
    }

    /**
     * Enqueue front-end CSS/JS and localize settings.
     */
    public function enqueue_assets() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return;
        }

        // CSS for RTL and dropdown tweaks.
        wp_enqueue_style(
            'algeria-localities-css',
            AL_DZ_WC_PLUGIN_URL . 'assets/css/algeria-localities.css',
            array(),
            AL_DZ_WC_VERSION
        );

        // JS for AJAX commune loader.
        wp_enqueue_script(
            'algeria-localities-js',
            AL_DZ_WC_PLUGIN_URL . 'assets/js/algeria-localities.js',
            array( 'jquery' ),
            AL_DZ_WC_VERSION,
            true
        );

        wp_localize_script(
            'algeria-localities-js',
            'AlgeriaLocalities',
            array(
                'ajax_url'     => admin_url( 'admin-ajax.php' ),
                'nonce'        => wp_create_nonce( 'algeria_localities_checkout' ),
                'placeholder'  => __( 'Select a commune…', 'algeria-localities-woocommerce' ),
                'error'        => __( 'Unable to load communes. Please try again.', 'algeria-localities-woocommerce' ),
                'arabic_rtl'   => (bool) get_option( 'algeria_enable_arabic', false ),
                'country_code' => 'DZ',
            )
        );
    }

    /**
     * Build wilaya code, e.g. DZ-01, DZ-02.
     *
     * @param int $wilaya_id Wilaya numeric ID.
     *
     * @return string
     */
    private function build_wilaya_code( $wilaya_id ) {
        $wilaya_id = intval( $wilaya_id );
        return 'DZ-' . str_pad( (string) $wilaya_id, 2, '0', STR_PAD_LEFT );
    }

    /**
     * Parse wilaya identifier from AJAX: either "DZ-01" or "1".
     *
     * @param string $value Input.
     *
     * @return int
     */
    private function parse_wilaya_identifier( $value ) {
        $value = trim( (string) $value );

        if ( '' === $value ) {
            return 0;
        }

        if ( 0 === strpos( $value, 'DZ-' ) ) {
            $numeric = substr( $value, 3 );
        } else {
            $numeric = $value;
        }

        return intval( ltrim( $numeric, '0' ) );
    }
}

endif;

