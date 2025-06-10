<?php
class Appetiser_SEO_Admin {

    public function __construct() {
        #dashboard actions
        add_action( 'admin_menu',  array( $this, 'add_plugin_menu' ) );

        add_action( 'admin_enqueue_scripts', array(  $this, 'enqueue_styles' ) );
        add_action( 'admin_enqueue_scripts', array(  $this, 'enqueue_scripts' ) );

        add_action('admin_init', array($this, 'handle_general_settings_form'));

        #blog schema handler functions
        add_action( 'wp_insert_post', array( $this, 'auto_generateblog_schema' ), 99, 3);

        #robots.txt handler functions
        add_action('admin_init', array($this, 'handle_robots_form'));

        #htaccess handler functions
        add_action('admin_init', array($this, 'handle_htaccess_form')); 

        #404 monitor handler functions
        add_action('admin_init', array($this, 'handle_clear_404_log'));

    }
    
    public function add_plugin_menu() {
        add_submenu_page(
            'appetiser-tools',           //parent-slug
            'SEO enhancements',     
            'SEO enhancements',     
            'manage_options',            
            'appetiser-seo-enhancements',     //menu-slug
            [$this, 'render_admin_page'] 
        );
    }

    public function enqueue_styles( $hook ) {
        if (!isset($_GET['page']) || $_GET['page'] !== 'appetiser-seo-enhancements') {
            return;
        }
        
        wp_enqueue_style('dashicons');

        wp_enqueue_style( 'appetiser-dashboard-style', plugins_url() . '/appetiser-common-assets/admin/css/appetiser-dashboard.css', array(), '1.0.0', 'all' );
        wp_enqueue_style( 'appetiser-seo-admin-style', plugin_dir_url( __FILE__ ) . 'css/app-link-exchange-admin.css', array(), '1.0.0', 'all' );
    }

    public function enqueue_scripts( $hook ) {
        if (!isset($_GET['page']) || $_GET['page'] !== 'appetiser-seo-enhancements') {
            return;
        }

        wp_enqueue_script( 'appetiser-dashboard-script', plugins_url() . '/appetiser-common-assets/admin/js/appetiser-dashboard.js', array( 'jquery' ), '1.0.0', false );
        wp_enqueue_script( 'appetiser-seo-admin-script', plugin_dir_url( __FILE__ ) . 'js/app-seo-admin.js', array( 'jquery' ), '1.0.0', true );
    }

    public function auto_generateblog_schema( $post_id, $post, $update ) {

        // Only run for posts, not revisions or autosaves
        if ($post->post_type !== 'post') return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;

        if ($post->post_status !== 'publish') return;

        // Make sure ACF functions exist
        if (!function_exists('get_field') || !function_exists('update_field')) return;

        // Core data
        $headline       = get_the_title($post_id);
        $description    = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
        if (empty($description)) {
            $content     = get_post_field('post_content', $post_id);
            $description = wp_trim_words(strip_tags($content), 30, '...');
        }   

        $date_published = get_the_date('c', $post_id);
        $date_modified  = get_the_modified_date('c', $post_id);
        $existing_schema = get_field('schema', $post_id);

        // If schema exists, only update key fields
        if (!empty($existing_schema)) {
            $schema_data = json_decode(str_replace(
                ['<script type="application/ld+json">', '</script>'], '', $existing_schema
            ), true);

            if (is_array($schema_data)) {
                $schema_data['headline']      = $headline;
                $schema_data['description']   = $description;
                $schema_data['datePublished'] = $date_published;
                $schema_data['dateModified']  = $date_modified;

                $schema_json = wp_json_encode($schema_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                update_field('schema', '<script type="application/ld+json">' . $schema_json . '</script>', $post_id);
            }

            return;
        }

        // Generate full schema
        $image_url      = get_the_post_thumbnail_url($post_id, 'full');
        $author_id      = get_post_field('post_author', $post_id);
        $author_name    = get_the_author_meta('display_name', $author_id);
        $author_url     = get_author_posts_url($author_id);
        $publisher_name = "Appetiser";
        $logo_url       = wp_get_attachment_image_url(16529, 'full');
        $post_url       = get_permalink($post_id);

        $schema = [
            "@context" => "https://schema.org",
            "@type" => "BlogPosting",
            "headline" => $headline,
            "description" => $description,
            "image" => $image_url,
            "author" => [
                "@type" => "Person",
                "name" => $author_name,
                "url"  => $author_url
            ],
            "publisher" => [
                "@type" => "Organization",
                "name" => $publisher_name,
                "logo" => [
                    "@type" => "ImageObject",
                    "url" => $logo_url
                ]
            ],
            "datePublished" => $date_published,
            "dateModified" => $date_modified,
            "mainEntityOfPage" => [
                "@type" => "WebPage",
                "@id" => $post_url
            ]
        ];

        $schema_json = wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        update_field('schema', '<script type="application/ld+json">' . $schema_json . '</script>', $post_id);
    }    

    public function handle_general_settings_form() {
        if (!current_user_can('manage_options')) return;
        if (!isset($_POST['save_general_settings'])) return;
        if (!isset($_POST['app_seo_general_nonce']) || !wp_verify_nonce($_POST['app_seo_general_nonce'], 'app_seo_general_save')) return;

        $toggle = isset($_POST['external_links_toggle']) ? '1' : '0';
            update_option('app_seo_external_links', $toggle);

        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>SEO General settings saved.</p></div>';
        });
    }

    public function handle_robots_form() {
        if (!current_user_can('manage_options')) return;
        if (!isset($_POST['appetiser_robots_nonce']) || !wp_verify_nonce($_POST['appetiser_robots_nonce'], 'appetiser_robots_save')) return;

        $robots_path = ABSPATH . 'robots.txt';
        $backup_path = ABSPATH . '.robots-backup';

        // Revert to backup
        if (isset($_POST['restore_robots']) && file_exists($backup_path)) {
            copy($backup_path, $robots_path);
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>robots.txt reverted to previous version.</p></div>';
            });
            return;
        }

        // Reset to default
        if (isset($_POST['reset_robots'])) {
            if (file_exists($robots_path) && !file_exists($backup_path)) {
                copy($robots_path, $backup_path); // Backup before reset
            }
            file_put_contents($robots_path, "User-agent: *\nDisallow:");

            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>robots.txt reset to default. Previous version backed up.</p></div>';
            });
            return;
        }

        // Save + Backup
        if (isset($_POST['save_robots'])) {
            $content = isset($_POST['robots_txt_content']) ? wp_unslash($_POST['robots_txt_content']) : '';
            if (file_exists($robots_path)) {
                copy($robots_path, $backup_path); // backup current file
            }
            file_put_contents($robots_path, $content);

            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>robots.txt updated successfully.</p></div>';
            });
        }
    }

    public function robots_backup_exists() {
        return file_exists(ABSPATH . '.robots-backup');
    }

    public function get_robots_content() {
        $robots_path = ABSPATH . 'robots.txt';
        if (file_exists($robots_path)) {
            return file_get_contents($robots_path);
        }
        return "User-agent: *\nDisallow:";
    }

    public function handle_htaccess_form() {
        if (!current_user_can('manage_options')) return;
        if (!isset($_POST['appetiser_htaccess_nonce']) || !wp_verify_nonce($_POST['appetiser_htaccess_nonce'], 'appetiser_htaccess_save')) return;

        $htaccess_path = ABSPATH . '.htaccess';
        $backup_path   = ABSPATH . '.htaccess-backup';

        // Revert to backup
        if (isset($_POST['restore_htaccess']) && file_exists($backup_path)) {
            copy($backup_path, $htaccess_path);
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>.htaccess reverted to previous version.</p></div>';
            });
            return;
        }

        // Reset to default WordPress rules
        if (isset($_POST['reset_htaccess'])) {
            if (file_exists($htaccess_path) && !file_exists($backup_path)) {
                copy($htaccess_path, $backup_path);
            }

            $default_htaccess = <<<HT
                # BEGIN WordPress
                <IfModule mod_rewrite.c>
                RewriteEngine On
                RewriteBase /
                RewriteRule ^index\.php$ - [L]
                RewriteCond %{REQUEST_FILENAME} !-f
                RewriteCond %{REQUEST_FILENAME} !-d
                RewriteRule . /index.php [L]
                </IfModule>
                # END WordPress
                HT;

            file_put_contents($htaccess_path, $default_htaccess);
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>.htaccess reset to default and previous version backed up.</p></div>';
            });
            return;
        }

        // Save + Backup
        if (isset($_POST['save_htaccess'])) {
            $content = isset($_POST['htaccess_content']) ? wp_unslash($_POST['htaccess_content']) : '';
            if (file_exists($htaccess_path) && !file_exists($backup_path)) {
                copy($htaccess_path, $backup_path);
            }
            file_put_contents($htaccess_path, $content);

            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>.htaccess updated successfully.</p></div>';
            });
        }
    }

    public function get_htaccess_content() {
        $htaccess_path = ABSPATH . '.htaccess';
        return file_exists($htaccess_path) ? file_get_contents($htaccess_path) : '';
    }

    public function htaccess_backup_exists() {
        return file_exists(ABSPATH . '.htaccess-backup');
    }

    public function handle_clear_404_log() {
        if (!current_user_can('manage_options')) return;
        if (!isset($_POST['clear_404_log'])) return;
        if (!isset($_POST['app_clear_404_nonce']) || !wp_verify_nonce($_POST['app_clear_404_nonce'], 'app_clear_404_log')) return;

        $log_file = WP_CONTENT_DIR . '/404-log.txt';
        if (file_exists($log_file)) {
            unlink($log_file);
        }

        // Delete all 404 transients
        global $wpdb;
        $transients = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_404_%'");
        foreach ($transients as $transient) {
            $key = str_replace('_transient_', '', $transient);
            delete_transient($key);
        }

        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>404 log and related transients cleared.</p></div>';
        });
    }

    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>SEO Enhancements</h1>
            <div class="tab">
                <button class="tablinks" onclick="openTab(event, 'general')" id="genlink">General</button>
                <button class="tablinks" onclick="openTab(event, 'robots')" id="robotslink">Robots.txt</button>
                <button class="tablinks" onclick="openTab(event, 'htaccess')" id="htaccesslink">htaccess</button>
                <button class="tablinks" onclick="openTab(event, '404')" id="404link">404 Monitor</button>
            </div>

            <div id="general" class="tabcontent">
                <h2>General</h2>
                 <form method="post" action="">
                    <?php wp_nonce_field('app_seo_general_save', 'app_seo_general_nonce'); ?>
                    <label>
                        <input type="checkbox" name="external_links_toggle" value="1" <?php checked(get_option('app_seo_external_links') === '1'); ?>>
                        Automatically add <code>target="_blank"</code> and <code>rel="nofollow"</code> to external links in single blog posts
                    </label>
                    <p><input type="submit" class="button button-primary" name="save_general_settings" value="Save Settings"></p>
                </form>
            </div>
        
            <div id="robots" class="tabcontent">
                 <h2>Robots.txt</h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('appetiser_robots_save', 'appetiser_robots_nonce'); ?>
                        <textarea name="robots_txt_content" rows="15" style="width:100%; font-family:monospace;"><?php echo esc_textarea($this->get_robots_content()); ?></textarea>
                        <p>
                            <input type="submit" class="button button-primary" name="save_robots" value="Save Robots.txt">
                            <input type="submit" class="button" name="reset_robots" value="Reset to Default" onclick="return confirm('Reset robots.txt to default?');">
                            <?php if ($this->robots_backup_exists()) : ?>
                                <input type="submit" class="button" name="restore_robots" value="Undo (Revert to Previous)">
                            <?php endif; ?>
                        </p>
                    </form>
            </div>
            <div id="htaccess" class="tabcontent">
                <h2>htaccess</h2>
                 <form method="post" action="">
                    <?php wp_nonce_field('appetiser_htaccess_save', 'appetiser_htaccess_nonce'); ?>
                    <textarea name="htaccess_content" rows="15" style="width:100%; font-family:monospace;"><?php echo esc_textarea($this->get_htaccess_content()); ?></textarea>
                    <p>
                        <input type="submit" class="button button-primary" name="save_htaccess" value="Save .htaccess">
                        <input type="submit" class="button" name="reset_htaccess" value="Reset to Default" onclick="return confirm('Reset .htaccess to default WordPress rules?');">
                        <?php if ($this->htaccess_backup_exists()) : ?>
                            <input type="submit" class="button" name="restore_htaccess" value="Undo (Revert to Previous)">
                        <?php endif; ?>
                    </p>
                </form>
            </div>
            <div id="404" class="tabcontent">
                <h2>404 Logs</h2>

                    <form method="post" action="">
                        <?php wp_nonce_field('app_clear_404_log', 'app_clear_404_nonce'); ?>
                        <p><input type="submit" class="button" name="clear_404_log" value="Clear 404 Log"></p>
                    </form>

                    <h3>Log Entries:</h3>
                    <pre style="background:#fff; border:1px solid #ddd; padding:10px; max-height:400px; overflow:auto;"><?php
                        $log_file = WP_CONTENT_DIR . '/404-log.txt';
                        if (file_exists($log_file)) {
                            $log_content = file_get_contents($log_file);

                            // Highlight the 404 URL
                            $highlighted = preg_replace_callback(
                                '/404:\s(https?:\/\/[^\s|]+)/',
                                function($matches) {
                                    return '404: <span style="color:#d63384;font-weight:bold;">' . esc_html($matches[1]) . '</span>';
                                },
                                esc_html($log_content)
                            );

                            echo $highlighted;
                        } else {
                            echo 'No logs found.';
                        }
                    ?></pre>
            </div>
            <div class="bottomtab">
                <a href="#" target="_blank">documentation</a>
            </div>

            

        </div>
        <?php
    }
}
