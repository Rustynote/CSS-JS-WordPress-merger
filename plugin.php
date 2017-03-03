<?php
/*
Plugin Name: CSS JS Queue Merger
Plugin URI: https://github.com/Rustynote/CSS-JS-queue-merger
Description: Aimed to lower number of request by merging css an js files while minifying them.
Author: Jaroslav Suhanek
Author URI: http://animaignis.com/
Version: 1.0.0
*/


// Gandalf it if it's accessed directly
if(!defined('ABSPATH')) exit;

if(!class_exists('CSSJSS_Merger')):

/**
 * Main Class
 *
 * @since 1.0.0
 */
final class CSSJSS_Merger {
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
           $init = new CSSJSS_Merger;
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
		$this->cache_dir  = WP_CONTENT_DIR.'/uploads/merger/';
		$this->cache_url  = WP_CONTENT_URL.'/uploads/merger/';

		$this->site_url = site_url();
		$this->lang_dir = apply_filters('wpgm_lang_dir', trailingslashit($this->plugin_dir.'languages'));
		$this->domain   = 'cssjs-merger';

		$this->options = wp_parse_args(get_option('cssjs_merger'), array(
			'allow_external' => true,
			'ignore_admin'   => false,
			'whitelist' => array(
				'*fonts.googleapis.com*'
			))
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
		register_deactivation_hook(__FILE__, array($this, 'purge_cache'));
		if(is_admin()){
			add_action('admin_init', array($this, 'admin_init'));
			add_action('admin_menu', array($this, 'admin_menu'));
			add_action('plugin_action_links_'.$this->basename, array($this, 'plugin_action_links'));
		}

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

		if(!file_exists($this->cache_dir.$this->css->file)) {
			$this->minify_css();
		}
		wp_enqueue_style($this->basename, $this->cache_url.$this->css->file, [], $this->version, 'all');

		// $wp_scripts = wp_scripts();
    }

	/**
	 * Add url and dependencies to the queue
	 *
	 * @since 1.0.0
	 * @var string $handle Style handle from wp_register_script.
	 */
	protected function add_css($handle) {
		$wp_styles = wp_styles();
		if(!isset($wp_styles->registered[$handle]))
			return;

		$dep = $wp_styles->registered[$handle];

		// Return if there's condition. probbly if IEx. There's no need to
		// include css intended for specific browser.
		if(isset($dep->extra['conditional']))
			return;

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
		$this->css->queue[$handle] = [
			'url' => $dep->src,
			'media' => $dep->args
		];
	}

	/**
	 * Merge and minify all
	 *
	 * @since 1.0.0
	 */
	protected function minify_css() {
		$css = '';
		foreach($this->css->queue as $handle => $queue) {
			// Add http or https to url if it's needed
			$protocol = 'http';
			if(is_ssl())
				$protocol = 'https';

			if(strpos($queue['url'], $protocol) === false) {
				$queue['url'] = $protocol.':'.$queue['url'];
			}

			$content = file_get_contents($queue['url']);
			// It couldnt get content form file so add style to wp queue.
			if($content === false) {
				wp_enqueue_style($handle);
				continue;
			}

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

	/**
	 * Convert relative url to absolute.
	 *
	 * @since 1.0.0
	 * @var string $content Whole file content
	 * @var string $url File url
	 */
	protected function rel_to_abs($content, $url) {
		$dir = dirname($url);
		$dirstruc = explode('/', $url);
		$dirstruc = array_reverse($dirstruc);

		$replace = [];

		// find all urls and ignore quotes
		preg_match_all('/url\((?!\s*([\'"]?(((?:https?:)?\/\/)|(?:data\:?:))))\s*(.+?)\)/', $content, $matched, PREG_SET_ORDER);
		if($matched) {
			foreach($matched as $match) {
				$match = array_filter($match);
				$url = preg_replace('/[\'"]/', '', end($match));

				$path = $dirstruc;

				// TODO: Fix this mess of a code. It's not self explanatory. At
				//       least comment it out.

				// Check if file is in different folder
				preg_match('/\.\./', end($match), $mach);
				if($mach && !empty($mach)) {
					$location = explode('/', end($match));
					foreach($mach as $key => $part) {
						unset($path[$key]);
						unset($location[$key]);
					}

					$path = array_reverse($path);
					$path = trailingslashit(implode('/', $path));
					$location = implode('/', $location);
				} else {
					$location = $url;
					$path = trailingslashit($dir);
				}

				$replace[end($match)] = $path.$location;
			}
		}

		if(!empty($replace)) {
			foreach($replace as $rel => $abs) {
				$content = str_replace($rel, $abs, $content);
			}
		}

		return $content;
	}

	/**
	 * Delete all cached files and remove the folder.
	 *
	 * @since 1.0.0
	 */
	public function purge_cache() {
		array_map('unlink', glob($this->cache_dir.'*.*'));
		rmdir($this->cache_dir);
	}

	/**
	 * Admin init. Register settings for options page.
	 *
	 * @since 1.0.0
	 */
	public function admin_init() {
		register_setting('cssjs_merger', 'cssjs_merger', array($this, 'validate'));

		if(isset($_GET['page']) && $_GET['page'] == 'cssjs_merger' && isset($_POST['purge-cache'])) {
			$this->purge_cache();
		}
	}

	/**
	 * Add options page to the Settings.
	 *
	 * @since 1.0.0
	 */
	public function admin_menu() {
		add_options_page('Queue Merger', 'CSSJS Queue Merger', 'manage_options', 'cssjs_merger', array($this, 'page_content'));
	}

	/**
	 * Add settings link to plugin page
	 */
	public function plugin_action_links($actions) {
		$actions[] = '<a href="'.menu_page_url('cssjs_merger', false).'">'.__('Settings', $this->domain).'</a>';
		return $actions;
	}

	/**
	 * Validate and sanitize the settings.
	 *
	 * @since 1.0.0
	 * @var array $old Settings submitted by options page form.
	 */
	public function validate($old) {
		$new = [];

		foreach($old as $key => $val) {
			switch($key) {
				case 'ignore_admin':
					$val = 1;
					break;
				case 'allow_external':
					$val = 1;
					break;
				case 'whitelist':
					$val = explode("\n", $val);
					break;

			}
			$new[$key] = $val;
		}

		return $new;
	}

	/**
	 * Options page content.
	 *
	 * @since 1.0.0
	 */
	public function page_content() {
		$options = $this->options;
		?>
		<div class="wrap">
	        <h2><?=__('CSSJS Queue Merger', $this->domain)?></h2>
			<p><?=sprintf(__('For this plugin to work as intended, styles and js must be properly enqueued (%s and %s). Plugin uses style/script handle name combined with version to form a hash which is then used for cache file name. <br />This mean new file will be automatically generated if plugin or theme is updated, this also means if some page requires more or different css/js new file will be generated.', $this->domain), '<a href="https://developer.wordpress.org/reference/functions/wp_enqueue_style/">wp_enqueue_style</a>', '<a href="https://developer.wordpress.org/reference/functions/wp_enqueue_script/">wp_enqueue_script</a>')?></p>
			<p><a href="https://developer.wordpress.org/themes/basics/including-css-javascript/"><?=__('How to properly include css and javascript', $this->domain)?></a></p>
	        <form method="post" action="options.php">
	            <?php settings_fields('cssjs_merger'); ?>
	            <table class="form-table">
	                <tr valign="top"><th scope="row"><label for="ignore-admin"><?=__('Ignore Administrator?', $this->domain)?></label></th>
	                    <td><input name="cssjs_merger[ignore_admin]" type="checkbox" id="ignore-admin" value="1" <?php checked(1, $options['ignore_admin']) ?>><label for="ignore-admin">Yes</label></td>
	                </tr>
	                <tr valign="top"><th scope="row"><label for="external"><?=__('Allow External?', $this->domain)?></label></th>
	                    <td><input name="cssjs_merger[allow_external]" type="checkbox" id="external" value="1" <?php checked(1, $options['allow_external']) ?>><label for="external">Yes</label></td>
	                </tr>
	                <tr valign="top"><th scope="row"><label for="whitelist"><?=__('Whitelist', $this->domain)?></label></th>
	                    <td>
							<textarea id="whitelist" name="cssjs_merger[whitelist]" cols="100" rows="10"><?=implode("\n", $options['whitelist'])?></textarea>
							<p class="description"><?=sprintf(__('Use %s as wildcard. One rule per line.', $this->domain), '<code>*</code>')?></p>
						</td>
	                </tr>
	            </table>
	            <p class="submit">
	                <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
	            </p>
	        </form>
			<h2><?=__('Cache', $this->domain)?></h2>
			<p><?=__("Currently there's no funcionality to remove outdated files from so you should do it manualy by clicking the button bellow.", $this->domain)?></p>
			<p><?=__('Current cache size:', $this->domain)?> <code>
			<?php
				// Get folder size and format it to b/kb/mb/etc
				$size = 0;
			    foreach (glob(rtrim($this->cache_dir, '/').'/*', GLOB_NOSORT) as $each) {
			        $size += is_file($each) ? filesize($each) : 0;
			    }
				$units = array('B', 'KB', 'MB', 'GB', 'TB');
				$bytes = max($size, 0);
			    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
			    $pow = min($pow, count($units) - 1);
			    $bytes /= pow(1024, $pow);

			    echo round($bytes, 2) . ' ' . $units[$pow];
			?>
			</code></p>
			<form action="" method="post">
				<input type="submit" class="button-secondary" name="purge-cache" value="<?=__('Clear Cache', $this->domain)?>" />
			</form>
	    </div>
		<?php
	}

}

if(!function_exists('CSSJSSMerger')) {
	/**
	 * Main function
	 */
	function CSSJSSMerger() {
		return CSSJSS_Merger::init();
	}
}

endif;

add_action('init', 'CSSJSSMerger');
