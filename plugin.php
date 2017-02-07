<?php
/*
Plugin Name: Enable-Render-Blocking
Plugin URI: https://github.com/Rustynote/Enable-Render-Blocking
Description: Aimed to fixe render blocking error by merging js and css and minifying it into two files.
Author: Jaroslav Suhanek
Author URI: http://animaignis.com/
Version: 1.0.0
*/


// Gandalf it if it's accessed directly
if(!defined('ABSPATH')) exit;

if(!class_exists('enableRenderBlocking')):

/**
 * Main Class
 *
 * @since 1.0.0
 */
final class enableRenderBlocking {
	/**
	 * Place where every byte is a happy byte.
	 * @see $this->globals()
	 * @param array
	 */
	private $data;

    /**
     * Initiate
     */
    public static function init() {
       static $init = null;

       if(null === $init) {
           $init = new enableRenderBlocking;
           $init->globals();
           $init->actions();
       }

       return $init;
    }


    /**
     * Ye who wield a class shall not have the power!
     */
    protected function __construct() {}
    protected function __clone() {}

	/**
	 * Magic method for checking the existence of data
	 *
	 * @since v0.1
	 */
	public function __isset( $key ) { return isset( $this->data[$key] ); }

	/**
	 * Magic method for getting data
	 *
	 * @since v0.1
	 */
	public function __get( $key ) { return isset( $this->data[$key] ) ? $this->data[$key] : null; }

	/**
	 * Magic method for setting data
	 *
	 * @since v0.1
	 */
	public function __set( $key, $value ) {$this->data[$key] = $value; }

	/**
	 * Magic method for unsetting data
	 *
	 * @since v0.1
	 */
	public function __unset( $key ) { if ( isset( $this->data[$key] ) ) unset( $this->data[$key] ); }

	/**
	 * Setup the globals
	 *
	 * @since v1.0.0
	 */
	private function globals() {
		/****** Plugin Data ******/
		$this->version    = '1.0.0';

		/********* Paths *********/
		$this->file       = __FILE__;
		$this->basename   = plugin_basename($this->file);
		$this->plugin_dir = plugin_dir_path($this->file);
		$this->plugin_url = plugin_dir_url($this->file);
		$this->cache_dir  = WP_CONTENT_DIR.'/uploads/erb_cache/';
		$this->cache_url  = WP_CONTENT_URL.'/uploads/erb_cache/';

		$this->site_url = site_url();
		$this->lang_dir = apply_filters('wpgm_lang_dir', trailingslashit($this->plugin_dir.'languages'));
		$this->domain   = 'erb';

		$this->options = array(
			'allow_external' => true,
			'ignore_admin'   => false,
			'whitelist' => array(
				'*fonts.googleapis.com*'
			)
		);

		// CSS dump
        $this->css         = new stdClass();
        $this->css->queue   = [];
        $this->css->handle = [];
        $this->css->file   = '';
		// Js dump
        $this->js          = new stdClass();
        $this->js->queue    = [];
        $this->js->handle  = [];
        $this->js->file    = '';
	}

    /**
     * Add actions
     *
     * @since v1.0.0
     */
    function actions() {
		register_activation_hook(__FILE__, array($this, 'activation'));

		if($this->options['ignore_admin'] && current_user_can('administrator'))
			return;

		add_action('wp_enqueue_scripts', array($this, 'run_cache_last'));
    }

    /**
     * Create cache folder if it doesn't exist on plugin activation
     *
     * @since v1.0.0
     */
    public function activation() {
        if(!file_exists($this->cache_dir)) {
            mkdir($this->cache_dir, 0777, true);
        }
        if(!file_exists($this->cache_dir.'/index.php')) {
            $index = fopen($this->cache_dir.'/index.php', 'w+');
            fclose($index);
        }
    }

    /**
     * Add cache_prepare function to the wp_enqueue_scripts with highest priority
     *
     * @since v1.0.0
     */
    public function run_cache_last() {
		if(empty($GLOBALS['wp_filter']['wp_enqueue_scripts'])) {
	        $this->cache_priority = PHP_INT_MAX;
		}

		$priorities = array_keys($GLOBALS['wp_filter']['wp_enqueue_scripts']->callbacks);
	    $last = end($priorities);

		add_action('wp_enqueue_scripts', array($this, 'cache_prepare'), $last);
    }

    /**
     * Create cache folder if it doesn't exist on plugin activation
     *
     * @since v1.0.0
     */
    public function cache_prepare() {
		// In case if folder was deleted
		$this->activation();

		$wp_styles = wp_styles();
		foreach($wp_styles->queue as $queue) {
			if(in_array($queue, ['admin-bar'])) {
				continue;
			}

			$this->add_css($queue);
		}

		$this->css->handle = implode(';', $this->css->handle);
		$this->css->file = md5($this->css->handle).'.css';

		// echo '<pre>';
		// print_r($this->css);
		// echo '</pre>';
		// exit;

		if(!file_exists($this->cache_dir.$this->css->file)) {
			$this->minify_css();
		}
		wp_enqueue_style($this->basename, $this->cache_url.$this->css->file, [], $this->version, 'all');

		// $wp_scripts = wp_scripts();
    }

	protected function add_css($handle) {
		$wp_styles = wp_styles();
		if(!isset($wp_styles->registered[$handle]))
			return;

		$dep = $wp_styles->registered[$handle];

		// Return if there's condition. probbly if IEx. There's no need to
		// include css intended for specific browser.
		if(isset($dep->extra['conditional']))
			return;

		// || !in_array($dep->args, ['all', 'screen'])

		// Check if css is external and if it's allowed, if not skip and enqueue
		// style just in case if it's dependency.
		if(!$this->options['allow_external'] && strpos($dep->src, $this->site_url) === false) {
			wp_enqueue_script($handle);
			return;
		}

		// If external is allowed, check the whitelist.
		if($this->options['allow_external'] && !empty($this->options['whitelist'])) {
			foreach($this->options['whitelist'] as $exeption) {
				if(fnmatch($exeption, $dep->src)) {
					wp_enqueue_script($handle);
					return;
				}
			}
		}

		// Add dependencies before main file
		if(!empty($dep->deps)) {
			foreach($dep->deps as $queue) {
				$this->add_css($queue);
			}
		}

		wp_dequeue_style($handle);

		$this->css->handle[] = $handle.($dep->ver ? '_'.$dep->ver : '');
		$this->css->queue[] = [
			'url' => $dep->src,
			'media' => $dep->args
		];
	}

	protected function minify_css() {
		$css = '';
		foreach($this->css->queue as $queue) {
			// Add http or https to url if it's needed
			if(strpos($queue['url'], $_SERVER['REQUEST_SCHEME']) === false) {
				$queue['url'] = $_SERVER['REQUEST_SCHEME'].':'.$queue['url'];
			}

			$content = file_get_contents($queue['url']);
			$content = $this->rel_to_abs($content, $queue['url']);

			// Add media query if it's not all
			if($queue['media'] != 'all') {
				$content = '@media '.$queue['media'].' {'.$content.'}';
			}
			$css .= $content;
		}
		$css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
	    $css = str_replace(': ', ':', $css);
	    $css = str_replace(array("\r\n", "\r", "\n", "\t", '  ', '    ', '    '), '', $css);

        $file = fopen($this->cache_dir.$this->css->file, 'w+');
		fwrite($file, $css);
		fclose($file);
	}

	protected function rel_to_abs($content, $url) {
		$dir = explode('/', dirname($url));
		$dir = array_reverse($dir);

		$replace = [];

		// find all urls and ignore quotes
		preg_match_all('/url\([\'"]?(.+?)[\'"]?\)/', $content, $matched, PREG_SET_ORDER);
		if($matched) {
			foreach($matched as $match) {
				// find only relative url
				preg_match_all('/\.\./', end($match), $mach);
				if(!is_array($mach) || empty(current($mach)))
					continue;

				$rule = end($match);

				$path = $dir;
				$match = explode('/', end($match));
				foreach(current($mach) as $key => $part) {
					unset($path[$key]);
					unset($match[$key]);
				}

				$path = array_reverse($path);
				$path = trailingslashit(implode('/', $path));
				$match = implode('/', $match);

				$replace[$rule] = $path.$match;
			}
		}

		if(!empty($replace)) {
			foreach($replace as $rel => $abs) {
				$content = str_replace($rel, $abs, $content);
			}
		}

		return $content;
	}

}

if(!function_exists('erb')) {
	/**
	 * Main function
	 */
	function erb() {
		return enableRenderBlocking::init();
	}
}

endif;

add_action('init', 'erb');
