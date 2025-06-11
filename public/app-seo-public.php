<?php
class Appetiser_SEO_Public {

    public function __construct() {
        add_action( 'wp', array($this, 'log_404_requests'));
        add_action( 'wp_footer', array( $this, 'public_link_handler' ) );
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

    public function public_link_handler(){
        if ( is_singular('post') && get_option('app_seo_external_links') === '1' ){
            $excluded_raw = get_option('app_seo_nofollow_excluded_domains');
            $excluded_domains = array_filter(array_map('trim', explode("\n", $excluded_raw)));
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                if (!document.body.classList.contains('single-post')) return;

                const base = document.querySelector('.infinite-content-container');
                if (!base) return;

                const currentHost = location.hostname;
                const excluded = <?php echo json_encode($excluded_domains); ?>;

                base.querySelectorAll('a[href^="http"]').forEach(link => {
                    const linkHost = (new URL(link.href, location.origin)).hostname;
                    const isExternal = linkHost !== currentHost;
                    const isExcluded = excluded.some(domain => linkHost.includes(domain));

                    if (isExternal) {
                        link.setAttribute('target', '_blank');
                        if(!isExcluded){
                            link.setAttribute('rel', 'nofollow');
                        }
                    }

                    if (!isExternal){
                        link.setAttribute('target', '_blank');
                    }
                });
            });
            </script>
            <?php
        }
    }

}