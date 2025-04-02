<?php
/*
Plugin Name: Add Plugin from URL
Description: Adds an option to install or update plugins from a URL in the Add Plugins page.
Version: 1.0
Author: 5StarPlugins.com / Press Wizards.com
Author URI: https://presswizards.com/
*/

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    add_submenu_page(
        'plugins.php',  // Parent menu is plugins.php
        'Add Plugin from URL',  // Page title
        'Add Plugin from URL',  // Menu title
        'install_plugins',  // Capability needed
        'add-plugin-from-url',  // Menu slug
        'render_add_plugin_from_url_page'  // Callback to render the page
    );
});

function render_add_plugin_from_url_page()
{
    // Ensure only admins or users with install_plugins capability can access
    if (!current_user_can('install_plugins')) {
        wp_die(__('You do not have sufficient permissions to install plugins.'));
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
                    if (delete_plugin_folder($plugin_path)) {
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
            <option value="https://envato.github.io/wp-envato-market/dist/envato-market.zip">Envato Market</option>
        </select>
        <input type="submit" class="button button-primary" value="Add Plugin">
    </form>

    </div>
    <?php
}

function delete_plugin_folder($plugin_path) {
    if (is_dir($plugin_path)) {
        // Open the directory and loop through its contents
        $files = array_diff(scandir($plugin_path), array('.', '..'));

        foreach ($files as $file) {
            $file_path = $plugin_path . '/' . $file;
            if (is_dir($file_path)) {
                // Recursively delete subdirectories
                delete_plugin_folder($file_path);
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
add_action('admin_notices', 'display_plugin_install_success_notice');
function display_plugin_install_success_notice() {
    // Check if the transient is set
    if ($message = get_transient('plugin_install_success_notice')) {
        echo '<div class="updated notice is-dismissible"><p>' . esc_html($message) . '</p></div>';
        delete_transient('plugin_install_success_notice'); // Clear the transient
    }
}
