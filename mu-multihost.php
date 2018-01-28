<?php
/*
 * Plugin Name: mu-multihost
 * Plugin URI: https://github.com/iantsch/mu-multihost
 * Description: WordPress must-use plugin to access the same instance on multiple domains with optional theme switch and front page switch.
 * Version: 0.1.0
 * Author: Christian Tschugg
 * Author URI: http://mbt.wien
 * Copyright: Christian Tschugg
 * Text Domain: mbt
*/

namespace MBT {

    define( __NAMESPACE__ . '\Multihost\DIR', __DIR__ . DIRECTORY_SEPARATOR );

    class Multihost {

        /**
         * @var Multihost;
         */
        protected static $instance;

        /**
         * @var string
         */
        private static $host;

        /**
         * @var array
         */
        private $domains = array();

        /**
         * Singleton Constructor
         *
         * @return Multihost
         */
        static public function init() {
            if (is_null(self::$instance)) {
                self::$instance = new self;
            }
            return self::$instance;
        }

        /**
         * Multihost constructor.
         */
        private function __construct() {
            self::$host          = $_SERVER['SERVER_NAME'];
            $this->registerDomains();
            $this->registerHooks();
        }

        /**
         * Register available domains from options table
         */
        private function registerDomains() {
            $domains = maybe_unserialize(get_option('mu-multihost', ''));
            if ($domains) {
                foreach ($domains['hosts'] as $domain) {
                    $this->addDomain($domain['domain'], $domain['theme'], $domain['front_page']);
                }
            }
        }

        /**
         * Register a domain and its optional theme and front page
         *
         * @param string $domain
         * @param string|null $theme
         * @param int|null $frontPage
         */
        private function addDomain( $domain, $theme = null, $frontPage = null ) {
            if (empty($domain)) {
                return;
            }
            $this->domains[ $domain ] = array(
                'theme' => $theme,
                'page_on_front' => $frontPage,
            );
        }

        /**
         * Register WordPress Action and Filter Hooks
         */
        private function registerHooks() {
            add_filter( 'option_siteurl', array( $this, 'getHostAsSiteurl' ) );
            add_filter( 'option_home', array( $this, 'getHostAsSiteurl' ) );
            add_filter( 'content_url', array( $this, 'getHostAsSiteurl' ) );
            add_filter( 'plugins_url', array( $this, 'getHostAsSiteurl' ) );
            add_filter( 'option_template', array( $this, 'getThemeByHost' ) );
            add_filter( 'option_stylesheet', array( $this, 'getThemeByHost' ) );
            add_filter( 'pre_option_show_on_front', array( $this, 'showOnFrontByHost' ) );
            add_filter( 'pre_option_page_on_front', array( $this, 'pageOnFrontByHost' ) );
            add_action( 'admin_init',  array( $this, 'registerSettings' ) );
            add_action( 'admin_menu', array($this, 'registerMenu' ) );
        }

        /**
         * Return the theme for the current host or default theme
         *
         * @param $theme
         *
         * @return string theme
         */
        public function getThemeByHost( $theme ) {
            if ( array_key_exists( self::$host, $this->domains ) ) {
                if ( empty( $this->domains[ self::$host ]['theme'] ) ) {
                    return $theme;
                }
                return $this->domains[ self::$host ]['theme'];
            }
            return $theme;
        }

        /**
         * Return the hosts domain as site url or default url
         *
         * @param string $value
         *
         * @return string site url
         */
        public function getHostAsSiteurl( $value ) {
            if ( array_key_exists( self::$host, $this->domains ) ) {
                return $this->buildUrl( $value, self::$host );
            }
            return $value;
        }

        /**
         * Build Url from given $host
         *
         * @param $url
         * @param $host
         *
         * @return string Url from given host
         */
        private function buildUrl( $url, $host ) {
            $url_parts = parse_url( $url );
            if ( is_array( $url_parts ) ) {
                $url = ( isset( $url_parts['scheme'] ) ? $url_parts['scheme'] . '://' : '' );
                $url .= ( isset( $url_parts['user'] ) ? $url_parts['user'] . ':' : '' );
                $url .= ( isset( $url_parts['pass'] ) ? $url_parts['pass'] . '@' : '' );
                $url .= ( isset( $url_parts['host'] ) ? $host : '' );
                $url .= ( isset( $url_parts['port'] ) ? ':' . $url_parts['port'] : '' );
                $url .= ( isset( $url_parts['path'] ) ? $url_parts['path'] : '' );
                $url .= ( isset( $url_parts['query'] ) ? '?' . $url_parts['query'] : '' );
                $url .= ( isset( $url_parts['fragment'] ) ? '#' . $url_parts['fragment'] : '' );
            }
            return $url;
        }

        /**
         * What to show (page or home) on front for current host
         *
         * @param $option
         *
         * @return string
         */
        public function showOnFrontByHost($option) {
            if ( array_key_exists( self::$host, $this->domains ) ) {
                if ( empty( $this->domains[ self::$host ]['page_on_front'] ) ) {
                    return $option;
                }
                return 'page';
            }
            return $option;
        }

        /**
         * Page on front for current host
         *
         * @param $post_id
         *
         * @return int post_id of page to display as front page
         */
        public function pageOnFrontByHost($post_id) {
            if ( array_key_exists( self::$host, $this->domains ) ) {
                if ( !empty( $this->domains[ self::$host ]['page_on_front'] ) ) {
                    return $post_id;
                }
                return $this->domains[ self::$host ]['page_on_front'];
            }
            return $post_id;
        }

        public function registerSettings() {
            register_setting( 'mu-multihost', 'mu-multihost' );
            add_settings_section(
                'mu-multihost',
                __( 'Settings', 'mbt' ),
                array($this, 'renderSettings'),
                'mu-multihost'
            );

            // register a new field in the "wporg_section_developers" section, inside the "wporg" page
            add_settings_field(
                'hosts',
                __( 'Hosts', 'mbt' ),
                array($this, 'renderField'),
                'mu-multihost',
                'mu-multihost',
                array(
                    'label_for' => 'hosts'
                )
            );
        }

        public function renderSettings($args) {
            echo "<p id='".esc_attr( $args['id'] )."'>".esc_html__( 'Enter your hosts, themes and front pages for multi-domain access', 'mbt' )."</p>";
        }

        public function renderField($args) {
            $options = maybe_unserialize(get_option( 'mu-multihost', '' ));?>
            <style>
                .host {
                    display: flex;
                    align-items: center;
                }
            </style>
            <div class="hosts">
                <?php if ($options):
                    foreach ($options[$args['label_for']] as $i => $option){
                        echo $this->getHost($i, $option);
                    }
                endif;?>
            </div>
            <button class="button" data-mbt="add-host">
                <?php _e('Add Host');?>
            </button>
            <script type="text/html" id="mbt-template-host">
                <?php echo $this->getHost();?>
            </script>
            <script>
                (function($) {
                    $('body').on('click', "[data-mbt='add-host']", function(e) {
                        e.preventDefault();
                        var $hosts = $(this).prev(),
                            template = $('#mbt-template-host').text();
                        $hosts.append($(template.split('###').join($hosts.children().length)));
                    }).on('click', "[data-mbt='remove-host']", function(e) {
                        e.preventDefault();
                        var $hosts = $(this).closest('.hosts').children(),
                            length = $hosts.length,
                            toRemove = $hosts.index($(this).parent()),
                            modifyOthers = false;
                        if ((length - 1) > toRemove) {
                            modifyOthers = true;
                        }
                        if (modifyOthers) {
                            for(++toRemove; toRemove < length; ++toRemove) {
                                $hosts.eq(toRemove).find('[name]').each(function() {
                                    $(this).attr('name', $(this).attr('name').split('['+toRemove+']').join('['+(toRemove - 1)+']'));
                                })
                            }
                        }
                        $(this).parent().remove();
                    });
                })( jQuery );
            </script>
            <?php
        }

        private function getHost($index = '###', $row = ['domain' => '', 'theme'=>'', 'front_page'=>'']) {
            $html = '<div class="host">';
            $html .= '<input type="text" name="mu-multihost[host]['.$index.'][domain]" placeholder="'.__('Domain','mbt').'" value="'.$row['domain'].'">';
            $html .= '<select  name="mu-multihost[host]['.$index.'][theme]">';
            foreach($this->getAvailableThemes() as $theme) {
                $html .= '<option value="'.$theme.'" '.selected($theme, $row['theme'], false).'>'.$theme.'</option>';
            }
            $html .= '</select>';
            $html .= '<input type="text" name="mu-multihost[host]['.$index.'][front_page]" placeholder="'.__('Front Page','mbt').'" value="'.$row['front_page'].'">';
            $html .= '<button class="button" data-mbt="remove-host">';
            $html .= __('&times;');
            $html .= '</button>';
            $html .= '</div>';
            return $html;
        }

        private function getAvailableThemes() {
            return array_keys(wp_get_themes());
        }

        public function registerMenu() {
            add_options_page(__('Multihost', 'mbt'),__('Multihost','mbt'),'manage_options','mu-multihost', array($this, 'renderSettingsPage'));
        }

        public function renderSettingsPage() {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            if ( isset( $_GET['settings-updated'] ) ) {
                add_settings_error( 'mbt_messages', 'mbt_message', __( 'Hosts saved', 'mbt' ), 'updated' );
            }
            settings_errors( 'mbt_messages' );
            ?>
            <div class="wrap">
                <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
                <form action="options.php" method="post">
                    <?php
                    settings_fields( 'mu-multihost' );
                    do_settings_sections( 'mu-multihost' );
                    submit_button( __('Save','mbt') );
                    ?>
                </form>
            </div>
            <?php
        }
    }
}

namespace {

    use MBT\Multihost;

    Multihost::init();
}
