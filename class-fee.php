<?php

class FEE {
	const VERSION = '1.0.0';
	const MIN_VERSION = '4.0-beta3-29445-src';
	const MAX_VERSION = '4.1-beta';

	function url( $path ) {
		$url = plugin_dir_url( __FILE__ );

		if ( is_string( $path ) ) {
			$url .= ltrim( $path, '/' );
		}

		return $url;
	}

	function has_fee() {
		global $post;

		$has_fee = false;

		if (
			is_singular() &&
			post_type_supports( $post->post_type, 'front-end-editor' ) &&
			current_user_can( 'edit_post', $post->ID )
		) {
			$has_fee = true;
		}

		return apply_filters( 'has_fee', $has_fee, $post );
	}

	function edit_link( $id ) {
		return $this->add_hash_arg( array( 'edit' => 'true' ), get_permalink( $id ) );
	}

	function __construct() {
		global $wp_version;

		if (
			empty( $wp_version ) ||
			version_compare( $wp_version, self::MIN_VERSION, '<' ) ||
			version_compare( $wp_version, self::MAX_VERSION, '>' )
		) {
			return add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		}

		add_action( 'init', array( $this, 'init' ) );
	}

	function admin_notices() {
		echo '<div class="error"><p><strong>WordPress Front-end Editor</strong> currently only works between versions ' . self::MIN_VERSION . ' and ' . self::MAX_VERSION . '.</p></div>';
	}

	function init() {
		global $wp_post_statuses;

		if ( ! is_admin() && ! empty( $_GET['trashed'] ) && $_GET['trashed'] === '1' && ! empty( $_GET['ids'] ) ) {
			wp_redirect( admin_url( 'edit.php?post_type=' . get_post_type( $_GET['ids'] ) . '&trashed=1&ids=' . $_GET['ids'] ) );

			die;
		}

		// Lets auto-drafts pass as drafts by WP_Query.
		$wp_post_statuses['auto-draft']->protected = true;

		add_post_type_support( 'post', 'front-end-editor' );
		add_post_type_support( 'page', 'front-end-editor' );

		add_action( 'wp', array( $this, 'wp' ) );

		add_action( 'wp_ajax_fee_post', array( $this, 'ajax_post' ) );
		add_action( 'wp_ajax_fee_new', array( $this, 'ajax_new' ) );
		add_action( 'wp_ajax_fee_slug', array( $this, 'ajax_slug' ) );
		add_action( 'wp_ajax_fee_shortcode', array( $this, 'ajax_shortcode' ) );
		add_action( 'wp_ajax_fee_thumbnail', array( $this, 'ajax_thumbnail' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'wp_enqueue_scripts' ) );
	}

	function wp() {
		global $post;

		if ( ! empty( $_GET['get-post-lock'] ) ) {
			require_once( ABSPATH . '/wp-admin/includes/post.php' );

			wp_set_post_lock( $post->ID );

			wp_redirect( $this->edit_link( $post->ID ) );

			die;
		}

		if ( ! $this->has_fee() ) {
			return;
		}

		add_filter( 'body_class', array( $this, 'body_class' ) );
		add_filter( 'get_edit_post_link', array( $this, 'get_edit_post_link' ), 10, 3 );

		if ( force_ssl_admin() && ! is_ssl() ) {
			wp_redirect( set_url_scheme( $this->edit_link( $post->ID ), 'https' ) );

			die;
		}

		if ( $post->post_status === 'auto-draft' ) {
			$post->post_title = '';
			$post->comment_status = get_option( 'default_comment_status' );
			$post->ping_status = get_option( 'default_ping_status' );
		}

		require_once( ABSPATH . '/wp-admin/includes/admin.php' );

		add_action( 'wp_print_footer_scripts', 'wp_auth_check_html' );
		add_action( 'wp_print_footer_scripts', array( $this, 'footer' ) );
		add_action( 'wp_print_footer_scripts', array( $this, 'link_modal' ) );

		add_filter( 'post_class', array( $this, 'post_class' ) );
		add_filter( 'the_title', array( $this, 'the_title' ), 10, 2 );
		add_filter( 'the_content', array( $this, 'the_content' ), 20 );
		add_filter( 'wp_link_pages', '__return_empty_string', 20 );
		add_filter( 'post_thumbnail_html', array( $this, 'post_thumbnail_html' ), 10, 5 );
		add_filter( 'get_post_metadata', array( $this, 'get_post_metadata' ), 10, 4 );

		if ( count( get_users( array( 'fields' => 'ID', 'number' => 2 ) ) ) > 1 ) {
			add_action( 'wp_print_footer_scripts', '_admin_notice_post_locked' );
		}
	}

	function get_edit_post_link( $link, $id, $context ) {
		$post = get_post( $id );

		if ( post_type_supports( $post->post_type, 'front-end-editor' ) && ! is_admin() ) {
			return $this->edit_link( $id );
		}

		return $link;
	}

	function wp_enqueue_scripts() {

		global $post, $wp_version, $tinymce_version, $concatenate_scripts, $compress_scripts;

		if ( ! isset( $concatenate_scripts ) ) {
			script_concat_settings();
		}

		$compressed =
			$compress_scripts &&
			$concatenate_scripts &&
			isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) &&
			false !== stripos( $_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip' );

		$suffix = ( defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ) ? '' : '.min';

		if ( $this->has_fee() ) {
			wp_enqueue_style( 'wp-core-ui' , $this->url( '/css/wp-core-ui.css' ), false, self::VERSION, 'screen' );
			wp_enqueue_style( 'wp-core-ui-colors' , $this->url( '/css/wp-core-ui-colors.css' ), false, self::VERSION, 'screen' );
			wp_enqueue_style( 'buttons' );
			wp_enqueue_style( 'wp-auth-check' );

			wp_enqueue_script( 'wp-auth-check' );

			wp_enqueue_script( 'autosave-custom', $this->url( '/js/autosave.js' ), array( 'schedule', 'wp-ajax-response', 'fee' ), self::VERSION, true );
			wp_localize_script( 'autosave-custom', 'autosaveL10n', array(
				'autosaveInterval' => AUTOSAVE_INTERVAL,
				'blog_id' => get_current_blog_id()
			) );

			// Load tinymce.js when running from /src, else load wp-tinymce.js.gz (production) or tinymce.min.js (SCRIPT_DEBUG)
			$mce_suffix = false !== strpos( $wp_version, '-src' ) ? '' : '.min';

			if ( $compressed ) {
				wp_enqueue_script( 'fee-tinymce', includes_url( 'js/tinymce' ) . '/wp-tinymce.php?c=1', array(), $tinymce_version, true );
			} else {
				wp_enqueue_script( 'fee-tinymce', includes_url( 'js/tinymce' ) . '/tinymce' . $mce_suffix . '.js', array(), $tinymce_version, true );
				wp_enqueue_script( 'fee-tinymce-compat3x', includes_url( 'js/tinymce' ) . '/plugins/compat3x/plugin' . $suffix . '.js', array( 'fee-tinymce' ), $tinymce_version, true );
			}

			wp_enqueue_script( 'tinymce-image', $this->url( '/js/tinymce.image.js' ), array( 'fee-tinymce' ), self::VERSION, true );
			wp_enqueue_script( 'tinymce-insert', $this->url( '/js/tinymce.insert.js' ), array( 'fee-tinymce' ), self::VERSION, true );
			wp_enqueue_script( 'tinymce-markdown', $this->url( '/js/tinymce.markdown.js' ), array( 'fee-tinymce' ), self::VERSION, true );
			wp_enqueue_script( 'tinymce-more', $this->url( '/js/tinymce.more.js' ), array( 'fee-tinymce' ), self::VERSION, true );
			wp_enqueue_script( 'tinymce-toolbar', $this->url( '/js/tinymce.toolbar.js' ), array( 'fee-tinymce' ), self::VERSION, true );
			wp_enqueue_script( 'tinymce-view', $this->url( '/js/tinymce.view.js' ), array( 'fee-tinymce' ), self::VERSION, true );

			$tinymce_plugins = array(
				'feeImage',
				'feeMarkDown',
				'wpmore',
				'wplink',
				'wpview',
				'paste',
				'toolbar',
				'insert',
				'hr',
				'lists'
			);

			$tinymce_buttons_1 = array(
				'kitchensink',
				'formatselect',
				'bold',
				'italic',
				'strikethrough',
				'blockquote',
				'alignleft',
				'aligncenter',
				'alignright',
				'wp_more',
				'link',
				'media',
				'undo',
				'redo'
			);

			$tinymce_buttons_2 = array(
				'kitchensink',
				'removeformat',
				'pastetext',
				'hr',
				'bullist',
				'numlist',
				'outdent',
				'indent',
				'undo',
				'redo'
			);

			$tinymce = array(
				'selector' => '#fee-mce-' . $post->ID,
				'inline' => true,
				'plugins' => implode( ' ', array_unique( apply_filters( 'fee_tinymce_plugins', $tinymce_plugins ) ) ),
				'toolbar1' => implode( ' ', apply_filters( 'fee_tinymce_buttons_1', $tinymce_buttons_1 ) ),
				'toolbar2' => implode( ' ', apply_filters( 'fee_tinymce_buttons_2', $tinymce_buttons_2 ) ),
				'toolbar3' => implode( ' ', apply_filters( 'fee_tinymce_buttons_3', array() ) ),
				'toolbar4' => implode( ' ', apply_filters( 'fee_tinymce_buttons_4', array() ) ),
				'menubar' => false,
				'fixed_toolbar_container' => '#fee-bar',
				'skin' => false,
				'relative_urls' => false,
				'convert_urls' => false,
				'browser_spellcheck' => true
			);

			wp_enqueue_script( 'fee', $this->url( '/js/fee.js' ), array( 'fee-tinymce', 'wp-util', 'heartbeat', 'editor' ), self::VERSION, true );
			wp_localize_script( 'fee', 'fee', array(
				'tinymce' => apply_filters( 'fee_tinymce_config', $tinymce ),
				'postOnServer' => $post,
				'permalink' => get_sample_permalink( $post->ID )[0],
				'nonces' => array(
					'post' => wp_create_nonce( 'update-post_' . $post->ID ),
					'slug' => wp_create_nonce( 'slug-nonce_' . $post->ID )
				),
				'lock' => ! wp_check_post_lock( $post->ID ) ? implode( ':', wp_set_post_lock( $post->ID ) ) : false,
				'notices' => array(
					'autosave' => $this->get_autosave_notice()
				)
			) );
			wp_localize_script( 'fee', 'feeL10n', array(
				'saveAlert' => __( 'The changes you made will be lost if you navigate away from this page.' )
			) );

			wp_enqueue_media( array( 'post' => $post ) );

			wp_deregister_script( 'mce-view' );
			wp_enqueue_script( 'mce-view', $this->url( '/js/mce-view.js' ), array( 'shortcode', 'media-models', 'media-audiovideo', 'wp-playlist' ), self::VERSION, true );

			wp_enqueue_script( 'wplink' );
			wp_localize_script( 'wplink', 'ajaxurl', admin_url( 'admin-ajax.php' ) );

			wp_enqueue_style( 'fee-link-modal' , $this->url( '/css/link-modal.css' ), false, self::VERSION, 'screen' );
			wp_enqueue_style( 'fee' , $this->url( '/css/fee.css' ), false, self::VERSION, 'screen' );
			wp_enqueue_style( 'dashicons' );
		}

		if ( current_user_can( 'edit_posts' ) ) {
			if ( is_singular() ) {
				require_once( ABSPATH . '/wp-admin/includes/post.php' );

				$user_id = wp_check_post_lock( $post->ID );
				$user = get_userdata( $user_id );
			}

			wp_enqueue_style( 'fee-adminbar', $this->url( '/css/fee-adminbar.css' ), false, self::VERSION, 'screen' );
			wp_enqueue_script( 'fee-adminbar', $this->url( '/js/fee-adminbar.js' ), array( 'wp-util' ), self::VERSION, true );
			wp_localize_script( 'fee-adminbar', 'fee', array(
				'lock' => ( is_singular() && $user_id ) ? $user->display_name : false
			) );
		}
	}

	function post_class( $classes ) {
		$classes[] = 'fee-post';

		return $classes;
	}

	function body_class( $classes ) {
		global $post;

		$classes[] = 'fee fee-off';

		require_once( ABSPATH . '/wp-admin/includes/post.php' );

		if ( wp_check_post_lock( $post->ID ) ) {
			$classes[] = 'fee-locked';
		}

		return $classes;
	}

	function the_title( $title, $id ) {
		global $wp_the_query;

		if (
			is_main_query() &&
			$id === $wp_the_query->queried_object->ID &&
			$this->really_did_action( 'wp_head' )
		) {
			$title .= '<br class="fee-title" />';
		}

		return $title;
	}

	function the_content( $content ) {
		global $post;

		if (
			is_main_query() &&
			in_the_loop() &&
			$this->really_did_action( 'wp_head' )
		) {
			return (
				'<div id="fee-content-' . $post->ID . '" class="fee-content">' .
					'<div class="fee-content-original">' .
						$content .
					'</div>' .
					'<div id="fee-mce-' . $post->ID . '" class="fee-content-body">' .
						wpautop( $post->post_content ) .
					'</div>' .
				'</div>'
			);
		}

		return $content;
	}

	function post_thumbnail_html( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
		global $post, $wp_the_query;

		if (
			is_main_query() &&
			in_the_loop() &&
			$wp_the_query->queried_object->ID === $post_id &&
			$this->really_did_action( 'wp_head' )
		) {
			return (
				'<div class="fee-thumbnail" data-size="' . esc_attr( $size ) . '">' .
					'<div class="fee-thumbnail-wrap">' .
						$html .
					'</div>' .
					'<div class="fee-thumbnail-toolbar">' .
						'<div class="fee-edit-thumbnail dashicons dashicons-edit"></div>' .
						'<div class="fee-remove-thumbnail dashicons dashicons-no-alt"></div>' .
					'</div>' .
				'</div>'
			);
		}

		return $html;
	}

	// Not sure if this is a good idea, this could have unexpected consequences. But otherwise nothing shows up if the featured image is set in edit mode.
	function get_post_metadata( $n, $object_id, $meta_key, $single ) {
		global $wp_the_query;

		if (
			is_main_query() &&
			in_the_loop() &&
			$wp_the_query->queried_object->ID === $object_id &&
			$this->really_did_action( 'wp_head' ) &&
			$meta_key === '_thumbnail_id' &&
			$single &&
			empty( $this->fee['filtering_get_post_metadata'] )
		) {
			$this->fee['filtering_get_post_metadata'] = true;

			$thumbnail_id = get_post_thumbnail_id( $object_id );

			$this->fee['filtering_get_post_metadata'] = false;

			if ( $thumbnail_id ) {
				return $thumbnail_id;
			}

			return true;
		}
	}

	function ajax_post() {
		require_once( ABSPATH . '/wp-admin/includes/post.php' );

		if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'update-post_' . $_POST['post_ID'] ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this item.' ) ) );
		}

		$_POST['post_title'] = strip_tags( $_POST['post_title'] );

		$post_id = edit_post();

		if ( isset( $_POST['save'] ) || isset( $_POST['publish'] ) ) {
			$status = get_post_status( $post_id );

			if ( isset( $_POST['publish'] ) ) {
				switch ( $status ) {
					case 'pending':
						$message = 8;
						break;
					case 'future':
						$message = 9;
						break;
					default:
						$message = 6;
				}
			} else {
				$message = 'draft' == $status ? 10 : 1 ;
			}
		} else {
			$message = 4;
		}

		$post = get_post( $post_id );

		wp_send_json_success( array(
			'message' => $this->get_message( $post, $message ),
			'post' => $post
		) );
	}

	function ajax_new() {
		require_once( ABSPATH . '/wp-admin/includes/post.php' );

		$post = get_default_post_to_edit( isset( $_POST['post_type'] ) ? $_POST['post_type'] : 'post', true );

		wp_send_json_success( $this->edit_link( $post->ID ) );
	}

	function ajax_slug() {
		check_ajax_referer( 'slug-nonce_' . $_POST['post_ID'], '_wpnonce' );

		wp_send_json_success( get_sample_permalink( $_POST['post_ID'], $_POST['post_title'], $_POST['post_name'] )[1] );
	}

	function ajax_shortcode() {
		global $post;

		$post = get_post( $_POST['post_ID'] );

		setup_postdata( $post );

		wp_send_json_success( do_shortcode( wp_unslash( $_POST['shortcode'] ) ) );
	}

	function ajax_thumbnail() {
		check_ajax_referer( 'update-post_' . $_POST['post_ID'] );

		if ( ! current_user_can( 'edit_post', $_POST['post_ID'] ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this item.' ) ) );
		}

		if ( $_POST['thumbnail_ID'] === '-1' ) {
			if ( delete_post_thumbnail( $_POST['post_ID'] ) ) {
				wp_send_json_success( '' );
			}
		} else if ( set_post_thumbnail( $_POST['post_ID'], $_POST['thumbnail_ID'] ) ) {
			wp_send_json_success( get_the_post_thumbnail( $_POST['post_ID'], $_POST['size'] ) );
		}

		die;
	}

	function get_message( $post, $message_id, $revision_id = null ) {
		$messages = array();

		$messages['post'] = array(
			 0 => '', // Unused. Messages start at index 1.
			 1 => __('Post updated.'),
			 2 => __('Custom field updated.'),
			 3 => __('Custom field deleted.'),
			 4 => __('Post updated.'),
			/* translators: %s: date and time of the revision */
			 5 => isset( $revision_id ) ? sprintf( __('Post restored to revision from %s'), wp_post_revision_title( (int) $revision_id, false ) ) : false,
			 6 => __('Post published.'),
			 7 => __('Post saved.'),
			 8 => __('Post submitted.'),
			 9 => sprintf( __('Post scheduled for: <strong>%1$s</strong>.'),
				// translators: Publish box date format, see http://php.net/date
				date_i18n( __( 'M j, Y @ G:i' ), strtotime( $post->post_date ) ) ),
			10 => __('Post draft updated.')
		);

		$messages['page'] = array(
			 0 => '', // Unused. Messages start at index 1.
			 1 => __('Page updated.'),
			 2 => __('Custom field updated.'),
			 3 => __('Custom field deleted.'),
			 4 => __('Page updated.'),
			 5 => isset( $revision_id ) ? sprintf( __('Page restored to revision from %s'), wp_post_revision_title( (int) $revision_id, false ) ) : false,
			 6 => __('Page published.'),
			 7 => __('Page saved.'),
			 8 => __('Page submitted.' ),
			 9 => sprintf( __('Page scheduled for: <strong>%1$s</strong>.'), date_i18n( __( 'M j, Y @ G:i' ), strtotime( $post->post_date ) ) ),
			10 => __('Page draft updated.')
		);

		$messages['attachment'] = array_fill( 1, 10, __( 'Media attachment updated.' ) ); // Hack, for now.

		$messages = apply_filters( 'post_updated_messages', $messages );

		return $messages[ $post->post_type ] ? $messages[ $post->post_type ][ $message_id ] : $messages[ 'post' ][ $message_id ];
	}

	function footer() {
		global $post;

		?>
		<div class="wp-core-ui">
			<div id="fee-bar"></div>
			<div id="fee-notice-area" class="wp-core-ui">
				<div id="lost-connection-notice" class="error hidden">
					<p><span class="spinner"></span> <?php _e( '<strong>Connection lost.</strong> Saving has been disabled until you&#8217;re reconnected.' ); ?>
					<span class="hide-if-no-sessionstorage"><?php _e( 'We&#8217;re backing up this post in your browser, just in case.' ); ?></span>
					</p>
				</div>
			</div>
			<div id="local-storage-notice" class="hidden">
				<p class="local-restore">
					The backup of this post in your browser is different from the version below. <a class="restore-backup" href="#">Restore the backup.</a>
				</p>
				<p class="undo-restore hidden">
					Post restored successfully. <a class="undo-restore-backup" href="#">Undo.</a>
				</p>
				<div class="dashicons dashicons-dismiss"></div>
			</div>
			<input type="hidden" id="post_ID" name="post_ID" value="<?php echo $post->ID; ?>">
			<div class="fee-toolbar">
				<?php if ( in_array( $post->post_status, array( 'auto-draft', 'draft', 'pending' ) ) ) { ?>
					<button class="button fee-save"><?php _e( 'Save' ); ?></button>
					<button class="button button-primary fee-publish"><?php _e( 'Publish' ); ?></button>
				<?php } else { ?>
					<button class="button button-primary fee-save"><?php _e( 'Update' ); ?></button>
				<?php } ?>
			</div>
			<div class="fee-alert fee-leave">
				<div class="fee-alert-body">
					<p><?php _e( 'The changes you made will be lost if you navigate away from this page.' ); ?></p>
					<button class="button fee-cancel">Cancel</button>
					<?php if ( in_array( $post->post_status, array( 'auto-draft', 'draft', 'pending' ) ) ) { ?>
						<button class="button fee-save-and-exit"><?php _e( 'Save and leave' ); ?></button>
					<?php } else { ?>
						<button class="button fee-save-and-exit"><?php _e( 'Update and leave' ); ?></button>
					<?php } ?>
					<button class="button button-primary fee-exit">Leave</button>
				</div>
			</div>
		</div>
		<?php
	}

	function really_did_action( $tag ) {
		$count = did_action( $tag );

		return $this->doing_action( $tag ) ? $count - 1 : $count;
	}

	function doing_action( $tag ) {
		global $wp_current_filter;

		return in_array( $tag, $wp_current_filter );
	}

	function link_modal() {
		if ( ! class_exists( '_WP_Editors' ) ) {
			require( ABSPATH . WPINC . '/class-wp-editor.php' );
		}

		_WP_Editors::wp_link_dialog();
	}

	function get_autosave_notice() {
		global $post;

		if ( 'auto-draft' == $post->post_status ) {
			$autosave = false;
		} else {
			$autosave = wp_get_post_autosave( $post->ID );
		}

		// Detect if there exists an autosave newer than the post and if that autosave is different than the post
		if ( $autosave && mysql2date( 'U', $autosave->post_modified_gmt, false ) > mysql2date( 'U', $post->post_modified_gmt, false ) ) {
			foreach ( _wp_post_revision_fields() as $autosave_field => $_autosave_field ) {
				if ( normalize_whitespace( $autosave->$autosave_field ) !== normalize_whitespace( $post->$autosave_field ) ) {
					return sprintf( __( 'There is an autosave of this post that is more recent than the version below. <a href="%s">View the autosave</a>' ), get_edit_post_link( $autosave->ID ) );
				}
			}

			// If this autosave isn't different from the current post, begone.
			wp_delete_post_revision( $autosave->ID );
		}

		return false;
	}

	function add_hash_arg( $array, $uri ) {
		if ( 0 === stripos( $uri, 'http://' ) ) {
			$protocol = 'http://';
			$uri = substr( $uri, 7 );
		} elseif ( 0 === stripos( $uri, 'https://' ) ) {
			$protocol = 'https://';
			$uri = substr( $uri, 8 );
		} else {
			$protocol = '';
		}

		if ( strpos( $uri, '#' ) !== false ) {
			list( $base, $query ) = explode( '#', $uri, 2 );
			$base .= '#';
		} elseif ( $protocol || strpos( $uri, '=' ) === false ) {
			$base = $uri . '#';
			$query = '';
		} else {
			$base = '';
			$query = $uri;
		}

		wp_parse_str( $query, $qs );

		$qs = urlencode_deep( $qs ); // this re-URL-encodes things that were already in the query string
		$qs = array_merge( $qs, $array );

		foreach ( $qs as $k => $v ) {
			if ( $v === false ) {
				unset( $qs[ $k ] );
			}
		}

		$return = build_query( $qs );
		$return = trim( $return, '#' );
		$return = preg_replace( '#=(&|$)#', '$1', $return );
		$return = $protocol . $base . $return;
		$return = rtrim( $return, '#' );

		return $return;
	}
}
