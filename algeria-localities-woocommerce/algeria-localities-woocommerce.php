<?php
/**
 * Plugin Name: Algeria Localities for WooCommerce (69 Wilayas)
 * Description: Modern replacement for outdated Algeria states/cities in WooCommerce checkout & shipping.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Tested up to: 6.6
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 * Author: Abdeljalil Boudjourfa
 * Text Domain: algeria-localities-woocommerce
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! class_exists( 'Algeria_Localities_WooCommerce' ) ) :

/**
 * Main plugin bootstrap class.
 */
final class Algeria_Localities_WooCommerce {

    /**
     * Singleton instance.
     *
     * @var Algeria_Localities_WooCommerce
     */
    private static $instance = null;

    /**
     * Plugin version.
     */
    const VERSION = '1.0.0';

    /**
     * Plugin ID for settings tab.
     */
    const SETTINGS_ID = 'algeria_localities';

    /**
     * Get singleton instance.
     *
     * @return Algeria_Localities_WooCommerce
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Algeria_Localities_WooCommerce constructor.
     */
    private function __construct() {
        $this->define_constants();
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Define constants.
     */
    private function define_constants() {
        define( 'AL_DZ_WC_VERSION', self::VERSION );
        define( 'AL_DZ_WC_PLUGIN_FILE', __FILE__ );
        define( 'AL_DZ_WC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
        define( 'AL_DZ_WC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
        define( 'AL_DZ_WC_TRANSIENT_WILAYAS', 'algeria_wilayas_cache' );
        define( 'AL_DZ_WC_TRANSIENT_COMMUNES', 'algeria_communes_cache' );
    }

    /**
     * Include required files.
     */
    private function includes() {
        require_once AL_DZ_WC_PLUGIN_DIR . 'includes/class-algeria-importer.php';
        require_once AL_DZ_WC_PLUGIN_DIR . 'includes/class-algeria-checkout.php';
        require_once AL_DZ_WC_PLUGIN_DIR . 'includes/class-algeria-shipping.php';
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Load text domain.
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

        // Check WooCommerce activation.
        add_action( 'plugins_loaded', array( $this, 'maybe_bootstrap' ), 20 );

        // Auto-import bundled XML on activation for zero-config setup.
        register_activation_hook( AL_DZ_WC_PLUGIN_FILE, array( $this, 'on_activation' ) );

        // WooCommerce settings tab.
        add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_tab' ), 50 );
        add_action( 'woocommerce_settings_tabs_' . self::SETTINGS_ID, array( $this, 'render_settings_tab' ) );
        add_action( 'woocommerce_update_options_' . self::SETTINGS_ID, array( $this, 'save_settings_tab' ) );

        // Admin assets (for settings page).
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
    }

    /**
     * Plugin activation hook.
     *
     * Automatically imports the bundled Algeria XML file if no data is present,
     * so that users can "install and use" without manual uploads.
     */
    public function on_activation() {
        // If wilayas already exist, do not overwrite.
        $existing = get_option( Algeria_Localities_Importer::OPTION_WILAYAS, array() );
        if ( ! empty( $existing ) ) {
            return;
        }

        // Fallback to bundled XML inside the plugin.
        $default_xml = Algeria_Localities_Importer::instance()->get_default_xml_path();
        if ( $default_xml && file_exists( $default_xml ) && is_readable( $default_xml ) ) {
            Algeria_Localities_Importer::instance()->import_from_file( $default_xml );
        }
    }

    /**
     * Load plugin textdomain.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'algeria-localities-woocommerce',
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
        );
    }

    /**
     * Bootstrap plugin components after WooCommerce is loaded.
     */
    public function maybe_bootstrap() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            // WooCommerce not active; nothing else to do.
            return;
        }

        // Basic conflict detection with common "states / cities" plugins.
        if ( $this->has_conflicting_plugins() ) {
            add_action( 'admin_notices', array( $this, 'conflict_admin_notice' ) );
            return;
        }

        // Ensure data is available: if no wilayas yet, auto-import from bundled XML.
        $existing = get_option( Algeria_Localities_Importer::OPTION_WILAYAS, array() );
        if ( empty( $existing ) ) {
            $default_xml = Algeria_Localities_Importer::instance()->get_default_xml_path();
            if ( $default_xml && file_exists( $default_xml ) && is_readable( $default_xml ) ) {
                Algeria_Localities_Importer::instance()->import_from_file( $default_xml );
            }
        }

        // Initialize core components.
        Algeria_Localities_Importer::instance();
        Algeria_Localities_Checkout::instance();
        Algeria_Localities_Shipping::instance();
    }

    /**
     * Very small heuristic to detect conflicting plugins.
     *
     * @return bool
     */
    private function has_conflicting_plugins() {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';

        // Example: popular "States, Cities, and Places for WooCommerce" plugin.
        $known_conflicts = array(
            'woocommerce-states-cities-and-places/states-cities-and-places.php',
            'states-cities-and-places-for-woocommerce/states-cities-and-places-for-woocommerce.php',
        );

        foreach ( $known_conflicts as $plugin_file ) {
            if ( is_plugin_active( $plugin_file ) ) {
                return true;
            }
        }

        // Fallback: detect by class name if needed.
        if ( class_exists( 'WC_States_Cities_Places' ) ) {
            return true;
        }

        return false;
    }

    /**
     * Admin notice when a conflicting plugin is active.
     */
    public function conflict_admin_notice() {
        ?>
        <div class="notice notice-warning">
            <p>
                <?php esc_html_e( 'Algeria Localities for WooCommerce is disabled because another states/cities plugin is active. Please deactivate the other plugin to use this one.', 'algeria-localities-woocommerce' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Add custom settings tab under WooCommerce → Settings.
     *
     * @param array $tabs Existing tabs.
     *
     * @return array
     */
    public function add_settings_tab( $tabs ) {
        $tabs[ self::SETTINGS_ID ] = __( 'Algeria Localities', 'algeria-localities-woocommerce' );
        return $tabs;
    }

    /**
     * Render settings tab content.
     */
    public function render_settings_tab() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'algeria-localities-woocommerce' ) );
        }

        // Handle immediate admin actions (import/export/delete) before rendering UI.
        Algeria_Localities_Importer::instance()->maybe_handle_admin_actions();

        $importer     = Algeria_Localities_Importer::instance();
        $status       = $importer->get_import_status();
        $sample_debug = $importer->get_debug_sample();

        $default_language = get_option( 'algeria_default_language', 'latin' );
        $enable_arabic    = (bool) get_option( 'algeria_enable_arabic', false );

        ?>
        <h2><?php esc_html_e( 'Algeria Localities for WooCommerce (69 Wilayas)', 'algeria-localities-woocommerce' ); ?></h2>
        <p><?php esc_html_e( 'Import and manage Algeria wilayas and communes for modern WooCommerce checkout and shipping.', 'algeria-localities-woocommerce' ); ?></p>

        <table class="form-table">
            <tr valign="top">
                <th scope="row"><?php esc_html_e( 'Import cities.xml', 'algeria-localities-woocommerce' ); ?></th>
                <td>
                    <p>
                        <?php esc_html_e( 'Upload a custom XML file, or leave empty to import from /wp-content/uploads/algeria-cities.xml if it exists.', 'algeria-localities-woocommerce' ); ?>
                    </p>
                    <form method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field( 'algeria_localities_import', 'algeria_localities_import_nonce' ); ?>
                        <input type="file" name="algeria_cities_xml" accept=".xml" />
                        <p class="submit">
                            <button type="submit" name="algeria_localities_action" value="import" class="button button-primary">
                                <?php esc_html_e( 'Import XML', 'algeria-localities-woocommerce' ); ?>
                            </button>
                            <button type="submit" name="algeria_localities_action" value="bulk_delete" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete all imported wilayas and communes?', 'algeria-localities-woocommerce' ) ); ?>');">
                                <?php esc_html_e( 'Delete &amp; Reset', 'algeria-localities-woocommerce' ); ?>
                            </button>
                            <button type="submit" name="algeria_localities_action" value="export" class="button">
                                <?php esc_html_e( 'Export as JSON', 'algeria-localities-woocommerce' ); ?>
                            </button>
                        </p>
                    </form>
                    <p>
                        <strong><?php esc_html_e( 'Import status:', 'algeria-localities-woocommerce' ); ?></strong>
                        <?php
                        printf(
                            /* translators: 1: wilayas, 2: communes */
                            esc_html__( '%1$s wilayas, %2$s communes loaded.', 'algeria-localities-woocommerce' ),
                            intval( $status['wilayas'] ),
                            intval( $status['communes'] )
                        );
                        ?>
                    </p>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><?php esc_html_e( 'Language &amp; Labels', 'algeria-localities-woocommerce' ); ?></th>
                <td>
                    <form method="post">
                        <?php wp_nonce_field( 'algeria_localities_settings', 'algeria_localities_settings_nonce' ); ?>
                        <fieldset>
                            <label for="algeria_default_language">
                                <?php esc_html_e( 'Default label language', 'algeria-localities-woocommerce' ); ?>
                            </label>
                            <select name="algeria_default_language" id="algeria_default_language">
                                <option value="latin" <?php selected( $default_language, 'latin' ); ?>>
                                    <?php esc_html_e( 'Latin (French/English transliteration)', 'algeria-localities-woocommerce' ); ?>
                                </option>
                                <option value="arabic" <?php selected( $default_language, 'arabic' ); ?>>
                                    <?php esc_html_e( 'Arabic', 'algeria-localities-woocommerce' ); ?>
                                </option>
                            </select>
                        </fieldset>
                        <fieldset style="margin-top:8px;">
                            <label>
                                <input type="checkbox" name="algeria_enable_arabic" value="1" <?php checked( $enable_arabic, true ); ?> />
                                <?php esc_html_e( 'Show bilingual labels (e.g., "Adrar (أدرار)")', 'algeria-localities-woocommerce' ); ?>
                            </label>
                        </fieldset>
                        <p class="submit">
                            <button type="submit" name="algeria_localities_action" value="save_settings" class="button button-primary">
                                <?php esc_html_e( 'Save Language Settings', 'algeria-localities-woocommerce' ); ?>
                            </button>
                        </p>
                    </form>
                </td>
            </tr>

            <tr valign="top">
                <th scope="row"><?php esc_html_e( 'Debug sample', 'algeria-localities-woocommerce' ); ?></th>
                <td>
                    <p><?php esc_html_e( 'First 5 wilayas and communes currently in the database (for debugging).', 'algeria-localities-woocommerce' ); ?></p>
                    <textarea rows="10" cols="80" readonly="readonly" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $sample_debug ); ?></textarea>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Handle saving of language-related settings.
     */
    public function save_settings_tab() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if (
            ! isset( $_POST['algeria_localities_action'], $_POST['algeria_localities_settings_nonce'] ) ||
            'save_settings' !== sanitize_text_field( wp_unslash( $_POST['algeria_localities_action'] ) )
        ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['algeria_localities_settings_nonce'] ) ), 'algeria_localities_settings' ) ) {
            return;
        }

        $default_language = isset( $_POST['algeria_default_language'] ) ? sanitize_text_field( wp_unslash( $_POST['algeria_default_language'] ) ) : 'latin';
        $enable_arabic    = isset( $_POST['algeria_enable_arabic'] ) ? 1 : 0;

        if ( ! in_array( $default_language, array( 'latin', 'arabic' ), true ) ) {
            $default_language = 'latin';
        }

        update_option( 'algeria_default_language', $default_language );
        update_option( 'algeria_enable_arabic', (bool) $enable_arabic );

        // Flush cached data to ensure labels are regenerated consistently.
        delete_transient( AL_DZ_WC_TRANSIENT_WILAYAS );
        delete_transient( AL_DZ_WC_TRANSIENT_COMMUNES );
    }

    /**
     * Enqueue admin assets when needed (minimal for now).
     *
     * @param string $hook_suffix Current admin page hook.
     */
    public function admin_assets( $hook_suffix ) {
        // Only enqueue on WooCommerce settings pages.
        if ( false === strpos( $hook_suffix, 'woocommerce_page_wc-settings' ) ) {
            return;
        }

        // Basic styling could be placed here if needed.
    }
}

endif;

/**
 * Initialize the plugin.
 *
 * @return Algeria_Localities_WooCommerce
 */
function algeria_localities_woocommerce() {
    return Algeria_Localities_WooCommerce::instance();
}

// Kick it off.
algeria_localities_woocommerce();

