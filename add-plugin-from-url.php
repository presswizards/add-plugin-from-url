<?php
/*
Plugin Name: Add Plugin from URL
Description: Adds an option to install or update plugins from a URL in the Add Plugins page.
Version: 1.0
Author: 5StarPlugins.com / Press Wizards.com
Author URI: https://presswizards.com/
*/

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_submenu_page(
        'plugins.php',  // Parent menu is plugins.php
        'Add Plugin from URL',  // Page title
        'Add Plugin from URL',  // Menu title
        'install_plugins',  // Capability needed
        'add-plugin-from-url',  // Menu slug
        'apfu_render_add_plugin_from_url_page'  // Callback to render the page
    );
});

function apfu_fetch_plugin_dropdown_options() {
    $transient_key = 'apfu_dropdown_options';
    $remote_url = 'https://raw.githubusercontent.com/presswizards/add-plugin-from-url/refs/heads/main/built-in-plugins.csv';

    // Check if the transient exists
    $cached_data = get_transient($transient_key);
    if ($cached_data !== false) {
        return $cached_data;
    }

    // Fetch the remote file
    $response = wp_remote_get($remote_url, ['timeout' => 30]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return []; // Return an empty array if the request fails
    }

    $csv_data = wp_remote_retrieve_body($response);
    $lines = explode("\n", $csv_data);
    $options = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if (!empty($line)) {
            list($name, $url) = array_map('trim', explode(',', $line, 2));
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                $options[] = ['name' => $name, 'url' => $url];
            }
        }
    }

    // Cache the data in a transient for 6 hours
    set_transient($transient_key, $options, 6 * HOUR_IN_SECONDS);

    // Save the data in the plugin options table as a fallback
    update_option('apfu_plugin_dropdown_options', $options);

    return $options;
}

function apfu_fetch_custom_plugin_lists() {
    $custom_lists = get_option('apfu_custom_plugin_lists', []);
    return is_array($custom_lists) ? $custom_lists : [];
}

function apfu_save_custom_plugin_list($title, $csv_url = '', $textarea_content = '') {
    $plugins = [];

    // Validate and fetch plugins from CSV URL
    if (!empty($csv_url)) {
        // Validate the URL: must start with https: and end with .csv
        if (!preg_match('/^https:.*\.csv$/i', $csv_url)) {
            return new WP_Error('invalid_url', 'The provided URL is invalid.');
        }

        $plugins = apfu_fetch_plugins_from_csv($csv_url);

        // Check for errors in fetching plugins
        if (is_wp_error($plugins)) {
            return $plugins; // Return the error for handling
        }

        // Validate each plugin line to ensure it is in Name,URL format
        $valid_plugins = [];
        foreach ($plugins as $plugin) {
            if (!empty($plugin['name']) && filter_var($plugin['url'], FILTER_VALIDATE_URL)) {
                $valid_plugins[] = $plugin;
            }
        }

        // Ensure valid plugins are found
        if (count($valid_plugins) === 0) {
            return new WP_Error('no_valid_plugins', 'No valid plugins found in the CSV file.');
        }

        $plugins = $valid_plugins;
    }

    // Validate and parse plugins from text area
    if (!empty($textarea_content)) {
        $lines = explode("\n", $textarea_content);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                list($name, $url) = array_map('trim', explode(',', $line, 2));
                if (!empty($name) && filter_var($url, FILTER_VALIDATE_URL)) {
                    $plugins[] = ['name' => $name, 'url' => $url];
                }
            }
        }

        // Ensure valid plugins are found
        if (count($plugins) === 0) {
            return new WP_Error('no_valid_plugins', 'No valid plugins found in the text area.');
        }
    }

    // Ensure at least one source (CSV or text area) provided valid plugins
    if (count($plugins) === 0) {
        return new WP_Error('no_plugins', 'No valid plugins were provided.');
    }

    // Save the list with validated plugins
    $custom_lists = apfu_fetch_custom_plugin_lists();
    $custom_lists[] = [
        'title' => sanitize_text_field($title),
        'csv_url' => !empty($csv_url) ? esc_url_raw($csv_url) : '',
        'plugins' => $plugins
    ];
    update_option('apfu_custom_plugin_lists', $custom_lists);

    return true; // Indicate success
}

function apfu_save_custom_plugin_list_from_textarea($title, $textarea_content) {
    $lines = explode("\n", $textarea_content);
    $options = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if (!empty($line)) {
            list($name, $url) = array_map('trim', explode(',', $line, 2));
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                $options[] = ['name' => $name, 'url' => $url];
            }
        }
    }

    if (!empty($options)) {
        $custom_lists = apfu_fetch_custom_plugin_lists();
        $custom_lists[] = ['title' => sanitize_text_field($title), 'plugins' => $options];
        update_option('apfu_custom_plugin_lists', $custom_lists);
        return true; // Indicate success
    }

    return false; // Indicate failure
}

function apfu_fetch_plugins_from_csv($csv_url) {
    // Validate the URL: must start with https: and end with .csv
    if (!preg_match('/^https:.*\.csv$/i', $csv_url)) {
        return new WP_Error('invalid_url', 'The provided URL is invalid.');
    }

    // Fetch the remote file
    $response = wp_remote_get($csv_url, ['timeout' => 30]);

    if (is_wp_error($response)) {
        return new WP_Error('fetch_error', 'Failed to connect to the URL: ' . $response->get_error_message());
    }

    if (wp_remote_retrieve_response_code($response) !== 200) {
        return new WP_Error('fetch_error', 'Failed to fetch the CSV file. HTTP status code: ' . wp_remote_retrieve_response_code($response));
    }

    $csv_data = wp_remote_retrieve_body($response);
    $lines = explode("\n", $csv_data);
    $options = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if (!empty($line)) {
            list($name, $url) = array_map('trim', explode(',', $line, 2));
            if (!empty($name) && filter_var($url, FILTER_VALIDATE_URL)) {
                $options[] = ['name' => $name, 'url' => $url];
            }
        }
    }

    // If no valid plugins are found, return an error
    if (count($options) <= 0) {
        return new WP_Error('no_valid_plugins', 'No valid plugins found in the CSV file.');
    }

    return $options;
}

function apfu_fetch_cached_plugins_from_csv($csv_url, $index = null) {
    $transient_key = $index !== null ? 'apfu_custom_list_' . $index : 'apfu_dropdown_options';

    // Check if the transient exists
    $cached_data = get_transient($transient_key);
    if ($cached_data !== false) {
        return $cached_data;
    }

    // Fetch fresh data and cache it
    $plugins = apfu_fetch_plugins_from_csv($csv_url);
    if (!is_wp_error($plugins)) {
        set_transient($transient_key, $plugins, 6 * HOUR_IN_SECONDS);
    }

    return $plugins;
}

function apfu_delete_custom_plugin_list($index) {
    $custom_lists = apfu_fetch_custom_plugin_lists();
    if (isset($custom_lists[$index])) {
        // Delete transient for the list
        $transient_key = 'apfu_custom_list_' . $index;
        delete_transient($transient_key);

        // Remove the list from the database
        unset($custom_lists[$index]);
        update_option('apfu_custom_plugin_lists', array_values($custom_lists)); // Reindex the array
    }
}

function apfu_clean_empty_lists() {
    $custom_lists = apfu_fetch_custom_plugin_lists();
    $cleaned_lists = [];

    foreach ($custom_lists as $list) {
        if (!empty($list['plugins']) || (!empty($list['csv_url']) && !is_wp_error(apfu_fetch_cached_plugins_from_csv($list['csv_url'])))) {
            $cleaned_lists[] = $list; // Keep valid lists
        }
    }

    update_option('apfu_custom_plugin_lists', $cleaned_lists); // Save only valid lists
}

function apfu_render_add_plugin_from_url_page()
{
    // Ensure only admins or users with install_plugins capability can access
    if (!current_user_can('install_plugins')) {
        wp_die(__('You do not have sufficient permissions to install plugins.'));
    }

    // Clean up empty lists
    apfu_clean_empty_lists();

    // Handle resync action
    if (!empty($_POST['apfu_resync_plugins'])) {
        delete_transient('apfu_dropdown_options'); // Clear the transient
        apfu_fetch_plugin_dropdown_options(); // Fetch and cache the latest options
        echo '<div class="updated"><p>Plugin list resynced successfully.</p></div>';
    }

    // Handle adding a new list
    if (!empty($_POST['apfu_new_list_title'])) {
        $title = sanitize_text_field($_POST['apfu_new_list_title']);
        $csv_url = !empty($_POST['apfu_new_list_csv_url']) ? esc_url_raw($_POST['apfu_new_list_csv_url']) : '';
        $textarea_content = !empty($_POST['apfu_new_list_textarea']) ? sanitize_textarea_field($_POST['apfu_new_list_textarea']) : '';

        $result = apfu_save_custom_plugin_list($title, $csv_url, $textarea_content);
        if ($result === true) {
            echo '<div class="updated"><p>New plugin list added successfully.</p></div>';
        } elseif (is_wp_error($result)) {
            echo '<div class="error"><p>' . esc_html($result->get_error_message()) . '</p></div>';
        } else {
            echo '<div class="error"><p>Failed to add the plugin list. Please check your input and try again.</p></div>';
        }
    }

    // Handle deleting a list
    if (isset($_POST['apfu_delete_list_index'])) { // Use isset to handle index 0
        $index = intval($_POST['apfu_delete_list_index']);
        apfu_delete_custom_plugin_list($index);
        echo '<div class="updated"><p>Plugin list deleted successfully.</p></div>';
    }

    if (!empty($_POST['plugin_url'])) {
        $plugin_url = esc_url_raw(trim($_POST['plugin_url']));

        // Validate the URL and ensure it's a .zip file
        if (!filter_var($plugin_url, FILTER_VALIDATE_URL) || !preg_match('/\.zip$/i', $plugin_url)) {
            echo '<div class="error"><p>Invalid URL. Please enter a valid .zip URL.</p></div>';
        } else {
            // Set up upload directory
            $upload_dir = wp_upload_dir();
            $plugin_file = $upload_dir['path'] . '/' . basename($plugin_url);
            $plugin_slug = sanitize_title(basename($plugin_url, '.zip'));
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_slug;

            // Download the plugin file
            $response = wp_remote_get($plugin_url, ['timeout' => 30]);

            if (is_wp_error($response)) {
                echo '<div class="error"><p>Failed to download file.</p></div>';
            } else {
                // Check if the response status code is successful (200 OK)
                $status_code = wp_remote_retrieve_response_code($response);
                if ($status_code !== 200) {
                        echo '<div class="error"><p>Failed to download file. Status code: ' . $status_code . '</p></div>';
                        exit;
                }

                // Save the file locally
                file_put_contents($plugin_file, wp_remote_retrieve_body($response));

                // Prepare the file for installation or update
                $_FILES['pluginzip'] = [
                    'name' => basename($plugin_file),
                    'type' => 'application/zip',
                    'tmp_name' => $plugin_file,
                    'error' => 0,
                    'size' => filesize($plugin_file)
                ];

                // Include necessary WordPress functions
                require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

                // Instantiate the plugin upgrader
                $upgrader = new Plugin_Upgrader();

                // Check if the plugin already exists
                if (is_dir($plugin_path)) {
                    // Plugin exists, delete it first to force reinstallation
                    if (apfu_delete_plugin_folder($plugin_path)) {
                    } else {
                        echo '<div class="error"><p>Failed to delete existing plugin folder.</p></div>';
                    }
                }

                // Plugin doesn't exist, proceed with installation
                $result = $upgrader->install($plugin_file);

                if (is_wp_error($result)) {
                    echo '<div class="error"><p>Plugin installation failed.</p></div>';
                } else {
                    // Set a transient to show an admin notice
                    set_transient('apfu_plugin_install_success_notice', 'Plugin added from URL successfully.', 30);
                    echo '<script type="text/javascript">window.location.href = "' . admin_url('plugins.php') . '";</script>';
                    exit;
                }
            }
        }
    }

    // Fetch dropdown options
    $dropdown_options = apfu_fetch_plugin_dropdown_options();
    $custom_lists = apfu_fetch_custom_plugin_lists();

    // Display the form
    ?>
    <div class="wrap">
    <h2>Add Plugin from URL - by Rob @ <a href=https://presswizards.com/ target=_blank>PressWizards.com</a></h2>
    <p>A simple little plugin that allows you to easily install a plugin from a remote URL, avoiding the whole download and save, add plugin and upload, etc.</p>
    <p>You can also use the built-in plugin list below to quickly install frequently used plugins.</p>

    <p>&nbsp;</p>
    <hr>

    <h2>Add Plugin from URL</h2>
        <form method="post">
            <label for="plugin_url">Enter Plugin URL (.zip):</label>
            <input type="text" name="plugin_url" id="plugin_url" class="regular-text" required>
            <input type="submit" class="button button-primary" value="Install Now">
        </form>

        <p>&nbsp;</p>
    <hr>
        <?php if (empty($dropdown_options)) { ?>
            <p>No plugins found in the remote list. Please try resyncing.</p>
        <?php } else { ?>
        <h2>Quicky Install Built-In Plugins</h2>
        <form method="post">
            <label for="plugin_select">Select a Plugin:</label>
            <select name="plugin_url" id="plugin_select" class="regular-text">
                <option value="">Select a plugin...</option>
                <?php foreach ($dropdown_options as $option): ?>
                    <option value="<?php echo esc_url($option['url']); ?>">
                        <?php echo esc_html($option['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="submit" class="button button-primary" value="Add Plugin">
        </form>
        <?php } ?>

        <p>&nbsp;</p>

    <?php foreach ($custom_lists as $index => $list): ?>
        <h2><?php echo esc_html($list['title']); ?></h2>
        <?php if (!empty($list['csv_url'])): ?>
            <p><em>CSV URL: <a href="<?php echo esc_url($list['csv_url']); ?>" target="_blank"><?php echo esc_html($list['csv_url']); ?></a></em></p>
        <?php endif; ?>
        <?php if (!empty($list['plugins'])): ?>
            <form method="post" style="display: inline-block;">
                <label for="plugin_select_<?php echo sanitize_title($list['title']); ?>">Select a Plugin:</label>
                <select name="plugin_url" id="plugin_select_<?php echo sanitize_title($list['title']); ?>" class="regular-text">
                    <option value="">Select a plugin...</option>
                    <?php foreach ($list['plugins'] as $plugin): ?>
                        <option value="<?php echo esc_url($plugin['url']); ?>">
                            <?php echo esc_html($plugin['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="submit" class="button button-primary" value="Add Plugin">
            </form>
        <?php else: ?>
            <p>No plugins found in this list. Please check the CSV URL or text area content.</p>
            <form method="post" style="display: inline-block;">
                <input type="hidden" name="apfu_delete_list_index" value="<?php echo $index; ?>">
                <button type="submit" class="button button-secondary">Delete List</button>
            </form>
        <?php endif; ?>
        <form method="post" style="display: inline-block;">
            <input type="hidden" name="apfu_delete_list_index" value="<?php echo $index; ?>">
            <button type="button" class="button button-secondary apfu-delete-list-button" data-list-title="<?php echo esc_attr($list['title']); ?>" style="margin-top: -5px;padding-bottom:1px;">Delete List</button>
        </form>
    <?php endforeach; ?>

    <!-- Confirmation modal -->
    <div id="apfu-delete-list-modal" style="display: none;">
        <div style="background: #fff; padding: 20px; border: 1px solid #ccc; max-width: 400px; margin: 40px auto; text-align: center;">
            <p id="apfu-delete-list-message"></p>
            <form method="post" id="apfu-delete-list-form">
                <input type="hidden" name="apfu_delete_list_index" id="apfu-delete-list-index">
                <button type="submit" class="button button-primary">Confirm</button>
                <button type="button" class="button button-secondary" id="apfu-cancel-delete">Cancel</button>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const deleteButtons = document.querySelectorAll('.apfu-delete-list-button');
            const modal = document.getElementById('apfu-delete-list-modal');
            const message = document.getElementById('apfu-delete-list-message');
            const indexInput = document.getElementById('apfu-delete-list-index');
            const cancelButton = document.getElementById('apfu-cancel-delete');
            const deleteForm = document.getElementById('apfu-delete-list-form');

            deleteButtons.forEach(button => {
                button.addEventListener('click', function () {
                    const listTitle = this.getAttribute('data-list-title');
                    const listIndex = this.previousElementSibling.value;
                    message.textContent = `Are you sure you want to delete the list "${listTitle}"?`;
                    indexInput.value = listIndex;
                    modal.style.display = 'block';
                });
            });

            cancelButton.addEventListener('click', function () {
                modal.style.display = 'none';
            });

            // Ensure the form submits properly
            deleteForm.addEventListener('submit', function () {
                modal.style.display = 'none';
            });
        });
    </script>

    <p>&nbsp;</p>
    <hr>

    <h2>Add New Plugin List</h2>
    <form method="post">
        <label for="apfu_new_list_title">List Title:</label>
        <input type="text" name="apfu_new_list_title" id="apfu_new_list_title" class="regular-text" required>
        <br><br>
        <label for="apfu_new_list_csv_url">CSV URL:</label>
        <input type="text" name="apfu_new_list_csv_url" id="apfu_new_list_csv_url" class="regular-text">
        <p><em>Leave this field empty if you want to use the text area below.</em></p>
        <br>
        <label for="apfu_new_list_textarea">Or Enter Plugins (One per line, in Name,URL format):</label>
        <textarea name="apfu_new_list_textarea" id="apfu_new_list_textarea" class="large-text" rows="5"></textarea>
        <br><br>
        <input type="submit" class="button button-secondary" value="Add New List">
    </form>

    <p>&nbsp;</p>
    <hr>
        <h2>Resync Plugin List</h2>
        <form method="post">
            <input type="hidden" name="apfu_resync_plugins" value="1">
            <input type="submit" class="button button-secondary" value="Resync Now">
        </form>
        <p>&nbsp;</p>
        <hr>
        <p>If you find this plugin useful, please consider supporting my work:</p>
        <p><a href="https://www.buymeacoffee.com/robwpdev" target="_blank"><img src="https://cdn.buymeacoffee.com/buttons/default-orange.png" alt="Buy Me A Coffee" height="41" width="174"></a><br>
            If this plugin saves you time, helps your clients, or helps you do better work, I’d appreciate it.</p>


    </div>
    <?php
}

function apfu_delete_plugin_folder($plugin_path) {
    if (is_dir($plugin_path)) {
        // Open the directory and loop through its contents
        $files = array_diff(scandir($plugin_path), array('.', '..'));

        foreach ($files as $file) {
            $file_path = $plugin_path . '/' . $file;
            if (is_dir($file_path)) {
                // Recursively delete subdirectories
                apfu_delete_plugin_folder($file_path);
            } else {
                // Delete files
                unlink($file_path);
            }
        }

        // Finally, delete the empty folder
        rmdir($plugin_path);
        return true;
    }
    return false;
}

// Displaying the admin notice on plugins.php
add_action('admin_notices', 'apfu_display_plugin_install_success_notice');
function apfu_display_plugin_install_success_notice() {
    // Check if the transient is set
    if ($message = get_transient('apfu_plugin_install_success_notice')) {
        echo '<div class="updated notice is-dismissible"><p>' . esc_html($message) . '</p></div>';
        delete_transient('apfu_plugin_install_success_notice'); // Clear the transient
    }
}
