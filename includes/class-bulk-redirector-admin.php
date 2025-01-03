<?php
class Bulk_Redirector_Admin {
    private $list_table;
    private $csv_processor;
    private $page_slug = 'bulk-redirector';

    public function init() {
        // Remove old constructor hooks to prevent duplicate menu items
        remove_action('admin_menu', array($this, 'add_admin_menu'));
        remove_action('admin_init', array($this, 'settings_init'));
        
        // Add new hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_filter('plugin_action_links_bulk-redirector/bulk-redirector.php', array($this, 'settings_link'));
        
        if (isset($_GET['page']) && $_GET['page'] === 'bulk-redirector-list') {
            $this->list_table = new Bulk_Redirects_List_Table();
        }
        
        $this->csv_processor = new Bulk_Redirector_CSV_Processor();
    }

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_filter('plugin_action_links_' . plugin_basename(BULK_REDIRECTOR_PLUGIN_DIR . 'bulk-redirector.php'), 
            array($this, 'settings_link')
        );
        $this->csv_processor = new Bulk_Redirector_CSV_Processor();
    }

    // Move all admin-related functions here as methods
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            __('Bulk Redirector', 'bulk-redirector'),
            __('Bulk Redirector', 'bulk-redirector'),
            'manage_options',
            $this->page_slug,
            array($this, 'options_page'),
            'dashicons-randomize'
        );

        // Upload CSV submenu (changed from Settings)
        add_submenu_page(
            $this->page_slug,
            __('Upload CSV', 'bulk-redirector'),
            __('Upload CSV', 'bulk-redirector'),
            'manage_options',
            $this->page_slug
        );

        // Redirects list submenu
        add_submenu_page(
            $this->page_slug,
            __('Redirects List', 'bulk-redirector'),
            __('Redirects List', 'bulk-redirector'),
            'manage_options',
            $this->page_slug . '-list',
            array($this, 'list_page')
        );
    }

    public function settings_init() {
        // Register the settings
        register_setting(
            'bulk_redirector_settings', 
            'bulk_redirector_settings',
            array(
                'sanitize_callback' => array($this, 'validate_settings')
            )
        );

        // Add settings section
        add_settings_section(
            'bulk_redirector_settings_section',
            __('Redirect Settings', 'bulk-redirector'),
            array($this, 'settings_section_callback'),
            $this->page_slug // Use the page slug property
        );

        // Add settings fields
        add_settings_field(
            'redirect_type',
            __('Redirect Type', 'bulk-redirector'),
            array($this, 'redirect_type_render'),
            $this->page_slug, // Use the page slug property
            'bulk_redirector_settings_section'
        );

        add_settings_field(
            'csv_upload',
            __('Upload CSV', 'bulk-redirector'),
            array($this, 'csv_upload_render'),
            $this->page_slug, // Use the page slug property
            'bulk_redirector_settings_section'
        );
    }

    public function settings_link($links) {
        $settings_link = '<a href="admin.php?page=' . $this->page_slug . '">' . __('Settings', 'bulk-redirector') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function list_page() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bulk_redirects'; // Changed from csv_redirects

        // Handle bulk actions
        if (isset($_POST['redirects']) && isset($_POST['action']) && $_POST['action'] === 'delete') {
            $ids = array_map('intval', $_POST['redirects']);
            $wpdb->query("DELETE FROM $table_name WHERE id IN (" . implode(',', $ids) . ")");
            add_settings_error('bulk_redirector_messages', 'bulk_delete', __('Selected redirects deleted.', 'bulk-redirector'), 'success');
        }

        // Handle single delete
        if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['redirect'])) {
            $wpdb->delete($table_name, ['id' => intval($_GET['redirect'])], ['%d']);
            add_settings_error('bulk_redirector_messages', 'delete', __('Redirect deleted.', 'bulk-redirector'), 'success');
        }

        // Handle add/edit form submission
        if (isset($_POST['submit_redirect'])) {
            $from_url = esc_url_raw(trim($_POST['from_url']));
            $to_url = esc_url_raw(trim($_POST['to_url']));
            $redirect_type = sanitize_text_field($_POST['redirect_type']);
            
            if (empty($from_url) || empty($to_url)) {
                add_settings_error('bulk_redirector_messages', 'empty_fields', __('Both URLs are required.', 'bulk-redirector'), 'error');
            } else {
                $data = [
                    'from_url' => $from_url,
                    'to_url' => $to_url,
                    'redirect_type' => $redirect_type
                ];
                
                if (isset($_POST['redirect_id'])) {
                    $wpdb->update($table_name, $data, ['id' => intval($_POST['redirect_id'])]);
                    add_settings_error('bulk_redirector_messages', 'updated', __('Redirect updated.', 'bulk-redirector'), 'success');
                } else {
                    $wpdb->insert($table_name, $data);
                    add_settings_error('bulk_redirector_messages', 'added', __('Redirect added.', 'bulk-redirector'), 'success');
                }
            }
        }

        // Show edit form or list
        if (isset($_GET['action']) && ($_GET['action'] === 'edit' || $_GET['action'] === 'add')) {
            $this->edit_form();
        } else {
            ?>
            <div class="wrap">
                <h1 class="wp-heading-inline"><?php echo esc_html__('Redirects List', 'bulk-redirector'); ?></h1>
                <a href="?page=<?php echo $_REQUEST['page']; ?>&action=add" class="page-title-action"><?php echo esc_html__('Add New', 'bulk-redirector'); ?></a>
                <?php settings_errors('bulk_redirector_messages'); ?>
                <form method="post">
                    <?php
                    if (!isset($this->list_table)) {
                        $this->list_table = new Bulk_Redirects_List_Table();
                    }
                    $this->list_table->prepare_items();
                    // Add search box before the table
                    $this->list_table->search_box(__('Search Redirects', 'bulk-redirector'), 'redirect');
                    $this->list_table->display();
                    ?>
                </form>
            </div>
            <?php
        }
    }

    public function edit_form() {
        global $wpdb;
        $redirect = null;
        $table_name = $wpdb->prefix . 'bulk_redirects'; // Fix: Changed from csv_redirects to bulk_redirects
        
        if (isset($_GET['redirect'])) {
            $redirect = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE id = %d",
                intval($_GET['redirect'])
            ));
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo $redirect ? esc_html__('Edit Redirect', 'bulk-redirector') : esc_html__('Add New Redirect', 'bulk-redirector'); ?></h1>
            <?php settings_errors('bulk_redirector_messages'); ?>
            <form method="post" action="">
                <?php if ($redirect) : ?>
                    <input type="hidden" name="redirect_id" value="<?php echo esc_attr($redirect->id); ?>">
                <?php endif; ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="from_url"><?php esc_html_e('From URL', 'bulk-redirector'); ?></label></th>
                        <td>
                            <input name="from_url" type="url" id="from_url" 
                                value="<?php echo $redirect ? esc_url($redirect->from_url) : ''; ?>" 
                                class="regular-text" required>
                            <p class="description"><?php esc_html_e('The URL to redirect from', 'bulk-redirector'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="to_url"><?php esc_html_e('To URL', 'bulk-redirector'); ?></label></th>
                        <td>
                            <input name="to_url" type="url" id="to_url" 
                                value="<?php echo $redirect ? esc_url($redirect->to_url) : ''; ?>" 
                                class="regular-text" required>
                            <p class="description"><?php esc_html_e('The URL to redirect to', 'bulk-redirector'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="redirect_type"><?php esc_html_e('Redirect Type', 'bulk-redirector'); ?></label></th>
                        <td>
                            <select name="redirect_type" id="redirect_type" required>
                                <option value="301" <?php selected($redirect ? $redirect->redirect_type : '', '301'); ?>>301 - Permanent</option>
                                <option value="302" <?php selected($redirect ? $redirect->redirect_type : '', '302'); ?>>302 - Temporary</option>
                                <option value="307" <?php selected($redirect ? $redirect->redirect_type : '', '307'); ?>>307 - Temporary (Strict)</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="submit_redirect" class="button button-primary" 
                        value="<?php echo $redirect ? esc_attr__('Update Redirect', 'bulk-redirector') : esc_attr__('Add Redirect', 'bulk-redirector'); ?>">
                    <a href="?page=<?php echo esc_attr($this->page_slug); ?>-list" class="button">
                        <?php esc_html_e('Cancel', 'bulk-redirector'); ?>
                    </a>
                </p>
            </form>
        </div>
        <?php
    }

    public function options_page() {
        // Handle CSV upload
        if (isset($_FILES['csv_file']) && isset($_POST['redirect_type'])) {
            check_admin_referer('bulk_redirector_csv_upload');
            
            if ($_FILES['csv_file']['error'] === 0) {
                $redirect_type = sanitize_text_field($_POST['redirect_type']);
                $result = $this->csv_processor->process($_FILES['csv_file'], $redirect_type);
                
                if ($result['success']) {
                    add_settings_error(
                        'bulk_redirector_messages',
                        'csv_upload_success',
                        $result['message'],
                        'success',
                        true // Allow HTML in message
                    );
                } else {
                    add_settings_error(
                        'bulk_redirector_messages',
                        'csv_upload_error',
                        $result['message'],
                        'error'
                    );
                }
            } else {
                add_settings_error(
                    'bulk_redirector_messages',
                    'csv_upload_error',
                    __('Error uploading file. Please try again.', 'bulk-redirector'),
                    'error'
                );
            }
        }

        // Handle reset action
        if (isset($_POST['bulk_redirector_reset'])) {
            check_admin_referer('bulk_redirector_reset');
            $this->reset_redirects();
        }

        // Rest of the options_page code remains the same
        ?>
        <div class="wrap">
            <h2><?php echo esc_html__('Upload CSV', 'bulk-redirector'); ?></h2>
            <?php settings_errors('bulk_redirector_messages'); ?>
            
            <div class="card" style="max-width: 520px;">
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('bulk_redirector_csv_upload'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="redirect_type"><?php _e('Redirect Type', 'bulk-redirector'); ?></label>
                            </th>
                            <td>
                                <select name="redirect_type" id="redirect_type" required>
                                    <option value="301">301 - Permanent</option>
                                    <option value="302">302 - Temporary</option>
                                    <option value="307">307 - Temporary (Strict)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="csv_file"><?php _e('Upload CSV', 'bulk-redirector'); ?></label>
                            </th>
                            <td>
                                <input type="file" name="csv_file" id="csv_file" accept=".csv" required />
                                <p class="description">
                                    <?php _e('CSV file must have "from_url" and "to_url" columns', 'bulk-redirector'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Upload CSV', 'bulk-redirector')); ?>
                </form>
            </div>

            <!-- Reset Section -->
            <div class="card" style="max-width: 520px; margin-top: 20px;">
                <h3><?php _e('Reset Redirects', 'bulk-redirector'); ?></h3>
                <p><?php _e('This will delete all redirects from the database. This action cannot be undone.', 'bulk-redirector'); ?></p>
                <form method="post">
                    <?php wp_nonce_field('bulk_redirector_reset'); ?>
                    <input type="submit" name="bulk_redirector_reset" class="button button-secondary delete" 
                        value="<?php echo esc_attr__('Delete All Redirects', 'bulk-redirector'); ?>"
                        onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete all redirects? This cannot be undone!', 'bulk-redirector')); ?>');">
                </form>
            </div>
        </div>
        <?php
    }

    private function reset_redirects() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bulk_redirects';
        
        $result = $wpdb->query("TRUNCATE TABLE $table_name");
        
        if ($result !== false) {
            add_settings_error(
                'bulk_redirector_messages',
                'reset_success',
                __('All redirects have been deleted successfully.', 'bulk-redirector'),
                'success'
            );
        } else {
            add_settings_error(
                'bulk_redirector_messages',
                'reset_error',
                __('Error deleting redirects. Please try again.', 'bulk-redirector'),
                'error'
            );
        }
    }

    public function process_csv($file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            add_settings_error(
                'bulk_redirector_messages',
                'csv_upload_error',
                __('Error uploading file.', 'bulk-redirector'),
                'error'
            );
            return;
        }

        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            add_settings_error(
                'bulk_redirector_messages',
                'csv_read_error',
                __('Error reading CSV file.', 'bulk-redirector'),
                'error'
            );
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'bulk_redirects'; // Changed from csv_redirects
        $options = get_option('bulk_redirector_settings');
        $redirect_type = $options['redirect_type'];
        
        $success_count = 0;
        $error_count = 0;
        $headers = fgetcsv($handle); // Skip headers

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < 2) continue;

            $from_url = esc_url_raw(trim($data[0]));
            $to_url = esc_url_raw(trim($data[1]));

            // Skip if URLs are empty
            if (empty($from_url) || empty($to_url)) {
                $error_count++;
                continue;
            }

            // Check for circular redirects
            if ($from_url === $to_url || $this->is_circular($from_url, $to_url)) {
                $error_count++;
                continue;
            }

            // Insert or update redirect
            $result = $wpdb->replace(
                $table_name,
                array(
                    'from_url' => $from_url,
                    'to_url' => $to_url,
                    'redirect_type' => $redirect_type
                ),
                array('%s', '%s', '%s')
            );

            if ($result === false) {
                $error_count++;
            } else {
                $success_count++;
            }
        }

        fclose($handle);

        add_settings_error(
            'bulk_redirector_messages',
            'csv_upload_success',
            sprintf(
                __('Processed CSV file. Success: %d, Errors: %d', 'bulk-redirector'),
                $success_count,
                $error_count
            ),
            'success'
        );
    }

    public function validate_settings($input) {
        $output = array();
        $error = false;

        if (empty($input['redirect_type'])) {
            add_settings_error(
                'bulk_redirector_messages',
                'bulk_redirector_error',
                __('Please select a redirect type.', 'bulk-redirector'),
                'error'
            );
            $error = true;
        }

        if ($error) {
            // Preserve previous valid settings if there's an error
            return get_option('csv_redirector_settings');
        }

        $output['redirect_type'] = sanitize_text_field($input['redirect_type']);
        return $output;
    }

    public function settings_section_callback() {
        echo __('Upload a CSV file to import redirects or configure default redirect settings below.', 'bulk-redirector');
    }

    public function redirect_type_render() {
        $options = get_option('bulk_redirector_settings', array('redirect_type' => ''));
        $redirect_type = isset($options['redirect_type']) ? $options['redirect_type'] : '';
        ?>
        <select name='bulk_redirector_settings[redirect_type]' required>
            <option value='' <?php selected($redirect_type, ''); ?>>-- Select Redirect Type --</option>
            <option value='301' <?php selected($redirect_type, '301'); ?>>301 - Permanent</option>
            <option value='302' <?php selected($redirect_type, '302'); ?>>302 - Temporary</option>
            <option value='307' <?php selected($redirect_type, '307'); ?>>307 - Temporary (Strict)</option>
        </select>
        <p class="description">
            <?php _e('Select the default type for new redirects', 'bulk-redirector'); ?>
        </p>
        <?php
    }

    public function csv_upload_render() {
        ?>
        <input type="file" name="csv_file" accept=".csv" />
        <p class="description">
            <?php _e('Upload a CSV file with two columns: "from_url" and "to_url". Use full URLs like https://old.com, https://new.com', 'bulk-redirector'); ?>
        </p>
        <?php
    }

    public function is_circular($from_url, $to_url) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'bulk_redirects'; // Changed from csv_redirects
        
        $checked_urls = array($from_url);
        $current_url = $to_url;

        while ($current_url) {
            if (in_array($current_url, $checked_urls)) {
                return true; // Circular redirect detected
            }

            $next_url = $wpdb->get_var($wpdb->prepare(
                "SELECT to_url FROM $table_name WHERE from_url = %s",
                $current_url
            ));

            if (!$next_url) break;

            $checked_urls[] = $current_url;
            $current_url = $next_url;
        }

        return false;
    }
}