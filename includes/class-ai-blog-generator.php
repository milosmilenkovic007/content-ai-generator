<?php
/**
 * Main plugin class
 */

if (!defined('ABSPATH')) {
    exit;
}

class AI_Blog_Generator {

    public function init() {
        // Initialize admin
        if (is_admin()) {
            $admin = new AI_Blog_Generator_Admin();
            $admin->init();
        }
    }
}