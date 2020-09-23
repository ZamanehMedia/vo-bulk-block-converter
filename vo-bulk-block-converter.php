<?php
/**
 *
 * @link https://tech.zamaneh.com
 * @since 1.0.0
 *
 * @wordpress-plugin
 * Plugin Name: VO Bulk Block Converter
 * Plugin URI: https://tech.zamaneh.com
 * Description: Convert all classic content to blocks. An extremely useful tool when upgrading to the WordPress 5 Gutenberg editor.
 * Version: 1.0.0
 * Requires at least: 5.4
 * Requires PHP: 5.6
 * Author: Zamaneh Media, Van Ons
 * Author URI: https://en.radiozamaneh.com
 * License: MIT
 * License URI: https://spdx.org/licenses/MIT.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

define( 'VO_DOMAIN', 'vo' );                   				 // Text Domain
define( 'VO_SLUG', 'bulk-block-conversion' );      		 // Plugin slug
define( 'VO_FOLDER', plugin_dir_path( __FILE__ ) );    // Plugin folder
define( 'VO_URL', plugin_dir_url( __FILE__ ) );        // Plugin URL

//Load files
require_once VO_FOLDER . 'code/class-vo-post-table.php';

/**
 * Add plugin actions if Block Editor is active.
 */
add_action( 'plugins_loaded', 'vo_init_the_plugin' );
function vo_init_the_plugin() {
	if ( ! vo_is_gutenberg_active() ) {
		return;
	}
	// dispatching POST to GET parameters
	add_action( 'init', 'vo_dispatch_url' );
	// adding subitem to the Tools menu item
	add_action( 'admin_menu', 'vo_display_menu_item' );
	// bulk all posts convert
	add_action( 'wp_ajax_vo_bulk_convert', 'vo_bulk_convert_ajax' );
	// single post convert via ajax
	add_action( 'wp_ajax_vo_single_convert', 'vo_single_convert_ajax' );
}

function vo_get_post_types() {
    /**
     * @param array $post_types
     */
    return apply_filters('vo_bulk_get_post_types', ['post', 'page']);
}

function vo_get_post_statuses() {
    /**
     * @param array $post_statusses
     */
    return apply_filters('vo_bulk_get_post_statuses', ['publish', 'future', 'draft', 'private']);
}

/**
 * Check if Block Editor is active.
 * Must only be used after plugins_loaded action is fired.
 *
 * @return bool
 */
function vo_is_gutenberg_active() {
	// Gutenberg plugin is installed and activated.
	// $gutenberg = ! ( false === has_filter( 'replace_editor', 'gutenberg_init' ) );

	// Block editor since 5.0.
	$block_editor = version_compare( $GLOBALS['wp_version'], '5.0-beta', '>' );

	// if ( ! $gutenberg && ! $block_editor ) {
	if ( ! $block_editor ) {
		return false;
	}

	$gutenberg_plugin = function_exists( 'gutenberg_register_packages_scripts' );

	// Remove Gutenberg plugin scripts reassigning.
	if ( $gutenberg_plugin ) {
		add_action( 'wp_default_scripts', 'vo_remove_gutenberg_overrides', 5 );
	}

	return true;
}

/**
 * Remove Gutenberg plugin scripts reassigning.
 */
function vo_remove_gutenberg_overrides() {
	$pagematch = strpos( $_SERVER['REQUEST_URI'], '/wp-admin/tools.php?page=' . VO_SLUG );
	if ( $pagematch !== false ) {
		remove_action( 'wp_default_scripts', 'gutenberg_register_vendor_scripts' );
		remove_action( 'wp_default_scripts', 'gutenberg_register_packages_scripts' );
	}
}

/**
 * Adding subitem to the Tools menu item.
 */
function vo_display_menu_item() {
    $plugin_page = add_management_page(
	        __( 'Bulk Block Conversion', VO_DOMAIN ),
            __( 'Block Conversion', VO_DOMAIN ),
            'manage_options',
            VO_SLUG,
            'vo_show_admin_page'
    );

    add_action( 'load-' . $plugin_page, 'vo_load_admin_css_js' );
}

/**
 * This function is only called when our plugin's page loads!
 */
function vo_load_admin_css_js() {
    // Unfortunately we can't just enqueue our scripts here - it's too early. So register against the proper action hook to do it
    add_action( 'admin_enqueue_scripts', 'vo_enqueue_admin_css_js' );
}

/**
 * Enqueue admin styles and scripts.
 */
function vo_enqueue_admin_css_js() {
    wp_register_script( VO_DOMAIN . '-script', VO_URL . 'js/scripts.js', array( 'jquery', 'wp-blocks', 'wp-edit-post' ), false, true );
    $jsObj = array(
        'ajaxUrl'                      => admin_url( 'admin-ajax.php' ),
        'serverErrorMessage'           => '<div class="error"><p>' . __( 'Server error occured!', VO_DOMAIN ) . '</p></div>',
        'scanningMessage'              => '<p>' . sprintf( __( 'Scanning... %s%%', VO_DOMAIN ), 0 ) . '</p>',
        'bulkConvertingMessage'        => '<p>' . sprintf( __( 'Converting... %s%%', VO_DOMAIN ), 0 ) . '</p>',
        'bulkConvertingSuccessMessage' => '<div class="updated"><p>' . __( 'All posts successfully converted!', VO_DOMAIN ) . '</p></div>',
        'confirmConvertAllMessage'     => __( 'You are about to convert all classic posts in this filter to blocks. These changes are irreversible. Convert all classic posts in this filter to blocks?', VO_DOMAIN ),
        'convertingSingleMessage'      => __( 'Converting...', VO_DOMAIN ),
        'convertedSingleMessage'       => __( 'Converted', VO_DOMAIN ),
        'convertedSingleHTMLWarning'   => '<span style="color: darkred">' . __( 'Block core/html warning', VO_DOMAIN ) . '</span>',
        'failedMessage'                => __( 'Failed', VO_DOMAIN ),
    );
    wp_localize_script( VO_DOMAIN . '-script', 'voObj', $jsObj );
    wp_enqueue_script( VO_DOMAIN . '-script' );
}


/**
 * Rendering admin page of the plugin.
 */
function vo_show_admin_page() {
    @set_time_limit(0);
    ?>
<div id="vo-wrapper" class="wrap">
	<h1><?php echo get_admin_page_title(); ?></h1>

	<div id="vo-output">
		<div id="vo-table"><?php vo_render_table(); ?></div>
	</div>
</div>
	<?php
}

/**
 * Bulk converting of all indexed posts via ajax.
 */
function vo_bulk_convert_ajax() {
	header( 'Content-Type: application/json; charset=UTF-8' );

	$json  = array();
	$nonce = esc_attr( $_REQUEST['_wpnonce'] );
	if ( ! wp_verify_nonce( $nonce, 'vo_bulk_convert' ) ) {
		$json['error']   = true;
		$json['message'] = '<div class="error"><p>' . __( 'Forbidden!', VO_DOMAIN ) . '</p></div>';
		die( json_encode( $json ) );
	}

	if ( ! empty( $_GET['total'] ) ) {
        global $wpdb;
        $offset         = intval( $_GET['offset'] );
		$total_expected = intval( $_GET['total'] );

		if ( $total_expected == -1 ) {
			$total_expected = vo_get_count();
		}

		$json = array(
			'error'     => false,
			'offset'    => $total_expected,
			'total'     => $total_expected,
			'message'   => '',
			'postsData' => array(),
		);

        $args = VO_Post_Table::set_args_for_query();

        $args .= "limit 10 ";

        $table = "{$wpdb->prefix}posts";
        $select = "{$table}.ID, {$table}.post_content ";
        $query = "SELECT {$select} FROM {$table} {$args}";
        $results = $wpdb->get_results($query, ARRAY_A);

		$posts_data = array();
		foreach ( $results as $post ) {
			$posts_data[] = array(
				'id'      => $post['ID'],
				'content' => wpautop( $post['post_content'] ),
			);
			$offset++;
		}
		$json['postsData'] = $posts_data;

		$json['offset']  = $offset;
		$percentage      = (int) ( $offset / $total_expected * 100 );
		$json['message'] = '<p>' . sprintf( __( 'Converting... %s%%', VO_DOMAIN ), $percentage ) . '</p>';

		die( json_encode( $json ) );
	}

	if ( ! empty( $_POST['total'] ) ) {
		$json = array(
			'error'  => false,
			'offset' => intval( $_POST['offset'] ),
			'total'  => intval( $_POST['total'] ),
		);
		foreach ( $_POST['postsData'] as $post ) {
			$post_data = array(
				'ID'           => $post['id'],
				'post_content' => $post['content'],
			);
			if ( ! wp_update_post( $post_data ) ) {
				$json['error'] = true;
				die( json_encode( $json ) );
			}
		}
		die( json_encode( $json ) );
	}
}

/**
 * Count indexed posts by type.
 *
 * @return int
 */
function vo_get_count( ) {
    return VO_Post_Table::count_items();
}

/**
 * Display table with indexed posts.
 */
function vo_render_table() {
	?>
	<div class="meta-box-sortables ui-sortable">
        <form method="get">
            <input type="hidden" name="page" value="<?php echo VO_SLUG; ?>">
        </form>
	<?php
	$table = new VO_Post_Table();
	$table->views();
	?>
		<form method="post">
		<?php
			$table->prepare_items();
			$table->display();
		?>
		</form>
	</div>
	<?php
}

/**
 * Single post converting via ajax.
 */
function vo_single_convert_ajax() {
	header( 'Content-Type: application/json; charset=UTF-8' );

	$json = array(
		'error'   => false,
		'message' => '',
	);

	$nonce = esc_attr( $_REQUEST['_wpnonce'] );
	if ( ! wp_verify_nonce( $nonce, 'vo_convert_post_' . $_REQUEST['post'] ) ) {
		$json['error']   = true;
		$json['message'] = '<div class="error"><p>' . __( 'Forbidden!', VO_DOMAIN ) . '</p></div>';
		die( json_encode( $json ) );
	}

	if ( ! empty( $_GET['post'] ) ) {
		$post_id = intval( $_GET['post'] );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			$json['error'] = true;
			die( json_encode( $json ) );
		} else {
			$json['message'] = wpautop( $post->post_content );
			die( json_encode( $json ) );
		}
	}

	if ( ! empty( $_POST['post'] ) ) {
		$post_id   = intval( $_POST['post'] );
		$post_data = array(
			'ID'           => $post_id,
			'post_content' => $_POST['content'],
		);
		if ( ! wp_update_post( $post_data ) ) {
			$json['error'] = true;
			die( json_encode( $json ) );
		} else {
			$json['message'] = $post_id;
			die( json_encode( $json ) );
		}
	}
}

/**
 * Dispatching POST to GET parameters.
 */
function vo_dispatch_url() {
	$params = array( 'vo_post_type', 'vo_category', 'vo_from_date', 'vo_to_date', 'vo_get_errors' );

	foreach ( $params as $param ) {
		vo_post_to_get( $param );
	}
}

/**
 * Copy parameter from POST to GET or remove if does not exist or mismatch.
 *
 * @param string $parameter
 */
function vo_post_to_get( $parameter ) {
	if ( isset( $_POST[ $parameter ] ) ) {
		if ( ! empty( $_POST[ $parameter ] ) || $parameter == 'vo_from_date' ) {
			if ( empty( $_GET[ $parameter ] ) ||
				$_GET[ $parameter ] != $_POST[ $parameter ] ) {
				$_SERVER['REQUEST_URI'] = add_query_arg( array( $parameter => $_POST[ $parameter ] ) );
			}
		} else {
			if ( ! empty( $_GET[ $parameter ] ) ) {
				$_SERVER['REQUEST_URI'] = remove_query_arg( $parameter );
			}
		}
	}
}

