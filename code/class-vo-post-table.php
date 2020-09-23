<?php
/**
 * Include table class file.
 */
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Custom table class.
 */
class VO_Post_Table extends WP_List_Table {

    public static $default_from_date = '2020-01-01';
    public static $total_items = 0;

    /** Class constructor */
    public function __construct() {

        parent::__construct(
            [
                'singular' => __( 'Post', VO_DOMAIN ), // singular name of the listed records
                'plural'   => __( 'Posts', VO_DOMAIN ), // plural name of the listed records
                'ajax'     => false, // should this table support ajax?
            ]
        );

    }

    /**
     * Set common arguments for table rendering query.
     *
     * @return string
     */
    public static function set_args_for_query() {
        global $wpdb;

        if ( empty( $_REQUEST['vo_get_errors'] ) ) {
            $args = "WHERE (post_content != '' AND post_content NOT LIKE '%<!-- wp:%') ";
        } else {
            $args = "WHERE (post_content != '' AND post_content LIKE '%<!-- wp:html -->%') ";
        }

        $posts_table = "{$wpdb->prefix}posts";

        $post_types    = vo_get_post_types();

        if ( ! empty( $_REQUEST['vo_category'] ) ) {
            $join_table = "{$wpdb->prefix}term_relationships";
            $args = "JOIN {$join_table} tr ON {$posts_table}.ID = tr.object_id " . $args;
            $args .= "AND tr.term_taxonomy_id = {$_REQUEST['vo_category']} ";
        }

        if ( ! empty( $_REQUEST['vo_post_type'] ) ) {
            $args .= "AND post_type = '{$_REQUEST['vo_post_type']}' ";
        } else {
            $args .= "AND (";
            foreach ($post_types as $index => $post_type) {
                if ($index) {
                    $args .= " OR ";
                }
                $args .= "post_type = '{$post_type}'";
            }
            $args .= ") ";
        }

        $post_statuses = vo_get_post_statuses();
        $args .= "AND (";
        foreach ($post_statuses as $index => $post_type) {
            if ($index) {
                $args .= " OR ";
            }
            $args .= "post_status = '{$post_type}'";
        }
        $args .= ") ";

        $from_date = null;
        if ( ! empty ( $_REQUEST['vo_from_date'] ) ) {
            $from_date = $_REQUEST['vo_from_date'];
        } else if( ! isset ( $_REQUEST['vo_from_date'] ) ) {
            $from_date = self::$default_from_date;
        }

        if ( $from_date && ! empty ( $_REQUEST['vo_to_date'] ) ) {
            $from = date('Y-m-d 00:00:00', strtotime($from_date));
            $to = date('Y-m-d 23:59:59', strtotime($_REQUEST['vo_to_date']));
            $args .= "AND post_date BETWEEN '{$from}' AND '{$to}' ";
        } else if ( $from_date ) {
            $from = date('Y-m-d 00:00:00', strtotime($from_date));
            $args .= "AND post_date > '{$from}' ";
        } else if ( ! empty ( $_REQUEST['vo_to_date'] ) ) {
            $to = date('Y-m-d 23:59:59', strtotime($_REQUEST['vo_to_date']));
            $args .= "AND post_date < '{$to}' ";
        }

        if ( ! empty( $_REQUEST['vo_category'] ) ) {
            $args .= "GROUP BY ID ";
        }

        if ( ! empty( $_REQUEST['orderby'] ) &&  ! empty( $_REQUEST['order'] ) ) {
            $args .= "ORDER BY {$_REQUEST['orderby']} {$_REQUEST['order']} ";
        } else {
            $args .= "ORDER BY post_date DESC ";
        }

        return $args;
    }

    /**
     * Get posts with 'bblock_not_converted' meta field
     *
     * @param int $per_page
     * @param int $page_number
     *
     * @return mixed
     */
    public static function get_posts( $per_page = 20, $page_number = 1 ) {
        global $wpdb;

        $args = self::set_args_for_query();

        $offset = ($per_page * $page_number) - $per_page;
        $args .= "limit {$offset}, $per_page ";

        $table = "{$wpdb->prefix}posts";
        $select = "{$table}.*";
        $query = "SELECT {$select} FROM {$table} {$args}";
        $results = $wpdb->get_results($query, ARRAY_A);

        $items = [];
        foreach ( $results as $post ) {
            $items[] = array(
                'ID'         => $post['ID'],
                'post_title' => $post['post_title'],
                'post_type'  => $post['post_type'],
                'post_date'  => $post['post_date'],
                'action'     => '',
            );
        }

        return $items;
    }

    /**
     * Return the count of posts that need to be converted.
     *
     * @return int
     */
    public static function count_items() {
        global $wpdb;

        $args = self::set_args_for_query();
        $select = 'count(ID)';

        $table = "{$wpdb->prefix}posts";
        $query = "SELECT {$select} FROM {$table} {$args}";
        $result = $wpdb->get_results($query, ARRAY_A);

        if (count($result) > 1) {
            return count($result);
        } elseif (isset($result[0][$select])) {
            return $result[0][$select];
        } else {
            return 0;
        }
    }

    /** Text displayed when no data is available */
    public function no_items() {
        _e( 'No items available.', VO_DOMAIN );
    }

    /**
     * Associative array of columns
     *
     * @return array
     */
    public function get_columns() {
        $columns = [
            'cb'         => '<input type="checkbox" />',
            'post_title' => __( 'Title', VO_DOMAIN ),
            'post_type'  => __( 'Post Type', VO_DOMAIN ),
            'post_date'  => __( 'Post Date', VO_DOMAIN ),
            'action'     => __( 'Action', VO_DOMAIN ),
        ];

        return $columns;
    }

    /**
     * Render the bulk edit checkbox
     *
     * @param array $item
     *
     * @return string
     */
    public function column_cb( $item ) {
        $post_id = absint( $item['ID'] );
        return sprintf(
            '<input type="checkbox" id="vo-convert-checkbox-%s" name="bulk-convert[]" value="%s" />',
            $post_id,
            $post_id
        );
    }

    /**
     * Method for post title column
     *
     * @param array $item an array of data
     *
     * @return string
     */
    public function column_post_title( $item ) {
        $post_title = $item['post_title'] ?: '&lt;'.__('No Post Title', VO_DOMAIN).'&gt;';

        $title = '<strong><a target="_blank" href="' . admin_url("post.php?post={$item['ID']}&action=edit") . '" target="_blank">' . $post_title . '</a></strong>';

        return $title;
    }

    /**
     * Method for post type column
     *
     * @param array $item an array of data
     *
     * @return string
     */
    public function column_post_type( $item ) {

        $url = esc_url( add_query_arg( array( 'vo_post_type' => $item['post_type'] ) ) );

        $post_type_obj = get_post_type_object( $item['post_type'] );
        $label         = $post_type_obj->labels->singular_name;

        $type = '<a href="' . $url . '">' . $label . '</a>';

        return $type;
    }

    /**
     * Method for post type column
     *
     * @param array $item an array of data
     *
     * @return string
     */
    public function column_post_date( $item ) {
        return $item['post_date'];
    }

    /**
     * Method for action column
     *
     * @param array $item an array of data
     *
     * @return string
     */
    public function column_action( $item ) {
        $actions = [];
        $convert_nonce = wp_create_nonce( 'vo_convert_post_' . $item['ID'] );

        $json = '{"action":"vo_single_convert", "post":"' . absint( $item['ID'] ) . '", "_wpnonce":"' . $convert_nonce . '"}';

        if (empty($_REQUEST['vo_get_errors'])) {
            $actions[] = '<span><a href="#" id="vo-single-convert-' . absint($item['ID']) . '" class="vo-single-convert" data-json=\'' . $json . '\'>' . __('Convert', VO_DOMAIN) . '</a></span>';
        }

        $actions[] = '<a target="_blank" href="'.get_permalink( $item['ID'] ).'">' . __( 'View', VO_DOMAIN ) . '</a>';

        return implode(' | ', $actions);
    }

    /**
     * Columns to make sortable.
     *
     * @return array
     */
    public function get_sortable_columns() {
        $sortable_columns = array(
            'post_title' => array( 'post_title', false ),
            'post_date' => array( 'post_date', false ),
        );

        return $sortable_columns;
    }

    /**
     * Returns an associative array containing the bulk action
     *
     * @return array
     */
    public function get_bulk_actions() {
        if ($_REQUEST['vo_get_errors']) {
            return [];
        }

        return [
            'bulk-convert' => __( 'Convert', VO_DOMAIN ),
        ];
    }

    /**
     * Extra controls to be displayed between bulk actions and pagination
     *
     * @param string $which
     */
    function extra_tablenav( $which ) {
        $bulk_nonce = wp_create_nonce( 'vo_bulk_convert');
        $post_types = vo_get_post_types();
        $categories = get_categories(['hide_empty' => false]);
        if ( $which == 'top' ) {
            ?>
            <div class="alignleft actions bulkactions">
                <select name="vo_get_errors">
                    <option value="">Classic Posts</option>
                    <option value="1" <?= (!empty($_REQUEST['vo_get_errors']) ? 'selected = "selected"' : '') ?>>HTML Block Warnings</option>
                </select>
                <select name="vo_post_type">
                    <option value="">All Post Types</option>
                    <?php
                    foreach ( $post_types as $post_type ) {
                        $selected = '';
                        if ( ! empty( $_REQUEST['vo_post_type'] ) && $_REQUEST['vo_post_type'] == $post_type ) {
                            $selected = ' selected = "selected"';
                        }
                        $post_type_obj = get_post_type_object( $post_type );
                        $label         = $post_type_obj->labels->name;
                        if ($label) :?>
                            <option value="<?php echo $post_type; ?>" <?php echo $selected; ?>><?php echo $label; ?></option>
                        <?php endif;
                    }
                    ?>
                </select>
                <select name="vo_category">
                    <option value="">All categories</option>
                    <?php
                    foreach ( $categories as $category ) {
                        $selected = '';
                        if ( ! empty( $_REQUEST['vo_category'] ) && $_REQUEST['vo_category'] == $category->term_id ) {
                            $selected = ' selected = "selected"';
                        }
                        ?>
                        <option value="<?php echo $category->term_id; ?>" <?php echo $selected; ?>><?php echo $category->name; ?></option>
                        <?php
                    }
                    ?>
                </select>
                <input type="text" name="vo_from_date" value="<?=isset($_REQUEST['vo_from_date']) ? $_REQUEST['vo_from_date'] : self::$default_from_date?>" placeholder="From (YYYY-MM-DD)"/>
                <input type="text" name="vo_to_date" value="<?=isset($_REQUEST['vo_to_date']) ? $_REQUEST['vo_to_date'] : ''?>" placeholder="To (YYYY-MM-DD)"/>
                <?php submit_button( __( 'Filter', VO_DOMAIN ), 'action', 'vo_filter_btn', false ); ?>
                <?php if (empty($_REQUEST['vo_get_errors'])) : ?>
                    <?php submit_button( sprintf(__( 'Convert Current Filter (%d)', VO_DOMAIN ), self::$total_items), 'primary', 'vo_convert_filter', false, ['data-nonce' => $bulk_nonce] ); ?>
                <?php endif; ?>
            </div>
            <?php
        }
        if ( $which == 'bottom' ) {
            // The code that goes after the table is there

        }
    }

    /**
     * Handles data query and filter, sorting, and pagination.
     */
    public function prepare_items() {

        $columns               = $this->get_columns();
        $hidden                = array();
        $sortable              = $this->get_sortable_columns();
        $this->_column_headers = array( $columns, $hidden, $sortable );

        /** Process bulk action */
        $this->process_bulk_action();

        $per_page    = $this->get_items_per_page( 'posts_per_page', 20 );
        self::$total_items = self::count_items();

        $this->set_pagination_args(
            [
                'total_items' => self::$total_items, // WE have to calculate the total number of items.
                'per_page'    => $per_page, // WE have to determine how many items to show on a page.
            ]
        );

        $current_page = $this->get_pagenum();

        $this->items = self::get_posts( $per_page, $current_page );
    }
}
