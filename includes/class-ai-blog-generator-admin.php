<?php
/**
 * Admin class for AI Blog Post Generator
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Blog_Generator_Admin {

    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function update_timer_schedule($value) {
        // Clear existing schedule
        wp_clear_scheduled_hook('ai_blog_generator_cron');

        // Schedule new cron based on the selected interval
        if (!wp_next_scheduled('ai_blog_generator_cron')) {
            wp_schedule_event(time(), $value, 'ai_blog_generator_cron');
        }

        return $value;
    }

    public function add_admin_menu() {
        add_menu_page(
            'AI Blog Generator',
            'AI Blog Generator',
            'manage_options',
            'ai-blog-generator',
            array($this, 'admin_page'),
            'dashicons-edit',
            30
        );

        add_submenu_page(
            'ai-blog-generator',
            'Settings',
            'Settings',
            'manage_options',
            'ai-blog-generator-settings',
            array($this, 'settings_page')
        );

        add_submenu_page(
            'ai-blog-generator',
            __('General Prompts', 'ai-blog-post-generator'),
            __('General Prompts', 'ai-blog-post-generator'),
            'manage_options',
            'ai-blog-generator-prompts',
            array($this, 'prompts_page')
        );

        // Bots submenu is added by AI_Blog_Generator_Bots; avoid duplicating here

        // Logs & Preview page
        add_submenu_page(
            'ai-blog-generator',
            __('Logs & Preview', 'ai-blog-post-generator'),
            __('Logs & Preview', 'ai-blog-post-generator'),
            'manage_options',
            'ai-blog-generator-logs',
            array($this, 'logs_page')
        );
    }

    public function register_settings() {
        // Settings
        register_setting('ai_blog_generator_settings', 'ai_blog_generator_api_key');
        register_setting('ai_blog_generator_settings', 'ai_blog_generator_model');
        register_setting('ai_blog_generator_settings', 'ai_blog_generator_post_status');
        register_setting('ai_blog_generator_settings', 'ai_blog_generator_timer', array($this, 'update_timer_schedule'));
        register_setting('ai_blog_generator_settings', 'ai_blog_generator_enabled');

        // General Prompts (site-wide)
        register_setting('ai_blog_generator_prompts', 'ai_blog_generator_global_instructions');
        register_setting('ai_blog_generator_prompts', 'ai_blog_generator_niche');
        register_setting('ai_blog_generator_prompts', 'ai_blog_generator_categories_list', array($this, 'sanitize_and_create_categories'));
    register_setting('ai_blog_generator_prompts', 'ai_blog_generator_seo_title_format');
    register_setting('ai_blog_generator_prompts', 'ai_blog_generator_seo_meta_prompt');
    register_setting('ai_blog_generator_prompts', 'ai_blog_generator_content_template');
    register_setting('ai_blog_generator_prompts', 'ai_blog_generator_default_category');
    }

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'ai-blog-generator') !== false) {
            wp_enqueue_script('ai-blog-generator-admin', AI_BLOG_GENERATOR_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), AI_BLOG_GENERATOR_VERSION, true);
            wp_enqueue_style('ai-blog-generator-admin', AI_BLOG_GENERATOR_PLUGIN_URL . 'assets/css/admin.css', array(), AI_BLOG_GENERATOR_VERSION);
        }
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>AI Blog Post Generator</h1>
            <?php
            // Show feedback from Run now
            $status = isset($_GET['ai_gen_status']) ? sanitize_text_field($_GET['ai_gen_status']) : '';
            $msg = isset($_GET['ai_gen_msg']) ? sanitize_text_field(wp_unslash($_GET['ai_gen_msg'])) : '';
            $post_id = isset($_GET['ai_gen_post']) ? absint($_GET['ai_gen_post']) : 0;
            if ($status === 'done') {
                echo '<div class="notice notice-success is-dismissible"><p>'.esc_html($msg);
                if ($post_id) {
                    echo ' <a class="button button-small" href="'.esc_url(get_edit_post_link($post_id)).'">'.__('Edit post','ai-blog-post-generator').'</a>';
                    echo ' <a class="button button-small" href="'.esc_url(get_permalink($post_id)).'" target="_blank">'.__('View','ai-blog-post-generator').'</a>';
                }
                echo '</p></div>';
            } elseif ($status === 'error') {
                echo '<div class="notice notice-error is-dismissible"><p>'.esc_html($msg).'</p></div>';
            }
            ?>
            <p>Welcome to the AI Blog Post Generator plugin. Use the menu above to configure settings and prompts.</p>

            <div class="ai-blog-generator-status">
                <h2>Status</h2>
                <p>Generator is currently: <strong><?php echo get_option('ai_blog_generator_enabled') === '1' ? 'Enabled' : 'Disabled'; ?></strong></p>
                <p>Next scheduled generation: <?php echo wp_next_scheduled('ai_blog_generator_cron') ? date('Y-m-d H:i:s', wp_next_scheduled('ai_blog_generator_cron')) : 'Not scheduled'; ?></p>
            </div>

            <div class="ai-blog-generator-status" style="margin-top:20px;">
                <h2><?php _e('Active Bots', 'ai-blog-post-generator'); ?></h2>
                <?php
                $bots = get_posts(array(
                    'post_type' => 'ai_bot',
                    'post_status' => 'publish',
                    'numberposts' => -1,
                    'meta_key' => 'ai_bot_enabled',
                    'meta_value' => '1',
                ));
                if ($bots): ?>
                    <table class="widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Bot', 'ai-blog-post-generator'); ?></th>
                                <th><?php _e('Status', 'ai-blog-post-generator'); ?></th>
                                <th><?php _e('Schedule', 'ai-blog-post-generator'); ?></th>
                                <th><?php _e('Last Run', 'ai-blog-post-generator'); ?></th>
                                <th><?php _e('Next Due', 'ai-blog-post-generator'); ?></th>
                                <th><?php _e('Actions', 'ai-blog-post-generator'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($bots as $b):
                            $running = get_transient('ai_bot_running_'.$b->ID) ? true : false;
                            $schedule = get_post_meta($b->ID, 'ai_bot_schedule', true);
                            if (!$schedule) { $schedule = 'daily'; }
                            $intervals = array(
                                'hourly' => HOUR_IN_SECONDS,
                                'twicedaily' => 12 * HOUR_IN_SECONDS,
                                'daily' => DAY_IN_SECONDS,
                                'weekly' => 7 * DAY_IN_SECONDS,
                            );
                            $interval = isset($intervals[$schedule]) ? $intervals[$schedule] : DAY_IN_SECONDS;
                            $last = (int) get_post_meta($b->ID, 'ai_bot_last_run', true);
                            $next = $last ? ($last + $interval) : time();
                            $run_url = wp_nonce_url(admin_url('admin-post.php?action=ai_blog_generator_run_bot&bot_id='.$b->ID), 'ai_blog_generator_run_bot');
                        ?>
                            <tr>
                                <td><a href="<?php echo esc_url(get_edit_post_link($b->ID)); ?>"><?php echo esc_html($b->post_title); ?></a></td>
                                <td><?php echo $running ? '<span style="color:#008a00;">Running</span>' : 'Idle'; ?></td>
                                <td><?php echo esc_html(ucfirst($schedule)); ?></td>
                                <td><?php echo $last ? esc_html(date('Y-m-d H:i', $last)) : '—'; ?></td>
                                <td><?php echo esc_html(date('Y-m-d H:i', $next)); ?></td>
                                <td><a class="button" href="<?php echo esc_url($run_url); ?>"><?php _e('Run now','ai-blog-post-generator'); ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><?php _e('No active bots.', 'ai-blog-post-generator'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>AI Blog Generator Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('ai_blog_generator_settings'); ?>
                <?php do_settings_sections('ai_blog_generator_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">OpenAI API Key</th>
                        <td>
                            <input type="password" name="ai_blog_generator_api_key" value="<?php echo esc_attr(get_option('ai_blog_generator_api_key')); ?>" class="regular-text" />
                            <p class="description">Enter your OpenAI API key.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Model</th>
                        <td>
                            <select name="ai_blog_generator_model">
                                <option value="gpt-3.5-turbo" <?php selected(get_option('ai_blog_generator_model'), 'gpt-3.5-turbo'); ?>>GPT-3.5 Turbo</option>
                                <option value="gpt-4" <?php selected(get_option('ai_blog_generator_model'), 'gpt-4'); ?>>GPT-4</option>
                                <option value="gpt-4-turbo" <?php selected(get_option('ai_blog_generator_model'), 'gpt-4-turbo'); ?>>GPT-4 Turbo</option>
                                <option value="gpt-5" <?php selected(get_option('ai_blog_generator_model'), 'gpt-5'); ?>>GPT-5</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Post Status</th>
                        <td>
                            <select name="ai_blog_generator_post_status">
                                <option value="draft" <?php selected(get_option('ai_blog_generator_post_status'), 'draft'); ?>>Draft</option>
                                <option value="publish" <?php selected(get_option('ai_blog_generator_post_status'), 'publish'); ?>>Publish</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Generation Interval</th>
                        <td>
                            <select name="ai_blog_generator_timer">
                                <option value="hourly" <?php selected(get_option('ai_blog_generator_timer'), 'hourly'); ?>>Hourly</option>
                                <option value="daily" <?php selected(get_option('ai_blog_generator_timer'), 'daily'); ?>>Daily</option>
                                <option value="weekly" <?php selected(get_option('ai_blog_generator_timer'), 'weekly'); ?>>Weekly</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Enable Generator</th>
                        <td>
                            <input type="checkbox" name="ai_blog_generator_enabled" value="1" <?php checked(get_option('ai_blog_generator_enabled'), '1'); ?> />
                            <label>Enable automatic post generation</label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function prompts_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('General Prompts (Site-wide)', 'ai-blog-post-generator'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('ai_blog_generator_prompts'); ?>
                <?php do_settings_sections('ai_blog_generator_prompts'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Global Instructions', 'ai-blog-post-generator'); ?></th>
                        <td>
                            <textarea name="ai_blog_generator_global_instructions" rows="5" class="large-text"><?php echo esc_textarea(get_option('ai_blog_generator_global_instructions')); ?></textarea>
                            <p class="description"><?php _e('High-level instructions for all content (tone, style, audience, brand rules).', 'ai-blog-post-generator'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Niche', 'ai-blog-post-generator'); ?></th>
                        <td>
                            <input type="text" name="ai_blog_generator_niche" value="<?php echo esc_attr(get_option('ai_blog_generator_niche')); ?>" class="regular-text" />
                            <p class="description"><?php _e('Primary niche/topic for the whole blog.', 'ai-blog-post-generator'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Default Categories (comma-separated)', 'ai-blog-post-generator'); ?></th>
                        <td>
                            <textarea name="ai_blog_generator_categories_list" rows="3" class="large-text"><?php echo esc_textarea(get_option('ai_blog_generator_categories_list')); ?></textarea>
                            <p class="description"><?php _e('On Save, new categories will be created automatically if they do not exist.', 'ai-blog-post-generator'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Default Category (fallback)', 'ai-blog-post-generator'); ?></th>
                        <td>
                            <?php wp_dropdown_categories(array(
                                'name' => 'ai_blog_generator_default_category',
                                'selected' => get_option('ai_blog_generator_default_category'),
                                'show_option_none' => __('— None —', 'ai-blog-post-generator'),
                                'hide_empty' => false
                            )); ?>
                            <p class="description"><?php _e('If a bot has no category selected, this one will be used.', 'ai-blog-post-generator'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('SEO Title Format', 'ai-blog-post-generator'); ?></th>
                        <td>
                            <input type="text" name="ai_blog_generator_seo_title_format" value="<?php echo esc_attr(get_option('ai_blog_generator_seo_title_format')); ?>" class="regular-text" placeholder="{title} | {site}"/>
                            <p class="description"><?php _e('Use placeholders like {title}, {site}, {category}.', 'ai-blog-post-generator'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('SEO Meta Description Prompt', 'ai-blog-post-generator'); ?></th>
                        <td>
                            <textarea name="ai_blog_generator_seo_meta_prompt" rows="3" class="large-text"><?php echo esc_textarea(get_option('ai_blog_generator_seo_meta_prompt')); ?></textarea>
                            <p class="description"><?php _e('Prompt to generate concise meta descriptions for SEO.', 'ai-blog-post-generator'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Content Template (HTML)', 'ai-blog-post-generator'); ?></th>
                        <td>
                            <textarea name="ai_blog_generator_content_template" rows="18" class="large-text code" placeholder="&lt;h2 id=&quot;section&quot;&gt;...&lt;/h2&gt;"><?php echo esc_textarea(get_option('ai_blog_generator_content_template')); ?></textarea>
                            <p class="description"><?php _e('If set, content generations will be instructed to strictly follow this HTML skeleton (keep ids/headings).', 'ai-blog-post-generator'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function sanitize_and_create_categories($value) {
        $value = is_string($value) ? $value : '';
        $names = array_filter(array_map('trim', explode(',', $value)));
        foreach ($names as $name) {
            if (!term_exists($name, 'category')) {
                wp_insert_term($name, 'category');
            }
        }
        return implode(', ', $names);
    }

    public function logs_page() {
        $logs = get_option('ai_blog_generator_logs', array());
        $bots = get_posts(array('post_type'=>'ai_bot','numberposts'=>-1,'post_status'=>'any'));
        ?>
        <div class="wrap">
            <h1><?php _e('Logs & Preview', 'ai-blog-post-generator'); ?></h1>
            <h2><?php _e('Dry run preview', 'ai-blog-post-generator'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('ai_blog_generator_preview_bot'); ?>
                <input type="hidden" name="action" value="ai_blog_generator_preview_bot" />
                <select name="bot_id">
                    <?php foreach($bots as $b): ?>
                        <option value="<?php echo esc_attr($b->ID); ?>"><?php echo esc_html($b->post_title.' (#'.$b->ID.')'); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button(__('Preview', 'ai-blog-post-generator'), 'secondary', '', false); ?>
            </form>

            <h2 style="margin-top:2em;"><?php _e('Recent logs', 'ai-blog-post-generator'); ?></h2>
            <div style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-height:400px;overflow:auto;white-space:pre-wrap;">
                <?php echo esc_html(implode("\n", $logs)); ?>
            </div>
        </div>
        <?php
    }
}