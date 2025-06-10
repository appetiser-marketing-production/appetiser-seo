<?php
class Appetiser_SEO_Public {

    public function __construct() {
        add_action('wp', array($this, 'log_404_requests'));

        if (is_singular('post') && get_option('app_seo_external_links') === '1') {
            add_action('wp_footer', array($this, 'inject_external_link_script'), 100);
        }
    }

    public function log_404_requests() {
        if (!is_404()) return;
        if (is_admin()) return;
        
        $url = home_url(add_query_arg([], $_SERVER['REQUEST_URI']));
        $transient_key = '404_' . md5($url);

        if (get_transient($transient_key)) return;
        set_transient($transient_key, true, DAY_IN_SECONDS);

        $referrer  = $_SERVER['HTTP_REFERER'] ?? 'Direct';
        $user_ip   = $_SERVER['REMOTE_ADDR'];
        $timestamp = current_time('mysql');

        $log_entry = sprintf("[%s] 404: %s | Referrer: %s | IP: %s\n", $timestamp, $url, $referrer, $user_ip);
        error_log($log_entry, 3, WP_CONTENT_DIR . '/404-log.txt');
    }

    public function inject_external_link_script() {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const base = document.querySelector('.single div#single-article-content');

            if (!base) return;

            // Set all links inside #single-article-content to _blank
            base.querySelectorAll('a').forEach(link => {
                link.setAttribute('target', '_blank');
            });

            // Set links inside <ul><li> to _self
            base.querySelectorAll('ul li a').forEach(link => {
                link.setAttribute('target', '_self');
            });

            // Set links in <table> and <p> back to _blank
            base.querySelectorAll('table a, p a').forEach(link => {
                link.setAttribute('target', '_blank');
            });

            // Set links in ul#new li to _blank
            base.querySelectorAll('ul#new li a').forEach(link => {
                link.setAttribute('target', '_blank');
            });
        });
        </script>
        <?php
    }


}