<?php
class Appetiser_SEO_Admin {

    public function __construct() {
        add_action( 'wp_insert_post', array( $this, 'auto_generateblog_schema' ), 99, 3);
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


}
