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

function apfu_render_add_plugin_from_url_page()
{
    // Ensure only admins or users with install_plugins capability can access
    if (!current_user_can('install_plugins')) {
        wp_die(__('You do not have sufficient permissions to install plugins.'));
    }

    // Handle resync action
    if (!empty($_POST['apfu_resync_plugins'])) {
        delete_transient('apfu_dropdown_options'); // Clear the transient
        apfu_fetch_plugin_dropdown_options(); // Fetch and cache the latest options
        echo '<div class="updated"><p>Plugin list resynced successfully.</p></div>';
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
                    set_transient('plugin_install_success_notice', 'Plugin added from URL successfully.', 30);
                    echo '<script type="text/javascript">window.location.href = "' . admin_url('plugins.php') . '";</script>';
                    exit;
                }
            }
        }
    }

    // Fetch dropdown options
    $dropdown_options = apfu_fetch_plugin_dropdown_options();

    // Display the form
    ?>
    <div class="wrap">
        <h2>Add Plugin from URL</h2>
        <form method="post">
            <label for="plugin_url">Enter Plugin URL (.zip):</label>
            <input type="text" name="plugin_url" id="plugin_url" class="regular-text" required>
            <input type="submit" class="button button-primary" value="Install Now">
        </form>

        <h2>Install Frequently Used Plugin</h2>
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

        <h2>Resync Plugin List</h2>
        <form method="post">
            <input type="hidden" name="apfu_resync_plugins" value="1">
            <input type="submit" class="button button-secondary" value="Resync Now">
        </form>
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
    if ($message = get_transient('plugin_install_success_notice')) {
        echo '<div class="updated notice is-dismissible"><p>' . esc_html($message) . '</p></div>';
        delete_transient('plugin_install_success_notice'); // Clear the transient
    }
}
