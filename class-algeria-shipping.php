<?php
/**
 * Algeria Localities Shipping Zones.
 *
 * Ensures that all wilayas are available as shipping locations for Algeria.
 *
 * @package Algeria_Localities_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Algeria_Localities_Shipping' ) ) :

class Algeria_Localities_Shipping {

    /**
     * Singleton instance.
     *
     * @var Algeria_Localities_Shipping
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return Algeria_Localities_Shipping
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
        // Add wilaya codes to shipping zone locations when evaluated.
        add_filter( 'woocommerce_shipping_zone_locations', array( $this, 'add_algeria_wilayas_zones' ), 10, 2 );
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
     * Add all wilaya codes (DZ-01 … DZ-69) as valid locations for zones that target Algeria.
     *
     * This does not forcibly rewrite existing zones, but when WooCommerce evaluates
     * zone locations it will see all available wilaya codes as candidates.
     *
     * @param array            $locations Existing locations.
     * @param WC_Shipping_Zone $zone      Zone object.
     *
     * @return array
     */
    public function add_algeria_wilayas_zones( $locations, $zone ) {
        $wilayas = $this->importer()->get_wilayas_raw();

        if ( empty( $wilayas ) ) {
            return $locations;
        }

        // Check whether this zone includes Algeria at country level.
        $has_algeria = false;
        foreach ( $locations as $loc ) {
            if ( isset( $loc->type, $loc->code ) && 'country' === $loc->type && 'DZ' === $loc->code ) {
                $has_algeria = true;
                break;
            }
        }

        if ( ! $has_algeria ) {
            return $locations;
        }

        // Create a map of existing state codes to avoid duplicates.
        $existing_states = array();
        foreach ( $locations as $loc ) {
            if ( isset( $loc->type, $loc->code ) && 'state' === $loc->type && 0 === strpos( $loc->code, 'DZ-' ) ) {
                $existing_states[ $loc->code ] = true;
            }
        }

        // Add all wilaya codes (DZ-01 …) as state locations.
        foreach ( $wilayas as $id => $data ) {
            $code = 'DZ-' . str_pad( (string) intval( $id ), 2, '0', STR_PAD_LEFT );

            if ( isset( $existing_states[ $code ] ) ) {
                continue;
            }

            $location          = new stdClass();
            $location->code    = $code;
            $location->type    = 'state';
            $location->zone_id = $zone->get_id();

            $locations[] = $location;
        }

        return $locations;
    }
}

endif;

