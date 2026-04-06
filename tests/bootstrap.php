<?php
/**
 * PHPUnit bootstrap for Peptide News Plugin tests.
 *
 * Loads WordPress stubs so unit tests can reference WP functions/classes
 * without requiring a full WordPress installation.
 *
 * @package PeptideNews\Tests
 */

// Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// ── WordPress function stubs ──────────────────────────────────────────
// Minimal stubs so the plugin files can be required without a WP runtime.
// Integration tests would use the real WP test suite instead.

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'WPINC' ) ) {
    define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'PEPTIDE_NEWS_VERSION' ) ) {
    define( 'PEPTIDE_NEWS_VERSION', '1.2.1-test' );
}
if ( ! defined( 'PEPTIDE_NEWS_PLUGIN_DIR' ) ) {
    define( 'PEPTIDE_NEWS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'PEPTIDE_NEWS_PLUGIN_URL' ) ) {
    define( 'PEPTIDE_NEWS_PLUGIN_URL', 'https://example.com/wp-content/plugins/peptide-news-plugin/' );
}
if ( ! defined( 'PEPTIDE_NEWS_PLUGIN_BASENAME' ) ) {
    define( 'PEPTIDE_NEWS_PLUGIN_BASENAME', 'peptide-news-plugin/peptide-news-plugin.php' );
}

// Stub commonly used WP functions that the plugin calls at include time.
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( $file ) {
        return trailingslashit( dirname( $file ) );
    }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( $file ) {
        return 'https://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
    }
}
if ( ! function_exists( 'plugin_basename' ) ) {
    function plugin_basename( $file ) {
        return basename( dirname( $file ) ) . '/' . basename( $file );
    }
}
if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( $string ) {
        return rtrim( $string, '/\\' ) . '/';
    }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $str ) {
        return trim( strip_tags( $str ) );
    }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( $str ) {
        return trim( strip_tags( $str ) );
    }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $val ) {
        return abs( (int) $val );
    }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
    function wp_parse_args( $args, $defaults = array() ) {
        if ( is_string( $args ) ) {
            parse_str( $args, $args );
        }
        return array_merge( $defaults, $args );
    }
}
if ( ! function_exists( 'get_option' ) ) {
    function get_option( $option, $default = false ) {
        global $_test_options;
        return isset( $_test_options[ $option ] ) ? $_test_options[ $option ] : $default;
    }
}
if ( ! function_exists( 'update_option' ) ) {
    function update_option( $option, $value, $autoload = null ) {
        global $_test_options;
        $_test_options[ $option ] = $value;
        return true;
    }
}
if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type, $gmt = 0 ) {
        if ( 'mysql' === $type ) {
            return gmdate( 'Y-m-d H:i:s' );
        }
        return time();
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return ( $thing instanceof WP_Error );
    }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $string, $remove_breaks = false ) {
        $string = strip_tags( $string );
        if ( $remove_breaks ) {
            $string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
        }
        return trim( $string );
    }
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
        return true;
    }
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
        return true;
    }
}
if ( ! function_exists( 'add_settings_error' ) ) {
    function add_settings_error( $setting, $code, $message, $type = 'error' ) {
        global $_test_settings_errors;
        $_test_settings_errors[] = compact( 'setting', 'code', 'message', 'type' );
    }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}
if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}
if ( ! function_exists( 'esc_textarea' ) ) {
    function esc_textarea( $text ) {
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}
if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( $text, $domain = 'default' ) {
        return esc_html( $text );
    }
}

// Stub WP_Error for permission checks.
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $errors  = array();
        public $error_data = array();

        public function __construct( $code = '', $message = '', $data = '' ) {
            if ( $code ) {
                $this->errors[ $code ][] = $message;
                if ( $data ) {
                    $this->error_data[ $code ] = $data;
                }
            }
        }

        public function get_error_code() {
            $codes = array_keys( $this->errors );
            return $codes ? $codes[0] : '';
        }

        public function get_error_message( $code = '' ) {
            if ( ! $code ) {
                $code = $this->get_error_code();
            }
            return isset( $this->errors[ $code ] ) ? $this->errors[ $code ][0] : '';
        }
    }
}

// ── Load plugin classes for testing ───────────────────────────────────
// Content filter can be loaded standalone (no WordPress runtime needed).
require_once PEPTIDE_NEWS_PLUGIN_DIR . 'includes/class-peptide-news-content-filter.php';

// Stub WP_REST_Request for REST API tests.
if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private $params = array();
        private $method = 'GET';

        public function __construct( $method = 'GET', $route = '' ) {
            $this->method = $method;
        }

        public function set_param( $key, $value ) {
            $this->params[ $key ] = $value;
        }

        public function get_param( $key ) {
            return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
        }

        public function get_params() {
            return $this->params;
        }
    }
}
