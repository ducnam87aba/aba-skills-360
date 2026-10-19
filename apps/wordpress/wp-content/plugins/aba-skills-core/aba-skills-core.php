<?php
/**
 * Plugin Name: ABA Skills Core
 * Plugin URI:  https://github.com/ducnam87aba/aba-skills-360
 * Description: Core backend engine for ABA Skills 360 (Assessment, Skills Profile, Recommendations, Gamification).
 * Version:     0.1.0
 * Author:      ABA Team
 * Author URI:  https://github.com/ducnam87aba
 * Text Domain: aba-skills-core
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP:      8.1
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ABA_SKILLS_CORE_VERSION', '0.1.0');
define('ABA_SKILLS_CORE_PATH', plugin_dir_path(__FILE__));
define('ABA_SKILLS_CORE_URL', plugin_dir_url(__FILE__));

/**
 * Autoloader for ABA Skills Core classes
 */
spl_autoload_register(function ($class) {
    $prefix = 'ABA\\Core\\';
    $base_dir = ABA_SKILLS_CORE_PATH . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Plugin Activation: Register Custom Post Types & verify tables
 */
register_activation_hook(__FILE__, function () {
    // Custom post types for editorial content
    // Flush rewrite rules on activation
    flush_rewrite_rules();
});

/**
 * Plugin Deactivation
 */
register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

/**
 * Initialize Plugin & REST APIs
 */
add_action('init', function () {
    // Register custom post types: aba_course, aba_module, aba_lesson, aba_assessment, aba_badge, aba_mission
    register_post_type('aba_assessment', [
        'labels' => [
            'name' => __('Assessments', 'aba-skills-core'),
            'singular_name' => __('Assessment', 'aba-skills-core'),
        ],
        'public' => false,
        'show_ui' => true,
        'supports' => ['title', 'editor', 'custom-fields'],
        'show_in_rest' => true,
    ]);

    register_post_type('aba_course', [
        'labels' => [
            'name' => __('Courses', 'aba-skills-core'),
            'singular_name' => __('Course', 'aba-skills-core'),
        ],
        'public' => false,
        'show_ui' => true,
        'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
        'show_in_rest' => true,
    ]);

    register_post_type('aba_lesson', [
        'labels' => [
            'name' => __('Lessons', 'aba-skills-core'),
            'singular_name' => __('Lesson', 'aba-skills-core'),
        ],
        'public' => false,
        'show_ui' => true,
        'supports' => ['title', 'editor', 'custom-fields'],
        'show_in_rest' => true,
    ]);
});

/**
 * Register ABA REST Endpoints: /wp-json/aba/v1/
 */
add_action('rest_api_init', function () {
    $namespace = 'aba/v1';

    // Me endpoint
    register_rest_route($namespace, '/me', [
        'methods' => 'GET',
        'callback' => function () {
            $user_id = get_current_user_id();
            if (!$user_id) {
                return new WP_Error('UNAUTHENTICATED', 'Authentication is required.', ['status' => 401]);
            }
            $user = get_userdata($user_id);
            return rest_ensure_response([
                'id' => $user->ID,
                'username' => $user->user_login,
                'email' => $user->user_email,
                'displayName' => $user->display_name,
                'roles' => $user->roles,
            ]);
        },
        'permission_callback' => '__return_true',
    ]);
});
