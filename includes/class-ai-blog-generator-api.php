<?php
/**
 * API class for OpenAI integration
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Blog_Generator_API {

    private $api_key;
    private $model;
    private $default_temperature = 0.7;

    public function __construct() {
        $this->api_key = get_option('ai_blog_generator_api_key');
        $this->model = get_option('ai_blog_generator_model', 'gpt-3.5-turbo');
    }

    public function generate_post_for_bot($bot_id, $dry_run = false) {
        if (empty($this->api_key)) {
            error_log('AI Blog Generator: API key not set');
            return false;
        }

        $general_prompt = get_post_meta($bot_id, 'ai_bot_general_prompt', true);
        $title_prompt    = get_post_meta($bot_id, 'ai_bot_title_prompt', true);
        $content_prompt  = get_post_meta($bot_id, 'ai_bot_content_prompt', true);
        $excerpt_prompt  = get_post_meta($bot_id, 'ai_bot_excerpt_prompt', true);
        $tags_prompt     = get_post_meta($bot_id, 'ai_bot_tags_prompt', true);
        $image_prompt    = get_post_meta($bot_id, 'ai_bot_image_prompt', true);

        // Build library context text + examples
        $library_text = $this->get_library_text_for_bot($bot_id);
        $examples_text = $this->get_examples_text_for_bot($bot_id);
        if (!empty($examples_text)) {
            $library_text = trim($library_text . "\n\nExamples from selected external sites:\n" . $examples_text);
        }

        // Get recent post titles to avoid duplicates
    $existing_titles = $this->get_recent_titles();

    // Progress: starting generation
    if (!$dry_run) { do_action('ai_blog_generator_progress', $bot_id, 'Generating title…', 1, 8); }

    $bot_model = get_post_meta($bot_id, 'ai_bot_model', true);
    $bot_temperature = get_post_meta($bot_id, 'ai_bot_temperature', true);
    $temperature = $bot_temperature !== '' ? floatval($bot_temperature) : $this->default_temperature;
    $model_to_use = $bot_model ? $bot_model : $this->model;

    $title = $this->generate_content_from_prompt($title_prompt, $general_prompt, $library_text, $existing_titles, 'title', $model_to_use, $temperature);
    $title = $this->enforce_single_title($title);
    if (!$title) {
        if (!$dry_run) { do_action('ai_blog_generator_progress', $bot_id, 'No title generated — aborting.', 1, 8); }
        error_log('AI Blog Generator: Failed to generate a valid title for bot '.$bot_id);
        return false;
    }

    if (!$dry_run) { do_action('ai_blog_generator_progress', $bot_id, 'Generating content…', 2, 8); }
    $content = $this->generate_content_from_prompt($content_prompt, $general_prompt, $library_text, $existing_titles, 'content', $model_to_use, $temperature);
    if (!$dry_run) { do_action('ai_blog_generator_progress', $bot_id, 'Generating excerpt…', 3, 8); }
    $excerpt = $this->generate_content_from_prompt($excerpt_prompt, $general_prompt, $library_text, $existing_titles, 'excerpt', $model_to_use, $temperature);
    if (!$dry_run) { do_action('ai_blog_generator_progress', $bot_id, 'Generating tags…', 4, 8); }
    $tags = $this->generate_content_from_prompt($tags_prompt, $general_prompt, $library_text, $existing_titles, 'tags', $model_to_use, $temperature);
    if (!$dry_run) { do_action('ai_blog_generator_progress', $bot_id, 'Preparing image prompt…', 5, 8); }
    $image_description = $this->generate_content_from_prompt($image_prompt, $general_prompt, $library_text, $existing_titles, 'image', $model_to_use, $temperature);

        if (!$title || !$content) {
            error_log('AI Blog Generator: Failed to generate title or content for bot '.$bot_id);
            if (!$dry_run) { do_action('ai_blog_generator_progress', $bot_id, 'Missing title or content — aborting.', 5, 8); }
            return false;
        }

        // Basic duplicate check by title
        if ($this->title_exists($title)) {
            error_log('AI Blog Generator: Skipping post because similar title exists: '.$title);
            if (!$dry_run) { do_action('ai_blog_generator_progress', $bot_id, 'Skipped: similar title already exists.', 5, 8); }
            return false;
        }

        // Optional semantic deduplication
        $use_semantic = get_post_meta($bot_id, 'ai_bot_semantic_dedup', true) === '1';
        if ($use_semantic && !empty($this->api_key)) {
            $threshold = get_post_meta($bot_id, 'ai_bot_semantic_threshold', true);
            $threshold = $threshold !== '' ? floatval($threshold) : 0.90;
            $candidate_embed = $this->get_embedding(mb_substr($title."\n\n".$excerpt,0,800));
            if ($candidate_embed) {
                $recent_ids = get_posts(array('post_type'=>'post','post_status'=>'publish','numberposts'=>50,'fields'=>'ids'));
                foreach ($recent_ids as $pid) {
                    $embed = $this->get_post_title_embedding($pid);
                    if ($embed) {
                        $sim = $this->cosine_similarity($candidate_embed, $embed);
                        if ($sim >= $threshold) {
                            error_log('AI Blog Generator: Semantic duplicate detected vs post '.$pid.' (sim='.round($sim,3).')');
                            if (!$dry_run) { do_action('ai_blog_generator_progress', $bot_id, 'Skipped: too similar to existing post (semantic).', 5, 8); }
                            return false;
                        }
                    }
                }
            }
        }

        $status_opt = get_post_meta($bot_id, 'ai_bot_post_status', true);
        $status = $status_opt ? $status_opt : get_option('ai_blog_generator_post_status', 'draft');
        $category = (int) get_post_meta($bot_id, 'ai_bot_category', true);
        if (!$category) {
            $default_cat = (int) get_option('ai_blog_generator_default_category');
            if ($default_cat) { $category = $default_cat; }
        }

        if (!$dry_run) { do_action('ai_blog_generator_progress', $bot_id, 'Creating post…', 6, 8); }

        $post_data = array(
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status' => $status,
            'post_author' => get_current_user_id() ?: 1,
            'post_category' => $category ? array($category) : array(),
        );

        if ($dry_run) {
            return array(
                'title' => $title,
                'content' => $content,
                'excerpt' => $excerpt,
                'tags' => $tags,
                'image_description' => $image_description,
            );
        }

        $post_id = wp_insert_post($post_data);

        if ($post_id) {
            if (!$dry_run) { do_action('ai_blog_generator_progress', $bot_id, 'Applying SEO fields…', 7, 8); }
            // Fill SEO (ACF) fields based on generated content
            $this->update_seo_fields($post_id, $title, $content, $excerpt, $tags, $bot_id);
            if ($tags) {
                $tag_array = array_map('trim', explode(',', $tags));
                wp_set_post_tags($post_id, $tag_array);
            }
            if (!$dry_run) { do_action('ai_blog_generator_progress', $bot_id, 'Setting featured image…', 8, 8); }
            if ($image_description) {
                // Try real image generation; if fails, use fallback images; else placeholder
                if (!$this->generate_featured_image_openai($post_id, $image_description)) {
                    // Try image category from AI media library
                    $term_id = (int) get_post_meta($bot_id, 'ai_bot_image_category', true);
                    if (!$this->set_random_image_from_category($term_id, $post_id)) {
                        if (!$this->set_random_fallback_featured_image($bot_id, $post_id)) {
                            $this->generate_featured_image($post_id, $image_description);
                        }
                    }
                }
            } else {
                // No description: try category, then fallback, then placeholder
                $term_id = (int) get_post_meta($bot_id, 'ai_bot_image_category', true);
                if (!$this->set_random_image_from_category($term_id, $post_id)) {
                    if (!$this->set_random_fallback_featured_image($bot_id, $post_id)) {
                        $this->generate_featured_image($post_id, $image_description);
                    }
                }
            }
            // Sync theme media ACF fields with the featured image if set
            $this->sync_media_fields_with_thumbnail($post_id, $image_description);
            error_log('AI Blog Generator: Post generated successfully by bot '.$bot_id.' - ID: ' . $post_id);
            return $post_id;
        }
        error_log('AI Blog Generator: Failed to create post for bot '.$bot_id);
        if (!$dry_run) { do_action('ai_blog_generator_progress', $bot_id, 'Failed to create post.', 6, 8); }
        return false;
    }

    private function get_examples_text_for_bot($bot_id) {
        $term_ids = get_post_meta($bot_id, 'ai_bot_example_categories', true);
        if (!is_array($term_ids) || empty($term_ids)) { return ''; }
        $examples = get_posts(array(
            'post_type' => 'ai_example',
            'post_status' => 'publish',
            'posts_per_page' => 8,
            'tax_query' => array(
                array('taxonomy'=>AI_Blog_Generator_Examples::TAX,'field'=>'term_id','terms'=>$term_ids)
            )
        ));
        if (!$examples) { return ''; }
        $parts = array();
        foreach ($examples as $ex) {
            $url = get_post_meta($ex->ID, 'ai_example_url', true);
            if (!$url) { continue; }
            $cached = get_post_meta($ex->ID, 'ai_example_summary', true);
            $cached_at = (int) get_post_meta($ex->ID, 'ai_example_summary_cached_at', true);
            $summary = '';
            if ($cached && (time() - $cached_at) < DAY_IN_SECONDS) {
                $summary = $cached;
            } else {
                $summary = $this->fetch_site_summary($url);
                if ($summary) {
                    update_post_meta($ex->ID, 'ai_example_summary', $summary);
                    update_post_meta($ex->ID, 'ai_example_summary_cached_at', time());
                }
            }
            if (!$summary) { $summary = $url; }
            $parts[] = $ex->post_title . ' — ' . $url . "\n" . $summary;
            if (strlen(implode("\n\n", $parts)) > 4000) { break; }
        }
        return implode("\n\n", $parts);
    }

    private function fetch_site_summary($url) {
        $resp = wp_remote_get($url, array('timeout'=>12, 'redirection'=>5));
        if (is_wp_error($resp)) { return ''; }
        $html = wp_remote_retrieve_body($resp);
        if (!$html) { return ''; }
        $html = wp_strip_all_tags($html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse whitespace
        $html = preg_replace('/\s+/', ' ', $html);
        $text = trim($html);
        if (mb_strlen($text) > 800) { $text = mb_substr($text, 0, 800).'…'; }
        return $text;
    }

    private function update_seo_fields($post_id, $title, $content, $excerpt, $tags, $bot_id) {
        // Build SEO title
        $format = get_option('ai_blog_generator_seo_title_format');
        $category_id = (int) get_post_meta($bot_id, 'ai_bot_category', true);
        $cat_name = $category_id ? get_cat_name($category_id) : '';
        $seo_title = $title;
        if (!empty($format)) {
            $seo_title = strtr($format, array(
                '{title}' => $title,
                '{site}' => get_bloginfo('name'),
                '{category}' => $cat_name,
            ));
        }

        // Generate SEO description (<=160 chars) via prompt if provided, else trim excerpt
        $meta_prompt = get_option('ai_blog_generator_seo_meta_prompt');
        $seo_desc = '';
        if (!empty($meta_prompt)) {
            $messages = array(
                array('role'=>'system','content'=>'You write concise, compelling SEO meta descriptions (<=160 characters). Return only the final description, no quotes.'),
                array('role'=>'user','content'=>$meta_prompt."\n\nTitle: ".$title."\nExcerpt: ".$excerpt."\nContent (truncated): ".mb_substr(wp_strip_all_tags($content),0,1200)),
            );
            $resp = $this->call_openai_api_messages($messages, 'seo');
            if ($resp) { $seo_desc = trim($resp); }
        }
        if (empty($seo_desc)) {
            $seo_desc = wp_trim_words(wp_strip_all_tags($content), 28, '');
            if (mb_strlen($seo_desc) > 160) { $seo_desc = mb_substr($seo_desc, 0, 157).'…'; }
        }

        // Keywords: reuse tags if available
        $seo_keywords = $tags ? implode(',', array_map('trim', explode(',', $tags))) : '';

        // Canonical and indexing
        $canonical = get_permalink($post_id);
        $indexing = 'index,follow';
        $last_reviewed = current_time('c');

        // Update via ACF if available; otherwise fallback to post meta
        if (function_exists('update_field')) {
            update_field('tw_seo_title', $seo_title, $post_id);
            update_field('tw_seo_description', $seo_desc, $post_id);
            update_field('tw_seo_keywords', $seo_keywords, $post_id);
            update_field('tw_canonical_url', $canonical, $post_id);
            update_field('tw_indexing', $indexing, $post_id);
            update_field('tw_last_reviewed', $last_reviewed, $post_id);
        } else {
            update_post_meta($post_id, 'tw_seo_title', $seo_title);
            update_post_meta($post_id, 'tw_seo_description', $seo_desc);
            update_post_meta($post_id, 'tw_seo_keywords', $seo_keywords);
            update_post_meta($post_id, 'tw_canonical_url', $canonical);
            update_post_meta($post_id, 'tw_indexing', $indexing);
            update_post_meta($post_id, 'tw_last_reviewed', $last_reviewed);
        }
    }

    private function sync_media_fields_with_thumbnail($post_id, $image_description = '') {
        $thumb_id = get_post_thumbnail_id($post_id);
        if (!$thumb_id) { return; }
        // Use same ID for desktop/mobile/social by default
        if (function_exists('update_field')) {
            update_field('tw_feat_image_desktop', $thumb_id, $post_id);
            update_field('tw_feat_image_mobile', $thumb_id, $post_id);
            update_field('tw_feat_image_social', $thumb_id, $post_id);
            // Alt/Title/Caption
            $alt = $image_description ? $image_description : get_the_title($post_id);
            update_field('tw_feat_alt', wp_strip_all_tags(mb_substr($alt,0,120)), $post_id);
            update_field('tw_feat_title_attr', get_the_title($post_id), $post_id);
            update_field('tw_feat_caption', '', $post_id);
        } else {
            update_post_meta($post_id, 'tw_feat_image_desktop', $thumb_id);
            update_post_meta($post_id, 'tw_feat_image_mobile', $thumb_id);
            update_post_meta($post_id, 'tw_feat_image_social', $thumb_id);
            update_post_meta($post_id, 'tw_feat_alt', wp_strip_all_tags(mb_substr($image_description ? $image_description : get_the_title($post_id),0,120)));
            update_post_meta($post_id, 'tw_feat_title_attr', get_the_title($post_id));
        }
        // Also update the attachment ALT text
        if ($image_description) {
            update_post_meta($thumb_id, '_wp_attachment_image_alt', wp_strip_all_tags(mb_substr($image_description,0,120)));
        }
    }

    private function generate_content_from_prompt($prompt, $general_prompt, $library_text, $existing_titles, $type_label = '', $model_override = null, $temperature = null) {
        $prompt = (string) $prompt;
        if (empty($prompt)) { return ''; }

        $messages = array();
        // Global site-wide instructions
        $global_instructions = get_option('ai_blog_generator_global_instructions');
        if (!empty($global_instructions)) {
            $messages[] = array('role' => 'system', 'content' => $global_instructions);
        }
        if (!empty($general_prompt)) {
            $messages[] = array('role' => 'system', 'content' => $general_prompt);
        }
        if (!empty($library_text)) {
            $messages[] = array('role' => 'system', 'content' => 'Reference library content (do not quote verbatim beyond fair use, use for facts and structure):\n'. $library_text);
        }
        if (!empty($existing_titles)) {
            $messages[] = array('role' => 'system', 'content' => 'Existing posts on this site (avoid duplicating topics and angles):\n'. implode("\n", $existing_titles));
        }
        if ($type_label === 'title') {
            $messages[] = array('role' => 'system', 'content' => 'Return ONLY a single, human-readable SEO title on one line. Max 60 characters. No quotes, no surrounding punctuation, no prefixes like "Title:" or numbering, no markdown or code fences.');
        }
        if ($type_label === 'content') {
            $tpl = get_option('ai_blog_generator_content_template');
            if (!empty($tpl)) {
                $messages[] = array('role' => 'system', 'content' => 'Use EXACTLY the following HTML template structure. Keep the headings and id attributes. Replace the placeholder text with accurate, well-structured content about the topic. Return ONLY valid HTML filled with content, nothing else.\n'.$tpl);
            }
        }
        $messages[] = array('role' => 'user', 'content' => $prompt);

        $response = $this->call_openai_api_messages($messages, $type_label, $model_override, $temperature);
        return $response ? trim($response) : '';
    }

    private function enforce_single_title($title) {
        $t = (string) $title;
        // take first line only
        $t = preg_split("/(\r\n|\r|\n)/", $t, 2)[0];
        // strip markdown/code fences and common prefixes
        $t = preg_replace('/^\s*(#+|[-*]\s+|Title\s*:\s*|H1\s*:\s*)/i', '', $t);
        // remove quotes/backticks
        $t = trim($t, " \t\n\r\0\x0B\"'`»«“”‚‘’—-:");
        // strip tags
        $t = wp_strip_all_tags($t);
        // collapse whitespace
        $t = preg_replace('/\s+/', ' ', $t);
        $t = trim($t);
        // limit length
        if (mb_strlen($t) > 60) { $t = rtrim(mb_substr($t, 0, 60), ' ,.;:-'); }
        // fallback if empty
        if ($t === '') { return ''; }
        return $t;
    }

    private function call_openai_api_messages($messages, $type_label = '', $model_override = null, $temperature = null) {
        $url = 'https://api.openai.com/v1/chat/completions';
        $body = array(
            'model' => $model_override ? $model_override : $this->model,
            'messages' => $messages,
            'max_tokens' => $type_label === 'content' ? 1800 : 600,
            'temperature' => $temperature !== null ? $temperature : $this->default_temperature,
        );
        $args = array(
            'body' => wp_json_encode($body),
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 45
        );
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            error_log('AI Blog Generator API Error: ' . $response->get_error_message());
            return false;
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (isset($data['choices'][0]['message']['content'])) {
            return $data['choices'][0]['message']['content'];
        }
        error_log('AI Blog Generator: Invalid API response');
        return false;
    }

    private function generate_featured_image($post_id, $description) {
        // For now, we'll use a placeholder. In a real implementation,
        // you might use DALL-E or another image generation API
        // For this example, we'll create a simple colored image

        $upload_dir = wp_upload_dir();
        $filename = 'ai-generated-' . $post_id . '.png';

        // Create a simple colored image (placeholder)
        $image = imagecreatetruecolor(800, 600);
        $bg_color = imagecolorallocate($image, rand(0, 255), rand(0, 255), rand(0, 255));
        imagefill($image, 0, 0, $bg_color);

        $file_path = $upload_dir['path'] . '/' . $filename;
        imagepng($image, $file_path);
        imagedestroy($image);

        // Upload to WordPress
        $attachment = array(
            'post_mime_type' => 'image/png',
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        $attach_id = wp_insert_attachment($attachment, $file_path, $post_id);

        if (!is_wp_error($attach_id)) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
            wp_update_attachment_metadata($attach_id, $attach_data);
            set_post_thumbnail($post_id, $attach_id);
        }
    }

    private function get_library_text_for_bot($bot_id) {
        $ids = get_post_meta($bot_id, 'ai_bot_library_ids', true);
        $ids = is_array($ids) ? $ids : array_filter(array_map('absint', explode(',', (string)$ids)));
        if (!$ids) { return ''; }
        $parts = array();
        foreach ($ids as $id) {
            $path = get_attached_file($id);
            if (!$path || !file_exists($path)) { continue; }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, array('txt','md','csv','json'))) {
                $txt = @file_get_contents($path);
                if ($txt) { $parts[] = $txt; }
            } elseif ($ext === 'pdf') {
                // Attempt to parse via Smalot\PdfParser if available
                $class = '\\Smalot\\PdfParser\\Parser';
                if (class_exists($class)) {
                    try {
                        $parser = new $class();
                        $pdf = $parser->parseFile($path);
                        $parts[] = method_exists($pdf, 'getText') ? $pdf->getText() : '';
                    } catch (\Throwable $e) {
                        error_log('AI Blog Generator: PDF parse failed - '.$e->getMessage());
                    }
                } else {
                    error_log('AI Blog Generator: PDF parser not available. Skipping '.$path);
                }
            }
        }
        // Limit size to avoid huge prompts
        $text = trim(implode("\n\n---\n\n", $parts));
        if (strlen($text) > 15000) {
            $text = substr($text, 0, 15000);
        }
        return $text;
    }

    private function get_recent_titles($days = 120, $limit = 50) {
        $q = new \WP_Query(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'date_query' => array(array(
                'after' => $days.' days ago'
            )),
            'posts_per_page' => $limit,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        $titles = array();
        foreach ($q->posts as $pid) { $titles[] = get_the_title($pid); }
        return $titles;
    }

    private function title_exists($title) {
        $existing = get_page_by_title($title, OBJECT, 'post');
        if ($existing) { return true; }
        // Loose similarity check against recent titles
        $titles = $this->get_recent_titles(365, 200);
        foreach ($titles as $t) {
            similar_text(mb_strtolower($t), mb_strtolower($title), $perc);
            if ($perc >= 85) { return true; }
        }
        return false;
    }

    private function generate_featured_image_openai($post_id, $prompt) {
        // Try to generate an image with OpenAI Images API (DALL·E/gpt-image-1). Returns true on success.
        if (empty($this->api_key)) { return false; }
        $url = 'https://api.openai.com/v1/images/generations';
        $body = array(
            'model' => 'gpt-image-1',
            'prompt' => $prompt,
            'size' => '1024x1024',
        );
        $args = array(
            'body' => wp_json_encode($body),
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 60
        );
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) { return false; }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($data['data'][0]['url'])) { return false; }
        $image_url = $data['data'][0]['url'];
        // Download and attach
        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) { return false; }
        $file = array(
            'name' => 'ai-image-'.time().'.png',
            'tmp_name' => $tmp,
        );
        $id = media_handle_sideload($file, $post_id);
        if (is_wp_error($id)) { @unlink($tmp); return false; }
        set_post_thumbnail($post_id, $id);
        return true;
    }

    private function set_random_fallback_featured_image($bot_id, $post_id) {
        $ids = get_post_meta($bot_id, 'ai_bot_fallback_image_ids', true);
        $ids = is_array($ids) ? $ids : array_filter(array_map('absint', explode(',', (string)$ids)));
        if (!$ids) { return false; }
        $id = $ids[array_rand($ids)];
        if ($id) { set_post_thumbnail($post_id, $id); return true; }
        return false;
    }

    private function set_random_image_from_category($term_id, $post_id) {
        $term_id = (int) $term_id;
        if (!$term_id) { return false; }
        $images = get_posts(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 50,
            'post_mime_type' => 'image',
            'tax_query' => array(
                array(
                    'taxonomy' => AI_Blog_Generator_Media::TAX,
                    'field' => 'term_id',
                    'terms' => array($term_id),
                )
            )
        ));
        if (!$images) { return false; }
        $img = $images[array_rand($images)];
        set_post_thumbnail($post_id, $img->ID);
        return true;
    }

    // Embeddings helpers
    private function get_embedding($text) {
        if (empty($this->api_key)) { return null; }
        $url = 'https://api.openai.com/v1/embeddings';
        $body = array(
            'model' => 'text-embedding-3-small',
            'input' => $text,
        );
        $args = array(
            'body' => wp_json_encode($body),
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 45
        );
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) { return null; }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['data'][0]['embedding'])) { return $data['data'][0]['embedding']; }
        return null;
    }

    private function get_post_title_embedding($post_id) {
        $embed = get_post_meta($post_id, 'ai_title_embed', true);
        if (is_array($embed) && !empty($embed)) { return $embed; }
        $title = get_the_title($post_id);
        if (!$title) { return null; }
        $embed = $this->get_embedding($title);
        if ($embed) { update_post_meta($post_id, 'ai_title_embed', $embed); }
        return $embed;
    }

    private function cosine_similarity($a, $b) {
        if (!$a || !$b) { return 0; }
        $dot = 0; $na = 0; $nb = 0; $len = min(count($a), count($b));
        for ($i=0; $i<$len; $i++) { $dot += $a[$i]*$b[$i]; $na += $a[$i]*$a[$i]; $nb += $b[$i]*$b[$i]; }
        if ($na == 0 || $nb == 0) { return 0; }
        return $dot / (sqrt($na) * sqrt($nb));
    }
}