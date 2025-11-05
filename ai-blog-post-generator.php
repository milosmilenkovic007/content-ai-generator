<?php
/**
 * Plugin Name: AI Blog Post Generator
 * Plugin URI: https://example.com/ai-blog-post-generator
 * Description: Automatically generates blog posts using OpenAI Chat API based on custom prompts.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: ai-blog-post-generator
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('AI_BLOG_GENERATOR_VERSION', '1.0.0');
define('AI_BLOG_GENERATOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AI_BLOG_GENERATOR_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once AI_BLOG_GENERATOR_PLUGIN_DIR . 'includes/class-ai-blog-generator.php';
require_once AI_BLOG_GENERATOR_PLUGIN_DIR . 'includes/class-ai-blog-generator-admin.php';
require_once AI_BLOG_GENERATOR_PLUGIN_DIR . 'includes/class-ai-blog-generator-api.php';
require_once AI_BLOG_GENERATOR_PLUGIN_DIR . 'includes/class-ai-blog-generator-bots.php';
require_once AI_BLOG_GENERATOR_PLUGIN_DIR . 'includes/class-ai-blog-generator-media.php';
require_once AI_BLOG_GENERATOR_PLUGIN_DIR . 'includes/class-ai-blog-generator-examples.php';

// Activation hook
register_activation_hook(__FILE__, 'ai_blog_generator_activate');
function ai_blog_generator_activate() {
    // Set default options
    add_option('ai_blog_generator_api_key', '');
    add_option('ai_blog_generator_model', 'gpt-3.5-turbo');
    add_option('ai_blog_generator_post_status', 'draft');
    add_option('ai_blog_generator_timer', 'daily');
    add_option('ai_blog_generator_enabled', '0');

    // Schedule cron job
    if (!wp_next_scheduled('ai_blog_generator_cron')) {
        wp_schedule_event(time(), 'daily', 'ai_blog_generator_cron');
    }
    // Flush rewrite rules to register CPT REST routes cleanly on first run
    flush_rewrite_rules();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'ai_blog_generator_deactivate');
function ai_blog_generator_deactivate() {
    // Clear scheduled cron job
    wp_clear_scheduled_hook('ai_blog_generator_cron');
    flush_rewrite_rules();
}

// Initialize the plugin
function ai_blog_generator_init() {
    (new AI_Blog_Generator())->init();
    (new AI_Blog_Generator_Bots())->init();
    (new AI_Blog_Generator_Media())->init();
    (new AI_Blog_Generator_Examples())->init();
}
add_action('plugins_loaded', 'ai_blog_generator_init');

// Cron action
add_action('ai_blog_generator_cron', 'ai_blog_generator_generate_post');
function ai_blog_generator_generate_post() {
    if (get_option('ai_blog_generator_enabled') !== '1') {
        return;
    }
    // Generate for all active bots, honoring their individual schedules
    $args = array(
        'post_type' => 'ai_bot',
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_key' => 'ai_bot_enabled',
        'meta_value' => '1',
    );
    $bots = get_posts($args);
    if (!$bots) {
        return;
    }
    $generator = new AI_Blog_Generator_API();
    foreach ($bots as $bot) {
        $schedule = get_post_meta($bot->ID, 'ai_bot_schedule', true);
        if (!$schedule) { $schedule = 'daily'; }
        $intervals = array(
            'hourly' => HOUR_IN_SECONDS,
            'twicedaily' => 12 * HOUR_IN_SECONDS,
            'daily' => DAY_IN_SECONDS,
            'weekly' => 7 * DAY_IN_SECONDS,
        );
        $interval = isset($intervals[$schedule]) ? $intervals[$schedule] : DAY_IN_SECONDS;
        $last = (int) get_post_meta($bot->ID, 'ai_bot_last_run', true);
        if (!$last || (time() - $last) >= $interval) {
            set_transient('ai_bot_running_'.$bot->ID, time(), 30 * MINUTE_IN_SECONDS);
            $post_id = $generator->generate_post_for_bot($bot->ID);
            delete_transient('ai_bot_running_'.$bot->ID);
            update_post_meta($bot->ID, 'ai_bot_last_run', time());
            if ($post_id) {
                ai_blog_generator_log('Bot #'.$bot->ID.' created post ID '.$post_id);
            }
        }
    }
}

// Admin action to run generation for a single bot immediately
add_action('admin_post_ai_blog_generator_run_bot', function() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Unauthorized', 'ai-blog-post-generator'));
    }
    check_admin_referer('ai_blog_generator_run_bot');
    $bot_id = isset($_GET['bot_id']) ? absint($_GET['bot_id']) : 0;
    if ($bot_id) {
        $generator = new AI_Blog_Generator_API();
        set_transient('ai_bot_running_'.$bot_id, time(), 30 * MINUTE_IN_SECONDS);
        $generator->generate_post_for_bot($bot_id);
        delete_transient('ai_bot_running_'.$bot_id);
    }
    wp_safe_redirect(wp_get_referer());
    exit;
});

// Admin action: Preview (dry run) a bot
add_action('admin_post_ai_blog_generator_preview_bot', function(){
    if (!current_user_can('manage_options')) { wp_die(__('Unauthorized', 'ai-blog-post-generator')); }
    check_admin_referer('ai_blog_generator_preview_bot');
    $bot_id = isset($_POST['bot_id']) ? absint($_POST['bot_id']) : 0;
    if (!$bot_id) { wp_safe_redirect(wp_get_referer()); exit; }
    $generator = new AI_Blog_Generator_API();
    $result = $generator->generate_post_for_bot($bot_id, true);
    echo '<div class="wrap"><h1>Preview for Bot #'.esc_html($bot_id).'</h1>';
    if ($result && is_array($result)) {
        echo '<h2>Title</h2><p>'.esc_html($result['title']).'</p>';
        echo '<h2>Excerpt</h2><p>'.wp_kses_post(nl2br(esc_html($result['excerpt']))).'</p>';
        echo '<h2>Content</h2><div style="white-space:pre-wrap;background:#fff;border:1px solid #ccd0d4;padding:12px">'.wp_kses_post(esc_html($result['content'])).'</div>';
        echo '<h2>Tags</h2><p>'.esc_html($result['tags']).'</p>';
        echo '<p><a href="'.esc_url(wp_get_referer()).'" class="button">Back</a></p>';
    } else {
        echo '<p>No preview available.</p><p><a href="'.esc_url(wp_get_referer()).'" class="button">Back</a></p>';
    }
    echo '</div>';
    exit;
});

// Simple plugin logger (stored in options)
function ai_blog_generator_log($message) {
    $logs = get_option('ai_blog_generator_logs', array());
    $logs[] = '['.date('Y-m-d H:i:s').'] '.$message;
    if (count($logs) > 200) { $logs = array_slice($logs, -200); }
    update_option('ai_blog_generator_logs', $logs, false);
}

// Progress reporting: store in transient and expose via AJAX
add_action('ai_blog_generator_progress', function($bot_id, $message, $step, $total){
    set_transient('ai_bot_progress_'.$bot_id, array(
        'status' => 'running',
        'message' => (string) $message,
        'step' => (int) $step,
        'total' => (int) $total,
        'ts' => time(),
    ), 30 * MINUTE_IN_SECONDS);
}, 10, 4);

add_action('wp_ajax_ai_blog_generate_now', function(){
    if (!current_user_can('manage_options')) { wp_send_json_error('forbidden', 403); }
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'ai_blog_gen_now')) { wp_send_json_error('bad-nonce', 403); }
    $bot_id = isset($_POST['bot_id']) ? absint($_POST['bot_id']) : 0;
    if (!$bot_id) { wp_send_json_error('missing', 400); }
    set_transient('ai_bot_progress_'.$bot_id, array('status'=>'running','message'=>'Queued…','step'=>0,'total'=>8,'ts'=>time()), 30*MINUTE_IN_SECONDS);
    try {
        $generator = new AI_Blog_Generator_API();
        $post_id = $generator->generate_post_for_bot($bot_id);
        if ($post_id) {
            set_transient('ai_bot_progress_'.$bot_id, array('status'=>'done','message'=>'Post created #'.$post_id,'step'=>8,'total'=>8,'post_id'=>$post_id,'ts'=>time()), 10*MINUTE_IN_SECONDS);
            wp_send_json_success(array('post_id'=>$post_id));
        } else {
            // If skipped or failed, mark error; details should already be in progress stream
            $current = get_transient('ai_bot_progress_'.$bot_id);
            $msg = $current && !empty($current['message']) ? $current['message'] : 'Generation failed or skipped.';
            set_transient('ai_bot_progress_'.$bot_id, array('status'=>'error','message'=>$msg,'step'=>0,'total'=>8,'ts'=>time()), 10*MINUTE_IN_SECONDS);
            wp_send_json_success(array('status'=>'error'));
        }
    } catch (Throwable $e) {
        set_transient('ai_bot_progress_'.$bot_id, array('status'=>'error','message'=>$e->getMessage(),'step'=>0,'total'=>8,'ts'=>time()), 10*MINUTE_IN_SECONDS);
        wp_send_json_error('exception', 500);
    }
});

add_action('wp_ajax_ai_blog_generation_progress', function(){
    if (!current_user_can('manage_options')) { wp_send_json_error('forbidden', 403); }
    $nonce = isset($_GET['nonce']) ? sanitize_text_field($_GET['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'ai_blog_gen_now')) { wp_send_json_error('bad-nonce', 403); }
    $bot_id = isset($_GET['bot_id']) ? absint($_GET['bot_id']) : 0;
    if (!$bot_id) { wp_send_json_error('missing', 400); }
    $data = get_transient('ai_bot_progress_'.$bot_id);
    if (!$data) { $data = array('status'=>'idle','message'=>'','step'=>0,'total'=>8); }
    wp_send_json_success($data);
});