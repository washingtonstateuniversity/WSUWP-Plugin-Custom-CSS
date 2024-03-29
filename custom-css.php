<?php
/*
Plugin Name: WSU Custom CSS
Plugin URI: https://web.wsu.edu/
Description: Custom CSS via custom post type.
Author: washingtonstateuniversity, jeremyfelt, automattic
Version: 3.0.0
*/

/**
 * The following is a fork of the custom CSS module included with Automattic's Jetpack plugin. The
 * weight of the full plugin was too much for our needs and we need to opt out of sending full
 * data back to wp.com servers. The custom css module is wonderful and we're happy to be able to
 * fork it. :)
 *
 * Our fork, like Jetpack itself is licensed as GPLv2+ http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Class WSU_Custom_CSS
 */
class WSU_Custom_CSS {

	static function init() {
		add_action( 'wp_ajax_ajax_custom_css_handle_save', array( __CLASS__, 'ajax_custom_css_handle_save' ) );

		add_action( 'wp_restore_post_revision', array( __CLASS__, 'restore_revision'   ), 10, 2 );

		// Override the edit link, the default link causes a redirect loop
		add_filter( 'get_edit_post_link',       array( __CLASS__, 'revision_post_link' ), 10, 3 );

		if ( ! is_admin() ) {
			add_filter( 'stylesheet_uri', array( __CLASS__, 'style_filter' ) );
		}

		define( 'SAFECSS_USE_ACE', apply_filters( 'safecss_use_ace', true ) );

		$args = array(
			'supports'     => array( 'revisions' ),
			'label'        => 'Custom CSS',
			'can_export'   => false,
			'rewrite'      => false,
			'capabilities' => array(
				'edit_post'          => 'edit_theme_options',
				'read_post'          => 'read',
				'delete_post'        => 'edit_theme_options',
				'edit_posts'         => 'edit_theme_options',
				'edit_others_posts'  => 'edit_theme_options',
				'publish_posts'      => 'edit_theme_options',
				'read_private_posts' => 'read'
			),
		);
		register_post_type( 'safecss', $args );

		// Short-circuit WP if this is a CSS stylesheet request
		if ( isset( $_GET['custom-css'] ) ) {
			header( 'Content-Type: text/css', true, 200 );
			header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + 31536000 ) . ' GMT' ); // 1 year
			WSU_Custom_CSS::print_css();
			exit;
		}

		add_action( 'admin_enqueue_scripts', array( 'WSU_Custom_CSS', 'enqueue_scripts' ) );

		$current_theme = wp_get_theme();

		if ( 'spine' === $current_theme->template || 'wswwp-theme-wds' === $current_theme->template ) {
			add_action( 'spine_enqueue_styles', array( 'WSU_Custom_CSS', 'link_tag' ), 10 );
		} else {
			add_action( 'wp_head', array( 'WSU_Custom_CSS', 'link_tag' ), 101 );
		}

		if ( !current_user_can( 'switch_themes' ) && !is_super_admin() ) {
			return;
		}

		add_action( 'admin_menu', array( 'WSU_Custom_CSS', 'menu' ) );

		if ( !isset( $_POST['security'] ) && isset( $_POST['safecss'] ) && false == strstr( $_SERVER[ 'REQUEST_URI' ], 'options.php' ) ) {
			self::custom_css_handle_save();
		}

		// Modify all internal links so that preview state persists
		if ( WSU_Custom_CSS::is_preview() ) {
			ob_start( array( 'WSU_Custom_CSS', 'buffer' ) );
		}
		$params = array(
		  'ajaxurl' => admin_url('admin-ajax.php'),
		  'ajax_nonce' => wp_create_nonce('custom_css')
		);
		wp_enqueue_script('jquery');
		wp_localize_script( 'jquery', 'ajax_object', $params );
	}

	/**
	 * Checks for a valid AJAX request before allowing data to save.
	 */
	public function ajax_custom_css_handle_save() {
		check_ajax_referer( 'custom_css', 'security' );
		self::custom_css_handle_save();
	}

	/**
	 * Handles the process of saving custom CSS through the admin and
	 * via AJAX requests.
	 */
	public static function custom_css_handle_save() {
		check_admin_referer( 'safecss' );

		$save_result = self::save( array(
			'css'             => stripslashes( $_POST['safecss'] ),
			'is_preview'      => isset( $_POST['action'] ) && $_POST['action'] == 'preview',
			'add_to_existing' => isset( $_POST['add_to_existing'] ) ? $_POST['add_to_existing'] == 'true' : true,
			'content_width'   => isset( $_POST['custom_content_width'] ) ? $_POST['custom_content_width'] : false,
		) );

		if ( 'preview' === $_POST['action'] ) {
			wp_safe_redirect( add_query_arg( 'csspreview', 'true', trailingslashit( home_url() ) ) );
			exit;
		}

		if ( $save_result ) {
			add_action( 'admin_notices', array( 'WSU_Custom_CSS', 'saved_message' ) );
		}
	}

	/**
	 * Save new custom CSS. This should be the entry point for any third-party code using WSU_Custom_CSS
	 * to save CSS.
	 *
	 * @param array $args {
	 *     Array of arguments:
	 *
	 *     @type string $css             The CSS (or LESS or Sass)
	 *     @type bool   $is_preview      Whether this CSS is preview or published
	 *     @type bool   $add_to_existing Whether this CSS replaces the theme's CSS or supplements it.
	 *     @type int    $content_width   A custom $content_width to go along with this CSS.
	 * }
	 *
	 * @return int The post ID of the saved Custom CSS post.
	 */
	public static function save( $args = array() ) {
		$defaults = array(
			'css' => '',
			'is_preview' => false,
			'add_to_existing' => true,
			'content_width' => false,
		);

		$args = wp_parse_args( $args, $defaults );

		if ( $args['content_width'] && intval( $args['content_width']) > 0 && ( ! isset( $GLOBALS['content_width'] ) || $args['content_width'] != $GLOBALS['content_width'] ) ) {
			$args['content_width'] = intval( $args['content_width'] );
		} else {
			$args['content_width'] = false;
		}

		// Remove wp_filter_post_kses, this causes CSS escaping issues
		remove_filter( 'content_save_pre', 'wp_filter_post_kses' );
		remove_filter( 'content_filtered_save_pre', 'wp_filter_post_kses' );
		remove_all_filters( 'content_save_pre' );

		do_action( 'safecss_save_pre', $args );

		$warnings = array();

		wsu_safecss_class();
		$csstidy = new csstidy();
		$csstidy->optimise = new wsu_safecss( $csstidy );

		$csstidy->set_cfg( 'remove_bslash',              false );
		$csstidy->set_cfg( 'compress_colors',            false );
		$csstidy->set_cfg( 'compress_font-weight',       false );
		$csstidy->set_cfg( 'optimise_shorthands',        0 );
		$csstidy->set_cfg( 'remove_last_;',              false );
		$csstidy->set_cfg( 'case_properties',            false );
		$csstidy->set_cfg( 'discard_invalid_properties', true );
		$csstidy->set_cfg( 'css_level',                  'CSS3.0' );
		$csstidy->set_cfg( 'preserve_css',               true );
		$csstidy->set_cfg( 'template',                   dirname( __FILE__ ) . '/csstidy/wordpress-standard.tpl' );

		$css = $orig = $args['css'];

		$css = preg_replace( '/\\\\([0-9a-fA-F]{4})/', '\\\\\\\\$1', $prev = $css );
		// prevent content: '\3434' from turning into '\\3434'
		$css = str_replace( array( '\'\\\\', '"\\\\' ), array( '\'\\', '"\\' ), $css );

		if ( $css != $prev ) {
			$warnings[] = 'preg_replace found stuff';
		}

		// Some people put weird stuff in their CSS, KSES tends to be greedy
		$css = str_replace( '<=', '&lt;=', $css );
		// Why KSES instead of strip_tags?  Who knows?
		$css = wp_kses_split( $prev = $css, array(), array() );
		$css = str_replace( '&gt;', '>', $css ); // kses replaces lone '>' with &gt;
		// Why both KSES and strip_tags?  Because we just added some '>'.
		$css = strip_tags( $css );

		if ( $css != $prev ) {
			$warnings[] = 'kses found stuff';
		}


		do_action( 'safecss_parse_pre', $csstidy, $css, $args );

		$csstidy->parse( $css );

		do_action( 'safecss_parse_post', $csstidy, $warnings, $args );

		$css = $csstidy->print->plain();

		if ( $args['add_to_existing'] ) {
			$add_to_existing = 'yes';
		} else {
			$add_to_existing = 'no';
		}

		if ( $args['is_preview'] ) {
			// Save the CSS
			$safecss_revision_id = WSU_Custom_CSS::save_revision( $css, true );

			// Cache Buster
			update_option( 'safecss_preview_rev', intval( get_option( 'safecss_preview_rev' ) ) + 1);

			update_metadata( 'post', $safecss_revision_id, 'custom_css_add', $add_to_existing );
			update_metadata( 'post', $safecss_revision_id, 'content_width', $args['content_width'] );

			delete_option( 'safecss_add' );
			delete_option( 'safecss_content_width' );

			if ( $args['is_preview'] ) {
				return $safecss_revision_id;
			}

			do_action( 'safecss_save_preview_post' );
		}

		// Save the CSS
		$safecss_post_id = WSU_Custom_CSS::save_revision( $css, false );

		$safecss_post_revision = WSU_Custom_CSS::get_current_revision();

		update_option( 'safecss_rev', intval( get_option( 'safecss_rev' ) ) + 1 );

		update_post_meta( $safecss_post_id, 'custom_css_add', $add_to_existing );
		update_post_meta( $safecss_post_id, 'content_width', $args['content_width'] );

		delete_option( 'safecss_add' );
		delete_option( 'safecss_content_width' );

		update_metadata( 'post', $safecss_post_revision['ID'], 'custom_css_add', $add_to_existing );
		update_metadata( 'post', $safecss_post_revision['ID'], 'content_width', $args['content_width'] );

		delete_option( 'safecss_preview_add' );

		return $safecss_post_id;
	}

	/**
	 * Get the published custom CSS post.
	 *
	 * @return array
	 */
	static function get_post() {

		$custom_css_post = WSU_Custom_CSS::post_id( true );

		if ( is_array( $custom_css_post ) ) {

			return $custom_css_post;

		}

		return array();
	}

	/**
	 * Get the post ID of the most recently published custom CSS post. As there is
	 * only one 'post' that we considered published for use as the stylesheet, we
	 * can assume that ordereding by date descending will give us the correct post.
	 *
	 * @return int|bool The post ID if it exists; false otherwise.
	 */
	static function post_id( $return_post = false ) {

		$custom_css_post_id   = wp_cache_get( 'custom_css_post_id' );
		$custom_css_post_type = wp_cache_get( 'custom_css_post_type' );
		$custom_css_post      = wp_cache_get( 'custom_css_post' );

		if ( empty( $custom_css_post ) || empty( $custom_css_post_id ) || 'safecss' !== $custom_css_post_type ) {

			// The Query.
			$css_query = new WP_Query(
				array(
					'posts_per_page' => 1,
					'post_type' => 'safecss',
					'post_status' => 'publish',
					'orderby' => 'date',
					'order' => 'DESC',
				)
			);

			// The Loop.
			if ( $css_query->have_posts() ) {

				while ( $css_query->have_posts() ) {

					$css_query->the_post();

					$custom_css_post_id = get_the_ID();

					$custom_css_post = get_post( null, ARRAY_A );

					wp_cache_set( 'custom_css_post_id', $custom_css_post_id );

					wp_cache_set( 'custom_css_post_type', get_post_type() );

					wp_cache_set( 'custom_css_post', $custom_css_post );
				}
			}
			// Restore original Post Data.
			wp_reset_postdata();

		}

		if ( empty( $custom_css_post_id ) ) {
			return false;
		}

		return ( $return_post ) ? $custom_css_post : $custom_css_post_id;
	}

	/**
	 * Get the current revision of the original safecss record
	 *
	 * @return object
	 */
	static function get_current_revision() {
		$safecss_post = WSU_Custom_CSS::get_post();

		if ( empty( $safecss_post ) ) {
			return false;
		}

		$revisions = wp_get_post_revisions( $safecss_post['ID'], array( 'posts_per_page' => 1, 'orderby' => 'date', 'order' => 'DESC' ) );

		// Empty array if no revisions exist
		if ( empty( $revisions ) ) {
			// Return original post
			return $safecss_post;
		} else {
			// Return the first entry in $revisions, this will be the current revision
			$current_revision = get_object_vars( array_shift( $revisions ) );
			return $current_revision;
		}
	}

	/**
	 * Save new revision of CSS
	 * Checks to see if content was modified before really saving
	 *
	 * @param string $css
	 * @param bool   $is_preview
	 *
	 * @return bool|int False if nothing saved. Post ID if a post or revision was saved.
	 */
	static function save_revision( $css, $is_preview = false ) {
		$safecss_post = WSU_Custom_CSS::get_post();

		$compressed_css = WSU_Custom_CSS::minify( $css );

		// If null, there was no original safecss record, so create one
		if ( null == $safecss_post ) {
			if ( ! $css ) {
				return false;
			}

			$post = array();
			$post['post_content'] = wp_slash( $css );
			$post['post_title'] = 'safecss';
			$post['post_status'] = 'publish';
			$post['post_type'] = 'safecss';
			$post['post_content_filtered'] = wp_slash( $compressed_css );

			// Set excerpt to current theme, for display in revisions list
			$current_theme = wp_get_theme();
			$post['post_excerpt'] = $current_theme->Name;

			// Insert the CSS into wp_posts
			$post_id = wp_insert_post( $post );
			wp_cache_set( 'custom_css_post_id', $post_id );
			return $post_id;
		}

		// Update CSS in post array with new value passed to this function
		$safecss_post['post_content'] = $css;
		$safecss_post['post_content_filtered'] = $compressed_css;

		// Set excerpt to current theme, for display in revisions list
		$current_theme = wp_get_theme();
		$safecss_post['post_excerpt'] = $current_theme->Name;

		// Don't carry over last revision's timestamps, otherwise revisions all have matching timestamps
		unset( $safecss_post['post_date'] );
		unset( $safecss_post['post_date_gmt'] );
		unset( $safecss_post['post_modified'] );
		unset( $safecss_post['post_modified_gmt'] );

		// Do not update post if we are only saving a preview
		if ( false === $is_preview ) {
			$safecss_post['post_content'] = wp_slash( $safecss_post['post_content'] );
			$safecss_post['post_content_filtered'] = wp_slash( $safecss_post['post_content_filtered'] );
			$post_id = wp_update_post( $safecss_post );
			wp_cache_set( 'custom_css_post_id', $post_id );
			return $post_id;
		} else if ( ! defined( 'DOING_MIGRATE' ) ) {
			return _wp_put_post_revision( $safecss_post );
		}
	}

	static function skip_stylesheet() {
		$skip_stylesheet = apply_filters( 'safecss_skip_stylesheet', null );

		if ( null !== $skip_stylesheet ) {
			return $skip_stylesheet;
		} elseif ( WSU_Custom_CSS::is_customizer_preview() ) {
			return false;
		} else {
			if ( WSU_Custom_CSS::is_preview() ) {
				$safecss_post = WSU_Custom_CSS::get_current_revision();

				if ( $safecss_post ) {
					return (bool) ( get_post_meta( $safecss_post['ID'], 'custom_css_add', true ) == 'no' );
				} else {
					return (bool) ( get_option( 'safecss_preview_add' ) == 'no' );
				}
			} else {
				$custom_css_post_id = WSU_Custom_CSS::post_id();

				if ( $custom_css_post_id ) {
					$custom_css_add = get_post_meta( $custom_css_post_id, 'custom_css_add', true );

					// It is possible for the CSS to be stored in a post but for the safecss_add option
					// to have not been upgraded yet if the user hasn't opened their Custom CSS editor
					// since October 2012.
					if ( ! empty( $custom_css_add ) ) {
						return (bool) ( $custom_css_add === 'no' );
					}
				}

				return (bool) ( get_option( 'safecss_add' ) == 'no' );
			}
		}
	}

	static function is_preview() {
		return isset( $_GET['csspreview'] ) && $_GET['csspreview'] === 'true';
	}

	static function get_css( $compressed = false ) {
		$default_css = apply_filters( 'safecss_get_css_error', false );

		if ( $default_css !== false ) {
			return $default_css;
		}

		$option = ( WSU_Custom_CSS::is_preview() ) ? 'safecss_preview' : 'safecss';

		if ( 'safecss' == $option ) {
			$current_revision = WSU_Custom_CSS::get_current_revision();
			if ( false === $current_revision ) {
				$css = '';
			} else {
				$css = ( $compressed && $current_revision['post_content_filtered'] ) ? $current_revision['post_content_filtered'] : $current_revision['post_content'];
			}

			// Fix for un-migrated Custom CSS
			if ( empty( $safecss_post ) ) {
				$_css = get_option( 'safecss' );
				if ( !empty( $_css ) ) {
					$css = $_css;
				}
			}
		}
		else if ( 'safecss_preview' == $option ) {
			$safecss_post = WSU_Custom_CSS::get_current_revision();
			$css = $safecss_post['post_content'];
			$css = WSU_Custom_CSS::minify( $css );
		}

		$css = str_replace( array( '\\\00BB \\\0020', '\0BB \020', '0BB 020' ), '\00BB \0020', $css );

		if ( empty( $css ) ) {
			$css = "/*\n"
				. wordwrap(
					apply_filters(
						'safecss_default_css',
						__(
							"Welcome to Custom CSS!\n\nCSS (Cascading Style Sheets) is a kind of code that tells the browser how to render a web page. You may delete these comments and get started with your customizations.\n\nBy default, your stylesheet will be loaded after the theme stylesheets, which means that your rules can take precedence and override the theme CSS rules. Just write here what you want to change, you don't need to copy all your theme's stylesheet content.",
							'jetpack'
						)
					)
				)
				. "\n*/";
		}

		$css = apply_filters( 'safecss_css', $css );

		return $css;
	}

	static function print_css() {
		do_action( 'safecss_print_pre' );

		echo WSU_Custom_CSS::get_css( true );
	}

	static function link_tag() {
		global $blog_id, $current_blog;

		$current_theme = wp_get_theme();

		if ( apply_filters( 'safecss_style_error', false ) ) {
			return;
		}

		if ( ! is_super_admin() && isset( $current_blog ) && ( 1 == $current_blog->spam || 1 == $current_blog->deleted ) ) {
			return;
		}

		if ( WSU_Custom_CSS::is_customizer_preview() && 'wsuwp-theme-wds' !== $current_theme->template ) {
			return;
		}

		$css    = '';
		$option = WSU_Custom_CSS::is_preview() ? 'safecss_preview' : 'safecss';

		if ( 'safecss' == $option ) {
			if ( get_option( 'safecss_revision_migrated' ) ) {
				$safecss_post = WSU_Custom_CSS::get_post();

				if ( ! empty( $safecss_post['post_content'] ) ) {
					$css = $safecss_post['post_content'];
				}
			} else {
				$current_revision = WSU_Custom_CSS::get_current_revision();

				if ( ! empty( $current_revision['post_content'] ) ) {
					$css = $current_revision['post_content'];
				}
			}

			// Fix for un-migrated Custom CSS
			if ( empty( $safecss_post ) ) {
				$_css = get_option( 'safecss' );
				if ( !empty( $_css ) ) {
					$css = $_css;
				}
			}
		}

		if ( 'safecss_preview' == $option ) {
			$safecss_post = WSU_Custom_CSS::get_current_revision();

			if ( !empty( $safecss_post['post_content'] ) ) {
				$css = $safecss_post['post_content'];
			}
		}

		$css = str_replace( array( '\\\00BB \\\0020', '\0BB \020', '0BB 020' ), '\00BB \0020', $css );

		if ( $css == '' ) {
			return;
		}

		$href = home_url( '/' );
		$href = add_query_arg( 'custom-css', 1, $href );
		$href = add_query_arg( 'csblog', $blog_id, $href );
		$href = add_query_arg( 'cscache', 6, $href );
		$href = add_query_arg( 'csrev', (int) get_option( $option . '_rev' ), $href );

		$href = apply_filters( 'safecss_href', $href, $blog_id );

		if ( WSU_Custom_CSS::is_preview() ) {
			$href = add_query_arg( 'csspreview', 'true', $href );
		}

		// We plan on the style being enqueued in the Spine parent theme. This should be considered temporary
		// until we can rewrite to handle more than the Spine theme.
		if ( 'spine' === $current_theme->template ) {
			wp_enqueue_style( 'spine-custom-css', $href, array(), spine_get_script_version() );
		} elseif ( 'wsuwp-theme-wds' === $current_theme->template ){
			wp_enqueue_style( 'wsu-custom-css', $href, array(), '0.0.1' );
		} else {
			?>
			<link rel="stylesheet" id="custom-css-css" type="text/css" href="<?php echo esc_url( $href ); ?>" />
			<?php
		}


		do_action( 'safecss_link_tag_post' );
	}

	static function style_filter( $current ) {
		if ( ! WSU_Custom_CSS::is_preview() || ! current_user_can( 'switch_themes' ) ) {
			return $current;
		} else if ( WSU_Custom_CSS::skip_stylesheet() ) {
			return apply_filters( 'safecss_style_filter_url', plugins_url( 'blank.css', __FILE__ ) );
		}

		return $current;
	}

	static function buffer( $html ) {
		$html = str_replace( '</body>', WSU_Custom_CSS::preview_flag(), $html );
		return preg_replace_callback( '!href=([\'"])(.*?)\\1!', array( 'WSU_Custom_CSS', 'preview_links' ), $html );
	}

	static function preview_links( $matches ) {
		if ( 0 !== strpos( $matches[2], get_option( 'home' ) ) ) {
			return $matches[0];
		}

		$link = wp_specialchars_decode( $matches[2] );
		$link = add_query_arg( 'csspreview', 'true', $link );
		$link = esc_url( $link );
		return "href={$matches[1]}$link{$matches[1]}";
	}

	/**
	 * Places a black bar above every preview page
	 */
	static function preview_flag() {
		if ( is_admin() ) {
			return;
		}

		$message = esc_html__( 'Preview: changes must be saved or they will be lost', 'jetpack' );
		$message = apply_filters( 'safecss_preview_message', $message );

		$preview_flag_js = "var flag = document.createElement('div');
	flag.innerHTML = " . json_encode( $message ) . ";
	flag.style.background = '#981e32';
	flag.style.color = 'white';
	flag.style.textAlign = 'center';
	flag.style.padding = '5px 0px 5px 0px';
	flag.style.opacity = '0.8';
	flag.style.position = 'absolute';
	flag.style.width = '100%';
	flag.style['z-index'] = '99999';
	document.body.style.paddingTop = '0px';
	document.body.insertBefore(flag, document.body.childNodes[0]);
	";

		$preview_flag_js = apply_filters( 'safecss_preview_flag_js', $preview_flag_js );
		if ( $preview_flag_js ) {
			$preview_flag_js = '<script type="text/javascript">
	// <![CDATA[
	' . $preview_flag_js . '
	// ]]>
	</script>';
		}

		return $preview_flag_js;
	}

	static function menu() {
		$title = __( 'Edit CSS', 'jetpack' );
		$hook = add_theme_page( $title, $title, 'edit_theme_options', 'editcss', array( 'WSU_Custom_CSS', 'admin' ) );

		add_action( "load-revision.php", array( 'WSU_Custom_CSS', 'prettify_post_revisions' ) );
		add_action( "load-$hook", array( 'WSU_Custom_CSS', 'update_title' ) );
	}

	/**
	 * Adds a menu item in the appearance section for this plugin's administration
	 * page. Also adds hooks to enqueue the CSS and JS for the admin page.
	 */
	static function update_title() {
		global $title;
		$title = __( 'CSS', 'jetpack' );
	}

	static function prettify_post_revisions() {
		add_filter( 'the_title', array( 'WSU_Custom_CSS', 'post_title' ), 10, 2 );
	}

	static function post_title( $title, $post_id ) {
		if ( !$post_id = (int) $post_id ) {
			return $title;
		}

		if ( !$post = get_post( $post_id ) ) {
			return $title;
		}

		if ( 'safecss' != $post->post_type ) {
			return $title;
		}

		return __( 'Custom CSS Stylesheet', 'jetpack' );
	}

	static function enqueue_scripts( $hook ) {
		if ( 'appearance_page_editcss' != $hook ) {
			return;
		}

		wp_enqueue_script( 'postbox' );
		wp_enqueue_script( 'custom-css-editor', plugins_url( 'js/css-editor.js', __FILE__ ), 'jquery', '20130325', true );
		wp_enqueue_style( 'custom-css-editor', plugins_url( 'css/css-editor.css', __FILE__ ) );

		if ( defined( 'SAFECSS_USE_ACE' ) && SAFECSS_USE_ACE ) {
			wp_register_style( 'jetpack-css-codemirror', plugins_url( 'css/codemirror.css', __FILE__ ), array(), '20170417' );
			wp_enqueue_style( 'jetpack-css-use-codemirror', plugins_url( 'css/use-codemirror.css', __FILE__ ), array( 'jetpack-css-codemirror' ), '20120905' );
			wp_enqueue_style( 'jetpack-css-use-codemirrordialog', plugins_url( 'css/dialog.css', __FILE__ ), array( 'jetpack-css-codemirror' ), '20120905' );
			wp_enqueue_style( 'jetpack-css-use-codemirrormatchesonscrollbar', plugins_url( 'css/matchesonscrollbar.css', __FILE__ ), array( 'jetpack-css-codemirror' ), '20120905' );

			wp_register_script( 'jetpack-css-codemirror', plugins_url( 'js/codemirror.min.js', __FILE__ ), array(), '20170417', true );
			wp_enqueue_script( 'jetpack-css-use-codemirror', plugins_url( 'js/use-codemirror.js', __FILE__ ), array( 'jquery', 'underscore', 'jetpack-css-codemirror' ), '20170417', true );

			wp_enqueue_script( 'jetpack-css-fullscreen', plugins_url( 'js/fullscreen.js', __FILE__ ), array( 'jquery', 'underscore', 'jetpack-css-codemirror' ), '20131009', true );
			wp_enqueue_script( 'jetpack-css-xmls', plugins_url( 'js/xml.js', __FILE__ ), array( 'jquery', 'underscore', 'jetpack-css-codemirror' ), '20131009', true );
			wp_enqueue_script( 'jetpack-css-dialog', plugins_url( 'js/dialog.js', __FILE__ ), array( 'jquery', 'underscore', 'jetpack-css-codemirror' ), '20131009', true );
			wp_enqueue_script( 'jetpack-css-searchcursor', plugins_url( 'js/searchcursor.js', __FILE__ ), array( 'jquery', 'underscore', 'jetpack-css-codemirror' ), '20131009', true );
			wp_enqueue_script( 'jetpack-css-search', plugins_url( 'js/search.js', __FILE__ ), array( 'jquery', 'underscore', 'jetpack-css-codemirror' ), '20131009', true );
			wp_enqueue_script( 'jetpack-css-annotatescrollbar', plugins_url( 'js/annotatescrollbar.js', __FILE__ ), array( 'jquery', 'underscore', 'jetpack-css-codemirror' ), '20131009', true );
			wp_enqueue_script( 'jetpack-css-matchesonscrollbar', plugins_url( 'js/matchesonscrollbar.js', __FILE__ ), array( 'jquery', 'underscore', 'jetpack-css-codemirror' ), '20131009', true );
			wp_enqueue_script( 'jetpack-css-jump-to-line', plugins_url( 'js/jump-to-line.js', __FILE__ ), array( 'jquery', 'underscore', 'jetpack-css-codemirror' ), '20131009', true );

			wp_enqueue_script('jquery-ui-dialog');
			wp_enqueue_style('wp-jquery-ui-dialog');
		}
	}

	static function saved_message() {
		echo '<div id="message" class="updated fade"><p><strong>' . __( 'Stylesheet saved.', 'jetpack' ) . '</strong></p></div>';
	}

	static function admin() {
		add_meta_box( 'submitdiv', __( 'Publish', 'jetpack' ), array( __CLASS__, 'publish_box' ), 'editcss', 'side' );
		add_action( 'custom_css_submitbox_misc_actions', array( __CLASS__, 'content_width_settings' ) );

		$safecss_post = WSU_Custom_CSS::get_post();

		if ( ! empty( $safecss_post ) && 0 < $safecss_post['ID'] && wp_get_post_revisions( $safecss_post['ID'] ) ) {
			add_meta_box( 'revisionsdiv', __( 'CSS Revisions', 'jetpack' ), array( __CLASS__, 'revisions_meta_box' ), 'editcss', 'side' );
			add_meta_box( 'helpdiv', __( 'Help', 'jetpack' ), array( __CLASS__, 'help_meta_box' ), 'editcss', 'side' );
		}

		?>
		<div class="wrap">
			<?php do_action( 'custom_design_header' ); ?>
			<h1><?php _e( 'CSS Stylesheet Editor', 'jetpack' ); ?></h1>
			<form id="safecssform" action="" method="post">
				<?php wp_nonce_field( 'safecss' ) ?>
				<?php wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false ); ?>
				<?php wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false ); ?>
				<input type="hidden" name="action" value="save" />
				<div id="poststuff">
					<p class="css-support"><?php echo apply_filters( 'safecss_intro_text', __( 'New to CSS? Start with a <a href="http://www.htmldog.com/guides/cssbeginner/">beginner tutorial</a>. Questions?
		Ask in the <a href="http://wordpress.org/support/forum/themes-and-templates">Themes and Templates forum</a>.', 'jetpack' ) ); ?></p>
					<div id="post-body" class="metabox-holder columns-2">
						<div id="post-body-content">
							<div class="postarea">
								<textarea id="safecss" name="safecss"<?php if ( SAFECSS_USE_ACE ) echo ' class="hide-if-js"'; ?>><?php echo esc_textarea( WSU_Custom_CSS::get_css() ); ?></textarea>
								<div class="clear"></div>
							</div>
						</div>
						<div id="postbox-container-1" class="inner-sidebar">
							<?php do_meta_boxes( 'editcss', 'side', $safecss_post ); ?>
						</div>
					</div>
					<br class="clear" />
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Content width setting callback
	 */
	static function content_width_settings() {
		$safecss_post = WSU_Custom_CSS::get_current_revision();

		$custom_content_width = get_post_meta( $safecss_post['ID'], 'content_width', true );

		// If custom content width hasn't been overridden and the theme has a content_width value, use that as a default.
		if ( $custom_content_width <= 0 && ! empty( $GLOBALS['content_width'] ) ) {
			$custom_content_width = $GLOBALS['content_width'];
		}

		if ( ! $custom_content_width || ( isset( $GLOBALS['content_width'] ) && $custom_content_width == $GLOBALS['content_width'] ) ) {
			$custom_content_width = '';
		}

		?>
		<div class="misc-pub-section">
			<label><?php esc_html_e( 'Content Width:', 'jetpack' ); ?></label>
			<span id="content-width-display" data-default-text="<?php esc_attr_e( 'Default', 'jetpack' ); ?>" data-custom-text="<?php esc_attr_e( '%s px', 'jetpack' ); ?>"><?php echo $custom_content_width ? sprintf( esc_html__( '%s px', 'jetpack' ), $custom_content_width ) : esc_html_e( 'Default', 'jetpack' ); ?></span>
			<a class="edit-content-width hide-if-no-js" href="#content-width"><?php echo esc_html_e( 'Edit', 'jetpack' ); ?></a>
			<div id="content-width-select" class="hide-if-js">
				<input type="hidden" name="custom_content_width" id="custom_content_width" value="<?php echo esc_attr( $custom_content_width ); ?>" />
				<p>
					<?php

					printf(
						__( 'Limit width to %1$s pixels for videos, full size images, and other shortcodes. (<a href="%2$s">More info</a>.)', 'jetpack' ),
						'<input type="text" id="custom_content_width_visible" value="' . esc_attr( $custom_content_width ) . '" size="4" />',
						apply_filters( 'safecss_limit_width_link', 'http://jetpack.me/support/custom-css/#limited-width' )
					);

					?>
				</p>
				<?php

				if ( !empty( $GLOBALS['content_width'] ) && $custom_content_width != $GLOBALS['content_width'] ) {
					$current_theme = wp_get_theme()->Name;
					?>
					<p><?php printf( __( 'The default content width for the %s theme is %d pixels.', 'jetpack' ), $current_theme, intval( $GLOBALS['content_width'] ) ); ?></p>
					<?php
				}

				?>
				<a class="save-content-width hide-if-no-js button" href="#content-width"><?php esc_html_e( 'OK', 'jetpack' ); ?></a>
				<a class="cancel-content-width hide-if-no-js" href="#content-width"><?php esc_html_e( 'Cancel', 'jetpack' ); ?></a>
			</div>
			<script type="text/javascript">
				jQuery( function ( $ ) {
					var defaultContentWidth = <?php echo isset( $GLOBALS['content_width'] ) ? json_encode( intval( $GLOBALS['content_width'] ) ) : 0; ?>;

					$( '.edit-content-width' ).bind( 'click', function ( e ) {
						e.preventDefault();

						$( '#content-width-select' ).slideDown();
						$( this ).hide();
					} );

					$( '.cancel-content-width' ).bind( 'click', function ( e ) {
						e.preventDefault();

						$( '#content-width-select' ).slideUp( function () {
							$( '.edit-content-width' ).show();
							$( '#custom_content_width_visible' ).val( $( '#custom_content_width' ).val() );
						} );
					} );

					$( '.save-content-width' ).bind( 'click', function ( e ) {
						e.preventDefault();

						$( '#content-width-select' ).slideUp();

						var newContentWidth = parseInt( $( '#custom_content_width_visible' ).val(), 10 );

						if ( newContentWidth && newContentWidth != defaultContentWidth ) {
							$( '#content-width-display' ).text(
								$( '#content-width-display' )
									.data( 'custom-text' )
										.replace( '%s', $( '#custom_content_width_visible' ).val() )
							);
						}
						else {
							$( '#content-width-display' ).text( $( '#content-width-display' ).data( 'default-text' ) );
						}

						$( '#custom_content_width' ).val( $( '#custom_content_width_visible' ).val() );
						$( '.edit-content-width' ).show();
					} );
				} );
			</script>
		</div>
		<?php
	}

	static function publish_box() {
		?>
		<div id="minor-publishing">
			<div id="misc-publishing-actions">
				<?php

				$safecss_post = WSU_Custom_CSS::get_current_revision();

				$add_css = ( get_post_meta( $safecss_post['ID'], 'custom_css_add', true ) != 'no' );

				?>
				<div class="misc-pub-section">
					<label><?php esc_html_e( 'Mode:', 'jetpack' ); ?></label>
					<span id="css-mode-display"><?php echo esc_html( $add_css ? __( 'Add-on', 'jetpack' ) : __( 'Replacement', 'jetpack' ) ); ?></span>
					<a class="edit-css-mode hide-if-no-js" href="#css-mode"><?php echo esc_html_e( 'Edit', 'jetpack' ); ?></a>
					<div id="css-mode-select" class="hide-if-js">
						<input type="hidden" name="add_to_existing" id="add_to_existing" value="<?php echo $add_css ? 'true' : 'false'; ?>" />
						<p>
							<label>
								<input type="radio" name="add_to_existing_display" value="true" <?php checked( $add_css ); ?>/>
								<?php _e( 'Add-on CSS <b>(Recommended)</b>', 'jetpack' ); ?>
							</label>
							<br />
							<label>
								<input type="radio" name="add_to_existing_display" value="false" <?php checked( ! $add_css ); ?>/>
								<?php printf( __( 'Replace <a href="%s">theme\'s CSS</a> <b>(Advanced)</b>', 'jetpack' ), apply_filters( 'safecss_theme_stylesheet_url', get_stylesheet_uri() ) ); ?>
							</label>
						</p>
						<a class="save-css-mode hide-if-no-js button" href="#css-mode"><?php esc_html_e( 'OK', 'jetpack' ); ?></a>
						<a class="cancel-css-mode hide-if-no-js" href="#css-mode"><?php esc_html_e( 'Cancel', 'jetpack' ); ?></a>
					</div>
				</div>
				<?php do_action( 'custom_css_submitbox_misc_actions' ); ?>
			</div>
		</div>
		<div id="major-publishing-actions">
			<input type="button" class="button" id="preview" name="preview" value="<?php esc_attr_e( 'Preview', 'jetpack' ) ?>" />
			<div id="publishing-action">
				<input type="submit" class="button-primary" id="save" name="save" value="<?php esc_attr_e( 'Save Stylesheet', 'jetpack' ); ?>" />
			</div>
		</div>
		<?php
	}

	/**
	 * Render metabox listing CSS revisions and the themes that correspond to the revisions.
	 * Called by safecss_admin
	 *
	 * @param array $safecss_post
	 *
	 * @return string
	 */
	static function revisions_meta_box( $safecss_post ) {

		$show_all_revisions = isset( $_GET['show_all_rev'] );

		$max_revisions = defined( 'WP_POST_REVISIONS' ) && is_numeric( WP_POST_REVISIONS ) ? (int) WP_POST_REVISIONS : 25;

		$posts_per_page = $show_all_revisions ? $max_revisions : 6;

		$revisions = new WP_Query( array(
			'posts_per_page' => $posts_per_page,
			'post_type' => 'revision',
			'post_status' => 'inherit',
			'post_parent' => $safecss_post['ID'],
			'orderby' => 'date',
			'order' => 'DESC'
		) );

		if ( $revisions->have_posts() ) { ?>
			<ul class="post-revisions"><?php

			global $post;

			while ( $revisions->have_posts() ) {
				$revisions->the_post();
				$author = get_the_author_meta( 'display_name', $post->post_author );
				$age = human_time_diff( strtotime( $post->post_modified ), current_time( 'timestamp' ) );
				$link = get_edit_post_link( $post->ID );
				?><li><?php echo get_avatar( $post->post_author, 24 ) . ' ' . $author . ' <a href="' . esc_url( $link ) . '">' . $age . ' ago</a>'; ?></li><?php
			}

			?></ul><?php

			if ( $revisions->found_posts > 6 && !$show_all_revisions ) {
				?>
				<br>
				<a href="<?php echo add_query_arg( 'show_all_rev', 'true', menu_page_url( 'editcss', false ) ); ?>"><?php esc_html_e( 'Show all', 'jetpack' ); ?></a>
				<?php
			}
		}

		wp_reset_query();
	}

	/**
	 * Render help metabox 
	 * Called by safecss_admin
	 *
	 * @param array 
	 *
	 * @return string
	 */
	static function help_meta_box(  ) {
		?>
<dl>
	  <dt>Ctrl-S / Cmd-S</dt><dd>Save without leaving page.</dd>
	  <dt>Esc</dt><dd>Fullscreen enter/exit</dd>
	  <dt>Ctrl-F / Cmd-F</dt><dd>Start searching</dd>
	  <dt>Ctrl-G / Cmd-G</dt><dd>Find next</dd>
	  <dt>Shift-Ctrl-G / Shift-Cmd-G</dt><dd>Find previous</dd>
	  <dt>Shift-Ctrl-F / Cmd-Option-F</dt><dd>Replace</dd>
	  <dt>Shift-Ctrl-R / Shift-Cmd-Option-F</dt><dd>Replace all</dd>
	  <dt>Alt-F</dt><dd>Persistent search (dialog doesn't autoclose,
	  enter to find next, Shift-Enter to find previous)</dd>
	  <dt>Alt-G / Cmd-L</dt><dd>Jump to line</dd>
	</dl>
		<?php
	}

	/**
	 * Hook in init at priority 11 to disable custom CSS.
	 */
	static function disable() {
		remove_action( 'wp_head', array( 'WSU_Custom_CSS', 'link_tag' ), 101 );
		remove_filter( 'stylesheet_uri', array( 'WSU_Custom_CSS', 'style_filter' ) );
	}

	/**
	 * Reset all aspects of Custom CSS on a theme switch so that changing
	 * themes is a sure-fire way to get a clean start.
	 */
	static function reset() {
		$safecss_post_id = WSU_Custom_CSS::save_revision( '' );
		$safecss_revision = WSU_Custom_CSS::get_current_revision();

		update_option( 'safecss_rev', intval( get_option( 'safecss_rev' ) ) + 1 );

		update_post_meta( $safecss_post_id, 'custom_css_add', 'yes' );
		update_post_meta( $safecss_post_id, 'content_width', false );

		delete_option( 'safecss_add' );
		delete_option( 'safecss_content_width' );

		update_metadata( 'post', $safecss_revision['ID'], 'custom_css_add', 'yes' );
		update_metadata( 'post', $safecss_revision['ID'], 'content_width', false );

		delete_option( 'safecss_preview_add' );
	}

	static function is_customizer_preview() {
		if ( isset ( $GLOBALS['wp_customize'] ) ) {
			return ! $GLOBALS['wp_customize']->is_theme_active();
		}

		return false;
	}

	static function minify( $css ) {
		if ( ! $css ) {
			return '';
		}

		wsu_safecss_class();
		$csstidy = new csstidy();
		$csstidy->optimise = new wsu_safecss( $csstidy );

		$csstidy->set_cfg( 'remove_bslash',              false );
		$csstidy->set_cfg( 'compress_colors',            true );
		$csstidy->set_cfg( 'compress_font-weight',       true );
		$csstidy->set_cfg( 'remove_last_;',              true );
		$csstidy->set_cfg( 'case_properties',            true );
		$csstidy->set_cfg( 'discard_invalid_properties', true );
		$csstidy->set_cfg( 'css_level',                  'CSS3.0' );
		$csstidy->set_cfg( 'template', 'highest');
		$csstidy->parse( $css );

		return $csstidy->print->plain();
	}

	/**
	 * When restoring a SafeCSS post revision, also copy over the
	 * content_width and custom_css_add post metadata.
	 */
	static function restore_revision( $_post_id, $_revision_id ) {
		$_post = get_post( $_post_id );

		if ( 'safecss' != $_post->post_type ) {
			return;
		}

		$safecss_revision = WSU_Custom_CSS::get_current_revision();

		$content_width = get_post_meta( $_revision_id, 'content_width', true );
		$custom_css_add = get_post_meta( $_revision_id, 'custom_css_add', true );

		update_metadata( 'post', $safecss_revision['ID'], 'content_width', $content_width );
		update_metadata( 'post', $safecss_revision['ID'], 'custom_css_add', $custom_css_add );

		delete_option( 'safecss_add' );
		delete_option( 'safecss_content_width' );

		update_post_meta( $_post->ID, 'content_width', $content_width );
		update_post_meta( $_post->ID, 'custom_css_add', $custom_css_add );

		delete_option( 'safecss_preview_add' );
	}

	static function revision_post_link( $post_link, $post_id, $context ) {
		if ( !$post_id = (int) $post_id ) {
			return $post_link;
		}

		if ( !$post = get_post( $post_id ) ) {
			return $post_link;
		}

		if ( 'safecss' != $post->post_type ) {
			return $post_link;
		}

		$post_link = admin_url( 'themes.php?page=editcss' );

		if ( 'display' == $context ) {
			return esc_url( $post_link );
		}

		return esc_url_raw( $post_link );
	}

	/**
	 * Override the content_width with a custom value if one is set.
	 */
	static function jetpack_content_width( $content_width ) {
		$custom_content_width = 0;

		if ( WSU_Custom_CSS::is_preview() ) {
			$safecss_post = WSU_Custom_CSS::get_current_revision();
			$custom_content_width = intval( get_post_meta( $safecss_post['ID'], 'content_width', true ) );
		} else {
			$custom_css_post_id = WSU_Custom_CSS::post_id();
			if ( $custom_css_post_id ) {
				$custom_content_width = intval( get_post_meta( $custom_css_post_id, 'content_width', true ) );
			}
		}

		if ( $custom_content_width > 0 ) {
			$content_width = $custom_content_width;
		}

		return $content_width;
	}
}

class WSU_Safe_CSS {
	static function filter_attr( $css, $element = 'div' ) {
		wsu_safecss_class();

		$css = $element . ' {' . $css . '}';

		$csstidy = new csstidy();
		$csstidy->optimise = new wsu_safecss( $csstidy );
		$csstidy->set_cfg( 'remove_bslash', false );
		$csstidy->set_cfg( 'compress_colors', false );
		$csstidy->set_cfg( 'compress_font-weight', false );
		$csstidy->set_cfg( 'discard_invalid_properties', true );
		$csstidy->set_cfg( 'merge_selectors', false );
		$csstidy->set_cfg( 'remove_last_;', false );
		$csstidy->set_cfg( 'css_level', 'CSS3.0' );

		$css = preg_replace( '/\\\\([0-9a-fA-F]{4})/', '\\\\\\\\$1', $css );
		$css = wp_kses_split( $css, array(), array() );
		$csstidy->parse( $css );

		$css = $csstidy->print->plain();

		$css = str_replace( array( "\n","\r","\t" ), '', $css );

		preg_match( "/^{$element}\s*{(.*)}\s*$/", $css, $matches );

		if ( empty( $matches[1] ) ) {
			return '';
		}

		return $matches[1];
	}
}

function wsu_safecss_class() {
	// Wrapped so we don't need the parent class just to load the plugin
	if ( class_exists('wsu_safecss') ) {
		return;
	}

	require_once( dirname( __FILE__ ) . '/csstidy/class.csstidy.php' );

	class wsu_safecss extends csstidy_optimise {

		function postparse() {
			do_action( 'csstidy_optimize_postparse', $this );

			return parent::postparse();
		}

		function subvalue() {
			do_action( 'csstidy_optimize_subvalue', $this );

			return parent::subvalue();
		}
	}
}

if ( ! function_exists( 'safecss_filter_attr' ) ) {
	function safecss_filter_attr( $css, $element = 'div' ) {
		return WSU_Safe_CSS::filter_attr( $css, $element );
	}
}

add_action( 'init', array( 'WSU_Custom_CSS', 'init' ) );
