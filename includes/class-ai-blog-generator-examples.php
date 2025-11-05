<?php
/**
 * Examples (external sites) CPT and taxonomy
 */

if (!defined('ABSPATH')) { exit; }

class AI_Blog_Generator_Examples {

    const TAX = 'ai_example_category';

    public function init() {
        add_action('init', array($this, 'register_cpt_and_tax'));
        add_action('add_meta_boxes', array($this, 'register_meta_boxes'));
        add_action('save_post_ai_example', array($this, 'save_meta'));
        add_action('admin_menu', array($this, 'add_submenus'));
        add_filter('use_block_editor_for_post_type', function($use, $post_type){
            if ($post_type === 'ai_example') { return false; }
            return $use;
        }, 10, 2);
    }

    public function register_cpt_and_tax() {
        // Taxonomy for grouping examples
        register_taxonomy(self::TAX, 'ai_example', array(
            'hierarchical' => true,
            'labels' => array(
                'name' => __('Example Categories', 'ai-blog-post-generator'),
                'singular_name' => __('Example Category', 'ai-blog-post-generator'),
            ),
            'show_ui' => true,
            'show_in_rest' => true,
            'rewrite' => false,
        ));

        // CPT for examples
        register_post_type('ai_example', array(
            'labels' => array(
                'name' => __('Examples', 'ai-blog-post-generator'),
                'singular_name' => __('Example', 'ai-blog-post-generator'),
                'add_new_item' => __('Add New Example', 'ai-blog-post-generator'),
                'edit_item' => __('Edit Example', 'ai-blog-post-generator'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false, // attach under plugin menu
            'supports' => array('title'),
            'show_in_rest' => true,
            'rewrite' => false,
        ));
    }

    public function add_submenus() {
        add_submenu_page(
            'ai-blog-generator',
            __('Examples', 'ai-blog-post-generator'),
            __('Examples', 'ai-blog-post-generator'),
            'manage_options',
            'edit.php?post_type=ai_example'
        );
        add_submenu_page(
            'ai-blog-generator',
            __('Add New Example', 'ai-blog-post-generator'),
            __('Add New Example', 'ai-blog-post-generator'),
            'manage_options',
            'post-new.php?post_type=ai_example'
        );
    }

    public function register_meta_boxes() {
        add_meta_box('ai_example_details', __('Example Details', 'ai-blog-post-generator'), array($this, 'meta_details'), 'ai_example', 'normal', 'high');
    }

    public function meta_details($post) {
        wp_nonce_field('ai_example_save_meta', 'ai_example_nonce');
        $url = get_post_meta($post->ID, 'ai_example_url', true);
        $notes = get_post_meta($post->ID, 'ai_example_notes', true);
        echo '<p><label for="ai_example_url"><strong>'.__('Website URL','ai-blog-post-generator').'</strong></label><br/>';
        echo '<input type="url" class="widefat" id="ai_example_url" name="ai_example_url" value="'.esc_attr($url).'" placeholder="https://example.com"/></p>';
        echo '<p><label for="ai_example_notes"><strong>'.__('Notes','ai-blog-post-generator').'</strong></label><br/>';
        echo '<textarea id="ai_example_notes" name="ai_example_notes" rows="4" class="widefat">'.esc_textarea($notes).'</textarea></p>';
        echo '<p class="description">'.__('Assign this Example to one or more Example Categories (right sidebar). Bots can target categories in their settings.','ai-blog-post-generator').'</p>';
    }

    public function save_meta($post_id) {
        if (!isset($_POST['ai_example_nonce']) || !wp_verify_nonce($_POST['ai_example_nonce'], 'ai_example_save_meta')) { return; }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
        if (!current_user_can('edit_post', $post_id)) { return; }
        $url = isset($_POST['ai_example_url']) ? esc_url_raw($_POST['ai_example_url']) : '';
        $notes = isset($_POST['ai_example_notes']) ? wp_kses_post($_POST['ai_example_notes']) : '';
        update_post_meta($post_id, 'ai_example_url', $url);
        update_post_meta($post_id, 'ai_example_notes', $notes);
    }
}
