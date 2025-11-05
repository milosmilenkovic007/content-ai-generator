<?php
/**
 * Media Library for AI images and taxonomy management
 */

if (!defined('ABSPATH')) { exit; }

class AI_Blog_Generator_Media {

    const TAX = 'ai_image_category';

    public function init() {
        add_action('init', array($this, 'register_taxonomy'));
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
        add_action('wp_ajax_ai_blog_attach_images', array($this, 'ajax_attach_images'));
        add_action('wp_ajax_ai_blog_list_images', array($this, 'ajax_list_images'));
        add_action('wp_ajax_ai_blog_remove_image', array($this, 'ajax_remove_image'));
        add_action('wp_ajax_ai_blog_remove_all_images', array($this, 'ajax_remove_all_images'));
        add_action('admin_post_ai_blog_create_image_category', array($this, 'create_term'));
    }

    public function register_taxonomy() {
        $labels = array(
            'name' => __('AI Image Categories', 'ai-blog-post-generator'),
            'singular_name' => __('AI Image Category', 'ai-blog-post-generator'),
            'search_items' => __('Search AI Image Categories', 'ai-blog-post-generator'),
            'all_items' => __('All AI Image Categories', 'ai-blog-post-generator'),
            'edit_item' => __('Edit AI Image Category', 'ai-blog-post-generator'),
            'update_item' => __('Update AI Image Category', 'ai-blog-post-generator'),
            'add_new_item' => __('Add New AI Image Category', 'ai-blog-post-generator'),
            'new_item_name' => __('New AI Image Category', 'ai-blog-post-generator'),
            'menu_name' => __('AI Image Categories', 'ai-blog-post-generator'),
        );
        register_taxonomy(self::TAX, 'attachment', array(
            'hierarchical' => true,
            'labels' => $labels,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => false,
            'show_in_rest' => true,
        ));
    }

    public function add_menu() {
        add_submenu_page(
            'ai-blog-generator',
            __('Media Library', 'ai-blog-post-generator'),
            __('Media Library', 'ai-blog-post-generator'),
            'upload_files',
            'ai-blog-generator-media',
            array($this, 'render_page')
        );
    }

    public function enqueue($hook) {
        if (strpos($hook, 'ai-blog-generator_page_ai-blog-generator-media') !== false) {
            wp_enqueue_media();
            wp_enqueue_script('ai-blog-generator-media', AI_BLOG_GENERATOR_PLUGIN_URL.'assets/js/media-library.js', array('jquery'), AI_BLOG_GENERATOR_VERSION, true);
            wp_localize_script('ai-blog-generator-media', 'AIBlogMedia', array(
                'ajax' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ai_blog_attach_images'),
                'i18n' => array(
                    'selectCategory' => __('Select a category first.', 'ai-blog-post-generator'),
                    'assigned' => __('Images assigned to category.', 'ai-blog-post-generator'),
                    'assignError' => __('Error assigning images.', 'ai-blog-post-generator'),
                    'loadedNone' => __('No images in this category yet.', 'ai-blog-post-generator'),
                    'remove' => __('Remove', 'ai-blog-post-generator'),
                    'removeAll' => __('Remove all from this category', 'ai-blog-post-generator'),
                    'removeAllConfirm' => __('This will remove all images from this category (images will stay in Media Library). Continue?', 'ai-blog-post-generator'),
                    'removeAllDone' => __('All images were removed from the category.', 'ai-blog-post-generator'),
                    'loadMore' => __('Load more', 'ai-blog-post-generator')
                )
            ));
        }
    }

    public function render_page() {
        $terms = get_terms(array('taxonomy'=>self::TAX, 'hide_empty'=>false));
        ?>
        <div class="wrap">
            <h1><?php _e('AI Media Library', 'ai-blog-post-generator'); ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:1rem;">
                <?php wp_nonce_field('ai_blog_create_image_category'); ?>
                <input type="hidden" name="action" value="ai_blog_create_image_category">
                <input type="text" name="term_name" class="regular-text" placeholder="<?php esc_attr_e('New category name', 'ai-blog-post-generator'); ?>">
                <?php submit_button(__('Create Category','ai-blog-post-generator'), 'secondary', '', false); ?>
            </form>

            <p><strong><?php _e('Assign images to a category:', 'ai-blog-post-generator'); ?></strong></p>
            <p>
                <select id="ai-media-term" style="min-width:220px;">
                    <option value="">— <?php _e('Select Category','ai-blog-post-generator'); ?> —</option>
                    <?php foreach ($terms as $t): ?>
                        <option value="<?php echo esc_attr($t->term_id); ?>"><?php echo esc_html($t->name); ?></option>
                    <?php endforeach; ?>
                </select>
                <button id="ai-media-add" class="button button-primary"><?php _e('Add Images','ai-blog-post-generator'); ?></button>
            </p>
            <p class="description"><?php _e('Selected images will be assigned to the chosen AI Image Category and used by bots when picking featured images.', 'ai-blog-post-generator'); ?></p>

            <hr style="margin: 18px 0;">
            <h2 style="margin-bottom:10px;"><?php _e('Images in selected category', 'ai-blog-post-generator'); ?></h2>
            <p>
                <button id="ai-media-remove-all" class="button" disabled><?php _e('Remove all from this category', 'ai-blog-post-generator'); ?></button>
            </p>
            <style>
                .ai-media-grid{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
                .ai-media-item{position:relative;width:120px;height:120px;border:1px solid #ccd0d4;border-radius:4px;overflow:hidden;background:#fff}
                .ai-media-item img{width:100%;height:100%;object-fit:cover}
                .ai-media-remove{position:absolute;top:4px;right:4px;background:#dc3232;color:#fff;border:none;border-radius:2px;font-size:11px;padding:2px 6px;cursor:pointer;opacity:.9}
                .ai-media-remove:hover{opacity:1}
                .ai-media-empty{margin-top:8px;color:#666}
            </style>
            <div id="ai-media-grid" class="ai-media-grid" aria-live="polite"></div>
            <p id="ai-media-empty" class="ai-media-empty" style="display:none;"></p>
            <p><button id="ai-media-load-more" class="button" style="display:none;"></button></p>
        </div>
        <?php
    }

    public function ajax_attach_images() {
        check_ajax_referer('ai_blog_attach_images', 'nonce');
        if (!current_user_can('upload_files')) { wp_send_json_error('forbidden', 403); }
        $ids = isset($_POST['ids']) ? array_map('absint', (array) $_POST['ids']) : array();
        $term_id = isset($_POST['term_id']) ? absint($_POST['term_id']) : 0;
        if (!$ids || !$term_id) { wp_send_json_error('missing', 400); }
        foreach ($ids as $id) {
            wp_set_object_terms($id, array($term_id), self::TAX, true);
        }
        wp_send_json_success(array('count'=>count($ids)));
    }

    public function ajax_list_images() {
        check_ajax_referer('ai_blog_attach_images', 'nonce');
        if (!current_user_can('upload_files')) { wp_send_json_error('forbidden', 403); }
        $term_id = isset($_GET['term_id']) ? absint($_GET['term_id']) : 0;
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = isset($_GET['per_page']) ? max(1, absint($_GET['per_page'])) : 40;
        if (!$term_id) { wp_send_json_success(array('items'=>array())); }
        $query = new WP_Query(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $per_page,
            'paged' => $paged,
            'tax_query' => array(
                array(
                    'taxonomy' => self::TAX,
                    'field'    => 'term_id',
                    'terms'    => array($term_id),
                )
            )
        ));
        $items = array();
        foreach ($query->posts as $p) {
            $items[] = array(
                'id' => $p->ID,
                'src' => wp_get_attachment_image_url($p->ID, 'thumbnail'),
                'alt' => get_post_meta($p->ID, '_wp_attachment_image_alt', true)
            );
        }
        $has_more = ($query->max_num_pages > $paged);
        wp_send_json_success(array('items'=>$items, 'has_more'=>$has_more));
    }

    public function ajax_remove_image() {
        check_ajax_referer('ai_blog_attach_images', 'nonce');
        if (!current_user_can('upload_files')) { wp_send_json_error('forbidden', 403); }
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $term_id = isset($_POST['term_id']) ? absint($_POST['term_id']) : 0;
        if (!$id || !$term_id) { wp_send_json_error('missing', 400); }
        $res = wp_remove_object_terms($id, array($term_id), self::TAX);
        if (is_wp_error($res)) { wp_send_json_error($res->get_error_message(), 500); }
        wp_send_json_success();
    }

    public function ajax_remove_all_images() {
        check_ajax_referer('ai_blog_attach_images', 'nonce');
        if (!current_user_can('upload_files')) { wp_send_json_error('forbidden', 403); }
        $term_id = isset($_POST['term_id']) ? absint($_POST['term_id']) : 0;
        if (!$term_id) { wp_send_json_error('missing', 400); }
        $query = new WP_Query(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => array(
                array(
                    'taxonomy' => self::TAX,
                    'field'    => 'term_id',
                    'terms'    => array($term_id),
                )
            )
        ));
        foreach ($query->posts as $id) {
            wp_remove_object_terms($id, array($term_id), self::TAX);
        }
        wp_send_json_success();
    }

    public function create_term() {
        if (!current_user_can('upload_files')) { wp_die('forbidden'); }
        check_admin_referer('ai_blog_create_image_category');
        $name = isset($_POST['term_name']) ? sanitize_text_field($_POST['term_name']) : '';
        if ($name) { wp_insert_term($name, self::TAX); }
        wp_safe_redirect(wp_get_referer());
        exit;
    }
}
