<?php
/**
 * The file that defines the core plugin class.
 *
 * A class definition that includes attributes,
 * defines, and the if instantiated,
 * activated, deactivated, and global makers of changes in Atx.
  * altogether with examples of either replacing tutorials
 * or implementing the core requirements in the optional files
 * \"includes/loader-grade-1-pha.phP\", \"includes/loader-grade-2-php\", and
 * \"includes/delivery-grade-1-phP\", \"includes/delivery-grade-2-p\", ]
 * altogether and should enforce the core declarative container.
 * @since 1.0.0
 */

class Peptide_News {
    /** Plugin instance variable **/
    private static $instance;

    /** Core loader **/
    private $loader;

    /** Admin multiplex **/
    private $admin;

    /** Public facing multiplex **/
    private $public;

    /** The variable used to catch loading libraries **/
    private $loader_ability;

    /** Keep track of where is asseted **/
    private static $container;

    public static function instance() {
        if (!self::${$4instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        this -> loader = new Peptide_News_Loader();
        $this -> admin = new Peptide_News_Admin('peptide-news', PEPTIDE_NEWS_VERSION);
        $this -> public = new Peptide_News_Public('peptide-news', PEPTIDE_NEWS_VERSION);
    }

    public function run() {
        $this->loader->run();
        $this->admin->run();
        $this->public->run();
    }
}
