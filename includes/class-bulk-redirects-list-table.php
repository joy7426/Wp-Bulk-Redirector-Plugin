<?php
if (!class_exists('WP_List_Table')) {
    require_once(ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

class Bulk_Redirects_List_Table extends WP_List_Table {
    public function __construct() {
        parent::__construct([
            'singular' => 'redirect',
            'plural'   => 'redirects',
            'ajax'     => false
        ]);
    }

    public function get_columns() {
        return [
            'cb'            => '<input type="checkbox" />', // Add checkbox column
            'from_url'      => __('From URL', 'bulk-redirector'),
            'to_url'        => __('To URL', 'bulk-redirector'),
            'redirect_type' => __('Type', 'bulk-redirector'),
            'created_at'    => __('Created', 'bulk-redirector')
        ];
    }

    public function column_cb($item) {
        return sprintf('<input type="checkbox" name="redirects[]" value="%s" />', $item->id);
    }

    public function column_from_url($item) {
        $actions = [
            'edit' => sprintf(
                '<a href="?page=%s&action=edit&redirect=%s">%s</a>',
                $_REQUEST['page'],
                $item->id,
                __('Edit', 'bulk-redirector')
            ),
            'delete' => sprintf(
                '<a href="?page=%s&action=delete&redirect=%s" onclick="return confirm(\'%s\')">%s</a>',
                $_REQUEST['page'],
                $item->id,
                __('Are you sure you want to delete this redirect?', 'bulk-redirector'),
                __('Delete', 'bulk-redirector')
            )
        ];

        return sprintf('<strong>%1$s</strong> %2$s', esc_html($item->from_url), $this->row_actions($actions));
    }

    public function get_sortable_columns() {
        return [
            'from_url'      => ['from_url', true],
            'to_url'        => ['to_url', false],
            'redirect_type' => ['redirect_type', false],
            'created_at'    => ['created_at', false]
        ];
    }

    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bulk_redirects';
        
        $per_page = 20;
        $current_page = $this->get_pagenum();
        
        // Add search functionality
        $search = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        $where = '';
        
        if (!empty($search)) {
            $where = $wpdb->prepare(
                " WHERE from_url LIKE %s OR to_url LIKE %s",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }
        
        $total_items = $wpdb->get_var("SELECT COUNT(id) FROM $table_name" . $where);

        $orderby = isset($_GET['orderby']) ? sanitize_sql_orderby($_GET['orderby']) : 'created_at';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'DESC';
        
        $this->items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table_name $where ORDER BY $orderby $order LIMIT %d OFFSET %d",
                $per_page,
                ($current_page - 1) * $per_page
            )
        );

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ]);
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'from_url':
            case 'to_url':
            case 'redirect_type':
                return esc_html($item->$column_name);
            case 'created_at':
                return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($item->created_at));
            default:
                return print_r($item, true);
        }
    }

    public function get_bulk_actions() {
        return [
            'delete' => 'Delete'
        ];
    }

    // Add this new method
    public function search_box($text, $input_id) {
        if (empty($_REQUEST['s']) && !$this->has_items()) {
            return;
        }
        
        $input_id = $input_id . '-search-input';
        
        ?>
        <p class="search-box">
            <label class="screen-reader-text" for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($text); ?>:</label>
            <input type="search" id="<?php echo esc_attr($input_id); ?>" name="s" 
                value="<?php echo esc_attr(isset($_REQUEST['s']) ? $_REQUEST['s'] : ''); ?>" />
            <?php submit_button($text, '', '', false, array('id' => 'search-submit')); ?>
        </p>
        <?php
    }
}