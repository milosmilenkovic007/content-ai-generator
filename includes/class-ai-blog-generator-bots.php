<?php
/**
 * Bots (multiple agents) CPT and meta management
 */

if (!defined('ABSPATH')) { exit; }

class AI_Blog_Generator_Bots {

    public function init() {
        add_action('init', array($this, 'register_cpt'));
    add_action('add_meta_boxes', array($this, 'register_meta_boxes'));
    add_action('save_post_ai_bot', array($this, 'save_bot_meta'));
    add_filter('manage_ai_bot_posts_columns', array($this, 'columns'));
    add_action('manage_ai_bot_posts_custom_column', array($this, 'column_content'), 10, 2);
    add_filter('post_row_actions', array($this, 'row_actions'), 10, 2);
    add_action('admin_enqueue_scripts', array($this, 'enqueue_media'));
    add_action('admin_menu', array($this, 'maybe_add_submenus'));
    // Force classic editor for this CPT even if block editor is enabled
    add_filter('use_block_editor_for_post_type', function($use, $post_type){
        if ($post_type === 'ai_bot') { return false; }
        return $use;
    }, 10, 2);
    }

    public function register_cpt() {
        $labels = array(
            'name' => __('Bots', 'ai-blog-post-generator'),
            'singular_name' => __('Bot', 'ai-blog-post-generator'),
            'add_new' => __('Add New', 'ai-blog-post-generator'),
            'add_new_item' => __('Add New Bot', 'ai-blog-post-generator'),
            'edit_item' => __('Edit Bot', 'ai-blog-post-generator'),
            'new_item' => __('New Bot', 'ai-blog-post-generator'),
            'view_item' => __('View Bot', 'ai-blog-post-generator'),
            'search_items' => __('Search Bots', 'ai-blog-post-generator'),
            'not_found' => __('No bots found', 'ai-blog-post-generator'),
        );
        $args = array(
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false, // We'll add under our plugin menu
            // Only title; no Gutenberg editor/content area
            'supports' => array('title'),
            'capability_type' => 'post',
            'map_meta_cap' => true,
            // Keep REST enabled for admin flows, but force classic editor via filter
            'show_in_rest' => true,
            'rest_base' => 'ai-bot',
            'rest_controller_class' => 'WP_REST_Posts_Controller',
            'rewrite' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
        );
        register_post_type('ai_bot', $args);
    }

    public function maybe_add_submenus() {
        // Add Bots as submenu of our main plugin menu
        add_submenu_page(
            'ai-blog-generator',
            __('Bots', 'ai-blog-post-generator'),
            __('Bots', 'ai-blog-post-generator'),
            'manage_options',
            'edit.php?post_type=ai_bot'
        );
        add_submenu_page(
            'ai-blog-generator',
            __('Add New Bot', 'ai-blog-post-generator'),
            __('Add New Bot', 'ai-blog-post-generator'),
            'manage_options',
            'post-new.php?post_type=ai_bot'
        );
    }

    public function enqueue_media($hook) {
        if (strpos($hook, 'ai_bot') !== false) {
            wp_enqueue_script('ai-blog-generator-bot-admin', AI_BLOG_GENERATOR_PLUGIN_URL . 'assets/js/bot-admin.js', array('jquery'), AI_BLOG_GENERATOR_VERSION, true);
            wp_localize_script('ai-blog-generator-bot-admin', 'AIBotGen', array(
                'ajax' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ai_blog_gen_now'),
                'i18n' => array(
                    'starting' => __('Starting generation…','ai-blog-post-generator'),
                    'done' => __('Done','ai-blog-post-generator'),
                    'error' => __('Error','ai-blog-post-generator')
                )
            ));
        }
    }

    public function register_meta_boxes() {
        add_meta_box('ai_bot_settings', __('Bot Settings', 'ai-blog-post-generator'), array($this, 'meta_settings'), 'ai_bot', 'normal', 'high');
        add_meta_box('ai_bot_prompts', __('Bot Prompts', 'ai-blog-post-generator'), array($this, 'meta_prompts'), 'ai_bot', 'normal', 'default');
        // Removed old Bot Library (text/pdf) per new requirement
        add_meta_box('ai_bot_image_category', __('Image Source Category', 'ai-blog-post-generator'), array($this, 'meta_image_category'), 'ai_bot', 'side', 'default');
        add_meta_box('ai_bot_actions', __('Actions', 'ai-blog-post-generator'), array($this, 'meta_actions'), 'ai_bot', 'side', 'high');
        add_meta_box('ai_bot_example_cats', __('Example Categories', 'ai-blog-post-generator'), array($this, 'meta_example_categories'), 'ai_bot', 'side', 'default');
    }

    public function meta_settings($post) {
        wp_nonce_field('ai_bot_save_meta', 'ai_bot_nonce');
        $enabled   = get_post_meta($post->ID, 'ai_bot_enabled', true);
        $status    = get_post_meta($post->ID, 'ai_bot_post_status', true);
        $category  = get_post_meta($post->ID, 'ai_bot_category', true);
        $schedule  = get_post_meta($post->ID, 'ai_bot_schedule', true);
        $model     = get_post_meta($post->ID, 'ai_bot_model', true);
        $temp      = get_post_meta($post->ID, 'ai_bot_temperature', true);
        $sem_dedup = get_post_meta($post->ID, 'ai_bot_semantic_dedup', true);
        $sem_thr   = get_post_meta($post->ID, 'ai_bot_semantic_threshold', true);
        ?>
        <p>
            <label><input type="checkbox" name="ai_bot_enabled" value="1" <?php checked($enabled, '1'); ?>> <?php _e('Enable this bot', 'ai-blog-post-generator'); ?></label>
        </p>
        <p>
            <label for="ai_bot_post_status"><strong><?php _e('Post Status', 'ai-blog-post-generator'); ?></strong></label><br>
            <select name="ai_bot_post_status" id="ai_bot_post_status">
                <option value=""><?php _e('Use global setting', 'ai-blog-post-generator'); ?></option>
                <option value="draft" <?php selected($status, 'draft'); ?>><?php _e('Draft'); ?></option>
                <option value="publish" <?php selected($status, 'publish'); ?>><?php _e('Publish'); ?></option>
            </select>
        </p>
        <p>
            <label for="ai_bot_category"><strong><?php _e('Category', 'ai-blog-post-generator'); ?></strong></label><br>
            <?php
            // Show ALL post categories, including empty ones, so the user can target any category
            wp_dropdown_categories(array(
                'taxonomy' => 'category',
                'name' => 'ai_bot_category',
                'selected' => $category,
                'hierarchical' => true,
                'hide_empty' => false,
                'orderby' => 'name',
                'show_option_none' => __('Select Category', 'ai-blog-post-generator')
            ));
            ?>
        </p>
        <p>
            <label for="ai_bot_schedule"><strong><?php _e('Schedule', 'ai-blog-post-generator'); ?></strong></label><br>
            <select name="ai_bot_schedule" id="ai_bot_schedule">
                <option value="hourly" <?php selected($schedule, 'hourly'); ?>><?php _e('Hourly'); ?></option>
                <option value="twicedaily" <?php selected($schedule, 'twicedaily'); ?>><?php _e('Twice Daily'); ?></option>
                <option value="daily" <?php selected($schedule, 'daily'); ?>><?php _e('Daily'); ?></option>
                <option value="weekly" <?php selected($schedule, 'weekly'); ?>><?php _e('Weekly'); ?></option>
            </select>
        </p>
        <p>
            <label for="ai_bot_model"><strong><?php _e('Model', 'ai-blog-post-generator'); ?></strong></label><br>
            <select name="ai_bot_model" id="ai_bot_model">
                <option value=""><?php _e('Use global setting', 'ai-blog-post-generator'); ?></option>
                <option value="gpt-3.5-turbo" <?php selected($model, 'gpt-3.5-turbo'); ?>>GPT-3.5 Turbo</option>
                <option value="gpt-4" <?php selected($model, 'gpt-4'); ?>>GPT-4</option>
                <option value="gpt-4-turbo" <?php selected($model, 'gpt-4-turbo'); ?>>GPT-4 Turbo</option>
                <option value="gpt-5" <?php selected($model, 'gpt-5'); ?>>GPT-5</option>
            </select>
        </p>
        <p>
            <label for="ai_bot_temperature"><strong><?php _e('Temperature', 'ai-blog-post-generator'); ?></strong></label><br>
            <input type="number" min="0" max="2" step="0.1" name="ai_bot_temperature" id="ai_bot_temperature" value="<?php echo esc_attr($temp); ?>" placeholder="0.7" style="width:100%">
        </p>
        <p>
            <label><input type="checkbox" name="ai_bot_semantic_dedup" value="1" <?php checked($sem_dedup, '1'); ?>> <?php _e('Enable semantic duplicate avoidance (embeddings)', 'ai-blog-post-generator'); ?></label>
        </p>
        <p>
            <label for="ai_bot_semantic_threshold"><strong><?php _e('Similarity threshold', 'ai-blog-post-generator'); ?></strong></label><br>
            <input type="number" min="0" max="1" step="0.01" name="ai_bot_semantic_threshold" id="ai_bot_semantic_threshold" value="<?php echo esc_attr($sem_thr ? $sem_thr : '0.90'); ?>" style="width:100%">
        </p>
        <?php
    }

    public function meta_prompts($post) {
        $fields = array(
            'ai_bot_general_prompt' => __('General Prompt (instructions)', 'ai-blog-post-generator'),
            'ai_bot_title_prompt' => __('Title Prompt', 'ai-blog-post-generator'),
            'ai_bot_content_prompt' => __('Content Prompt', 'ai-blog-post-generator'),
            'ai_bot_excerpt_prompt' => __('Excerpt Prompt', 'ai-blog-post-generator'),
            'ai_bot_tags_prompt' => __('Tags Prompt (comma-separated)', 'ai-blog-post-generator'),
            'ai_bot_image_prompt' => __('Featured Image Prompt', 'ai-blog-post-generator'),
        );
        foreach ($fields as $key => $label) {
            $val = get_post_meta($post->ID, $key, true);
            echo '<p><label for="'.$key.'"><strong>'.$label.'</strong></label><br/>';
            echo '<textarea id="'.$key.'" name="'.$key.'" rows="3" class="widefat">'.esc_textarea($val).'</textarea></p>';
        }
    }

    public function meta_image_category($post) {
        $term_id = (int) get_post_meta($post->ID, 'ai_bot_image_category', true);
        $terms = get_terms(array('taxonomy'=>AI_Blog_Generator_Media::TAX,'hide_empty'=>false));
        echo '<select name="ai_bot_image_category" id="ai_bot_image_category">';
        echo '<option value="">— '.__('Select Image Category','ai-blog-post-generator').' —</option>';
        foreach ($terms as $t) {
            echo '<option value="'.esc_attr($t->term_id).'" '.selected($term_id,$t->term_id,false).'>'.esc_html($t->name).'</option>';
        }
        echo '</select>';
        echo '<p class="description">'.__('Bots will pick featured images from this category in the AI Media Library.', 'ai-blog-post-generator').'</p>';
    }

    public function meta_example_categories($post) {
        $selected = get_post_meta($post->ID, 'ai_bot_example_categories', true);
        $selected = is_array($selected) ? array_map('intval', $selected) : array();
        $terms = get_terms(array('taxonomy'=>AI_Blog_Generator_Examples::TAX, 'hide_empty'=>false));
        echo '<select name="ai_bot_example_categories[]" id="ai_bot_example_categories" multiple style="width:100%">';
        foreach ($terms as $t) {
            $sel = in_array($t->term_id, $selected) ? 'selected' : '';
            echo '<option value="'.esc_attr($t->term_id).'" '.$sel.'>'.esc_html($t->name).'</option>';
        }
        echo '</select>';
        echo '<p class="description">'.__('Pick Example Categories; the bot will consult these sites before generating articles.', 'ai-blog-post-generator').'</p>';
    }

    // Fallback Featured Images meta box removed per requirements

    public function meta_actions($post) {
        $url = wp_nonce_url(admin_url('admin-post.php?action=ai_blog_generator_run_bot&bot_id='.$post->ID), 'ai_blog_generator_run_bot');
        echo '<p><button type="button" class="button button-primary" id="ai-bot-generate-now" data-bot="'.esc_attr($post->ID).'">'.__('Generate Now', 'ai-blog-post-generator').'</button> ';
        echo '<a href="'.esc_url($url).'" class="button" style="margin-left:6px">'.__('Fallback link', 'ai-blog-post-generator').'</a></p>';
        echo '<div id="ai-bot-progress" style="display:none;border:1px solid #ccd0d4;background:#fff;height:10px;border-radius:3px;overflow:hidden">'
            .'<div id="ai-bot-progress-bar" style="height:100%;width:0;background:#2271b1"></div>'
            .'</div>';
        echo '<p id="ai-bot-progress-text" style="display:none;margin-top:6px;font-size:12px;color:#555"></p>';
    }

    public function save_bot_meta($post_id) {
        if (!isset($_POST['ai_bot_nonce']) || !wp_verify_nonce($_POST['ai_bot_nonce'], 'ai_bot_save_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return; }
        if (!current_user_can('edit_post', $post_id)) { return; }

    $checkbox = isset($_POST['ai_bot_enabled']) ? '1' : '';
        update_post_meta($post_id, 'ai_bot_enabled', $checkbox);
        $status = isset($_POST['ai_bot_post_status']) ? sanitize_text_field($_POST['ai_bot_post_status']) : '';
        update_post_meta($post_id, 'ai_bot_post_status', $status);
        $category = isset($_POST['ai_bot_category']) ? absint($_POST['ai_bot_category']) : '';
        update_post_meta($post_id, 'ai_bot_category', $category);
    $schedule = isset($_POST['ai_bot_schedule']) ? sanitize_text_field($_POST['ai_bot_schedule']) : '';
    update_post_meta($post_id, 'ai_bot_schedule', $schedule);
    $model = isset($_POST['ai_bot_model']) ? sanitize_text_field($_POST['ai_bot_model']) : '';
    update_post_meta($post_id, 'ai_bot_model', $model);
    $temp = isset($_POST['ai_bot_temperature']) ? sanitize_text_field($_POST['ai_bot_temperature']) : '';
    update_post_meta($post_id, 'ai_bot_temperature', $temp);
    $sem_dedup = isset($_POST['ai_bot_semantic_dedup']) ? '1' : '';
    update_post_meta($post_id, 'ai_bot_semantic_dedup', $sem_dedup);
    $sem_thr = isset($_POST['ai_bot_semantic_threshold']) ? sanitize_text_field($_POST['ai_bot_semantic_threshold']) : '';
    update_post_meta($post_id, 'ai_bot_semantic_threshold', $sem_thr);

        $keys = array('ai_bot_general_prompt','ai_bot_title_prompt','ai_bot_content_prompt','ai_bot_excerpt_prompt','ai_bot_tags_prompt','ai_bot_image_prompt');
        foreach ($keys as $k) {
            if (isset($_POST[$k])) {
                update_post_meta($post_id, $k, wp_kses_post($_POST[$k]));
            }
        }
        // Save image category
        $img_cat = isset($_POST['ai_bot_image_category']) ? absint($_POST['ai_bot_image_category']) : '';
        update_post_meta($post_id, 'ai_bot_image_category', $img_cat);
        
        // Save Example Categories selection
        $ex_cats = isset($_POST['ai_bot_example_categories']) ? (array) $_POST['ai_bot_example_categories'] : array();
        $ex_cats = array_values(array_unique(array_filter(array_map('absint', $ex_cats))));
        update_post_meta($post_id, 'ai_bot_example_categories', $ex_cats);
    }

    public function columns($columns) {
        $columns['enabled'] = __('Enabled', 'ai-blog-post-generator');
        return $columns;
    }

    public function column_content($column, $post_id) {
        if ($column === 'enabled') {
            echo get_post_meta($post_id, 'ai_bot_enabled', true) === '1' ? '✔' : '—';
        }
    }

    public function row_actions($actions, $post) {
        if ($post->post_type === 'ai_bot') {
            $url = wp_nonce_url(admin_url('admin-post.php?action=ai_blog_generator_run_bot&bot_id='.$post->ID), 'ai_blog_generator_run_bot');
            $actions['generate_now'] = '<a href="'.esc_url($url).'">'.__('Generate Now', 'ai-blog-post-generator').'</a>';
        }
        return $actions;
    }
}
