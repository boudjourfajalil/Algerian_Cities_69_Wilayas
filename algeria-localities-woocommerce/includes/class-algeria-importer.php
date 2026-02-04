<?php
/**
 * Algeria Localities Importer.
 *
 * Handles XML parsing, storage in options, and transient-based caching.
 *
 * @package Algeria_Localities_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Algeria_Localities_Importer' ) ) :

class Algeria_Localities_Importer {

    /**
     * Singleton instance.
     *
     * @var Algeria_Localities_Importer
     */
    private static $instance = null;

    /**
     * Options keys.
     */
    const OPTION_WILAYAS   = 'algeria_wilayas';
    const OPTION_COMMUNES  = 'algeria_communes';
    const OPTION_COUNTS    = 'algeria_import_counts';

    /**
     * Get singleton instance.
     *
     * @return Algeria_Localities_Importer
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Algeria_Localities_Importer constructor.
     */
    private function __construct() {}

    /**
     * Get the default bundled XML path inside the plugin.
     *
     * @return string Absolute path or empty string.
     */
    public function get_default_xml_path() {
        $path = trailingslashit( plugin_dir_path( AL_DZ_WC_PLUGIN_FILE ) ) . 'assets/data/algeria-cities.xml';
        if ( file_exists( $path ) && is_readable( $path ) ) {
            return $path;
        }
        return '';
    }

    /**
     * Maybe handle admin POST actions for import/export/delete.
     */
    public function maybe_handle_admin_actions() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( empty( $_POST['algeria_localities_action'] ) ) {
            return;
        }

        $action = sanitize_text_field( wp_unslash( $_POST['algeria_localities_action'] ) );

        switch ( $action ) {
            case 'import':
                $this->handle_import_request();
                break;
            case 'bulk_delete':
                $this->bulk_delete();
                add_action(
                    'admin_notices',
                    function () {
                        ?>
                        <div class="notice notice-success is-dismissible">
                            <p><?php esc_html_e( 'Algeria wilayas and communes have been deleted.', 'algeria-localities-woocommerce' ); ?></p>
                        </div>
                        <?php
                    }
                );
                break;
            case 'export':
                $this->export_json();
                break;
        }
    }

    /**
     * Handle XML import request from admin form.
     */
    private function handle_import_request() {
        if ( ! isset( $_POST['algeria_localities_import_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['algeria_localities_import_nonce'] ) ), 'algeria_localities_import' ) ) {
            return;
        }

        $xml_path = '';

        // 1. File upload via form.
        if ( ! empty( $_FILES['algeria_cities_xml']['tmp_name'] ) && UPLOAD_ERR_OK === (int) $_FILES['algeria_cities_xml']['error'] ) {
            $file_type = wp_check_filetype_and_ext(
                $_FILES['algeria_cities_xml']['tmp_name'],
                $_FILES['algeria_cities_xml']['name']
            );

            if ( empty( $file_type['ext'] ) || 'xml' !== strtolower( $file_type['ext'] ) ) {
                $this->add_admin_error( __( 'Uploaded file must be a valid XML file.', 'algeria-localities-woocommerce' ) );
                return;
            }

            $upload = wp_handle_upload(
                $_FILES['algeria_cities_xml'],
                array( 'test_form' => false )
            );

            if ( isset( $upload['error'] ) ) {
                $this->add_admin_error( sprintf( __( 'Upload error: %s', 'algeria-localities-woocommerce' ), $upload['error'] ) );
                return;
            }

            $xml_path = $upload['file'];
        }

        // 2. Fallback: /wp-content/uploads/algeria-cities.xml.
        if ( empty( $xml_path ) ) {
            $uploads  = wp_upload_dir();
            $fallback = trailingslashit( $uploads['basedir'] ) . 'algeria-cities.xml';

            if ( file_exists( $fallback ) && is_readable( $fallback ) ) {
                $xml_path = $fallback;
            }
        }

        // 3. Fallback: bundled XML file shipped with the plugin (zero-config for end users).
        if ( empty( $xml_path ) ) {
            $maybe_bundled = $this->get_default_xml_path();
            if ( ! empty( $maybe_bundled ) ) {
                $xml_path = $maybe_bundled;
            }
        }

        if ( empty( $xml_path ) ) {
            $this->add_admin_error( __( 'No XML file provided, and neither /wp-content/uploads/algeria-cities.xml nor the bundled plugin XML could be found.', 'algeria-localities-woocommerce' ) );
            return;
        }

        $result = $this->import_from_file( $xml_path );

        if ( is_wp_error( $result ) ) {
            $this->add_admin_error( $result->get_error_message() );
        } else {
            $counts = $this->get_import_status();
            $this->add_admin_success(
                sprintf(
                    /* translators: 1: wilayas, 2: communes */
                    __( 'Import completed successfully: %1$s wilayas, %2$s communes.', 'algeria-localities-woocommerce' ),
                    intval( $counts['wilayas'] ),
                    intval( $counts['communes'] )
                )
            );
        }
    }

    /**
     * Import data from XML file.
     *
     * @param string $path Absolute path to XML file.
     *
     * @return true|\WP_Error
     */
    public function import_from_file( $path ) {
        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            return new WP_Error( 'al_dz_wc_missing_file', __( 'XML file not found or not readable.', 'algeria-localities-woocommerce' ) );
        }

        // Load XML securely with libxml internal errors.
        libxml_use_internal_errors( true );

        $xml = simplexml_load_file( $path );

        if ( false === $xml ) {
            $error_messages = array();
            foreach ( libxml_get_errors() as $error ) {
                $error_messages[] = trim( $error->message );
            }
            libxml_clear_errors();

            return new WP_Error(
                'al_dz_wc_invalid_xml',
                sprintf(
                    __( 'Invalid XML structure: %s', 'algeria-localities-woocommerce' ),
                    implode( '; ', $error_messages )
                )
            );
        }

        // Basic structure validation.
        if ( empty( $xml->wilayas ) || empty( $xml->communes ) ) {
            return new WP_Error(
                'al_dz_wc_missing_nodes',
                __( 'XML must contain <wilayas> and <communes> sections.', 'algeria-localities-woocommerce' )
            );
        }

        $wilayas  = array();
        $communes = array();

        // Parse wilayas.
        foreach ( $xml->wilayas->wilaya as $wilaya ) {
            $id = isset( $wilaya->wilaya_id ) ? intval( $wilaya->wilaya_id ) : 0;
            if ( $id <= 0 ) {
                continue;
            }

            $latin  = isset( $wilaya->wilaya_name_latin ) ? (string) $wilaya->wilaya_name_latin : '';
            $arabic = isset( $wilaya->wilaya_name_arabic ) ? (string) $wilaya->wilaya_name_arabic : '';

            if ( '' === $latin && '' === $arabic ) {
                continue;
            }

            $wilayas[ $id ] = array(
                'latin'  => $latin,
                'arabic' => $arabic,
            );
        }

        // Parse communes.
        foreach ( $xml->communes->commune as $commune ) {
            $commune_id = isset( $commune->commune_id ) ? intval( $commune->commune_id ) : 0;
            $wilaya_id  = isset( $commune->wilaya_id ) ? intval( $commune->wilaya_id ) : 0;

            if ( $commune_id <= 0 || $wilaya_id <= 0 ) {
                continue;
            }

            $latin  = isset( $commune->commune_name_latin ) ? (string) $commune->commune_name_latin : '';
            $arabic = isset( $commune->commune_name_arabic ) ? (string) $commune->commune_name_arabic : '';

            if ( '' === $latin && '' === $arabic ) {
                continue;
            }

            if ( ! isset( $communes[ $wilaya_id ] ) ) {
                $communes[ $wilaya_id ] = array();
            }

            $communes[ $wilaya_id ][ $commune_id ] = array(
                'latin'  => $latin,
                'arabic' => $arabic,
            );
        }

        // Store in options.
        update_option( self::OPTION_WILAYAS, $wilayas );
        update_option( self::OPTION_COMMUNES, $communes );

        // Store counts for status display.
        $counts = array(
            'wilayas'  => count( $wilayas ),
            'communes' => 0,
        );

        foreach ( $communes as $by_wilaya ) {
            $counts['communes'] += count( $by_wilaya );
        }

        update_option( self::OPTION_COUNTS, $counts );

        // Clear cached transients.
        delete_transient( AL_DZ_WC_TRANSIENT_WILAYAS );
        delete_transient( AL_DZ_WC_TRANSIENT_COMMUNES );

        return true;
    }

    /**
     * Remove stored data.
     */
    public function bulk_delete() {
        delete_option( self::OPTION_WILAYAS );
        delete_option( self::OPTION_COMMUNES );
        delete_option( self::OPTION_COUNTS );

        delete_transient( AL_DZ_WC_TRANSIENT_WILAYAS );
        delete_transient( AL_DZ_WC_TRANSIENT_COMMUNES );
    }

    /**
     * Export current data as JSON and force download.
     */
    private function export_json() {
        if ( headers_sent() ) {
            $this->add_admin_error( __( 'Cannot export data because headers have already been sent.', 'algeria-localities-woocommerce' ) );
            return;
        }

        $data = array(
            'wilayas'  => $this->get_wilayas_raw(),
            'communes' => $this->get_communes_raw(),
            'counts'   => $this->get_import_status(),
        );

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="algeria-localities.json"' );

        echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
        exit;
    }

    /**
     * Get import status (counts) for admin display.
     *
     * @return array
     */
    public function get_import_status() {
        $defaults = array(
            'wilayas'  => 0,
            'communes' => 0,
        );

        $counts = get_option( self::OPTION_COUNTS, $defaults );

        return wp_parse_args( $counts, $defaults );
    }

    /**
     * Get all wilayas (raw array).
     *
     * @return array
     */
    public function get_wilayas_raw() {
        $cached = get_transient( AL_DZ_WC_TRANSIENT_WILAYAS );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $wilayas = get_option( self::OPTION_WILAYAS, array() );

        // Cache for 24 hours.
        set_transient( AL_DZ_WC_TRANSIENT_WILAYAS, $wilayas, DAY_IN_SECONDS );

        return $wilayas;
    }

    /**
     * Get all communes (raw array).
     *
     * @return array
     */
    public function get_communes_raw() {
        $cached = get_transient( AL_DZ_WC_TRANSIENT_COMMUNES );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $communes = get_option( self::OPTION_COMMUNES, array() );

        set_transient( AL_DZ_WC_TRANSIENT_COMMUNES, $communes, DAY_IN_SECONDS );

        return $communes;
    }

    /**
     * Get communes for a specific wilaya ID.
     *
     * @param int $wilaya_id Wilaya ID.
     *
     * @return array
     */
    public function get_communes_by_wilaya( $wilaya_id ) {
        $wilaya_id = intval( $wilaya_id );
        $all       = $this->get_communes_raw();

        if ( isset( $all[ $wilaya_id ] ) && is_array( $all[ $wilaya_id ] ) ) {
            return $all[ $wilaya_id ];
        }

        return array();
    }

    /**
     * Build user-facing label according to language settings.
     *
     * @param string $latin  Latin name.
     * @param string $arabic Arabic name.
     *
     * @return string
     */
    public function build_label( $latin, $arabic ) {
        $default_language = get_option( 'algeria_default_language', 'latin' );
        $enable_arabic    = (bool) get_option( 'algeria_enable_arabic', false );

        $latin  = trim( (string) $latin );
        $arabic = trim( (string) $arabic );

        // Choose base according to default language.
        $base = ( 'arabic' === $default_language && '' !== $arabic ) ? $arabic : $latin;
        if ( '' === $base ) {
            $base = ( 'arabic' === $default_language ) ? $latin : $arabic;
        }

        // If bilingual labels enabled and we have both variants, append.
        if ( $enable_arabic && '' !== $latin && '' !== $arabic && $latin !== $arabic ) {
            if ( 'arabic' === $default_language ) {
                return sprintf( '%1$s (%2$s)', $arabic, $latin );
            }

            return sprintf( '%1$s (%2$s)', $latin, $arabic );
        }

        return $base;
    }

    /**
     * Debug helper: show first 5 wilayas and communes.
     *
     * @return string
     */
    public function get_debug_sample() {
        $wilayas  = $this->get_wilayas_raw();
        $communes = $this->get_communes_raw();

        $output = array();

        $output[] = 'Wilayas (first 5):';
        $count    = 0;
        foreach ( $wilayas as $id => $data ) {
            $output[] = sprintf(
                '  #%1$d: %2$s / %3$s',
                $id,
                isset( $data['latin'] ) ? $data['latin'] : '',
                isset( $data['arabic'] ) ? $data['arabic'] : ''
            );
            $count++;
            if ( $count >= 5 ) {
                break;
            }
        }

        $output[] = '';
        $output[] = 'Communes (first 5 by wilaya):';

        $outer = 0;
        foreach ( $communes as $wilaya_id => $items ) {
            $output[] = sprintf( '  Wilaya #%d:', $wilaya_id );

            $inner = 0;
            foreach ( $items as $commune_id => $data ) {
                $output[] = sprintf(
                    '    - Commune #%1$d: %2$s / %3$s',
                    $commune_id,
                    isset( $data['latin'] ) ? $data['latin'] : '',
                    isset( $data['arabic'] ) ? $data['arabic'] : ''
                );
                $inner++;
                if ( $inner >= 5 ) {
                    break;
                }
            }

            $outer++;
            if ( $outer >= 5 ) {
                break;
            }
        }

        return implode( "\n", $output );
    }

    /**
     * Helper: display admin error notice.
     *
     * @param string $message Message.
     */
    private function add_admin_error( $message ) {
        add_action(
            'admin_notices',
            function () use ( $message ) {
                ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html( $message ); ?></p>
                </div>
                <?php
            }
        );
    }

    /**
     * Helper: display admin success notice.
     *
     * @param string $message Message.
     */
    private function add_admin_success( $message ) {
        add_action(
            'admin_notices',
            function () use ( $message ) {
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html( $message ); ?></p>
                </div>
                <?php
            }
        );
    }
}

endif;

