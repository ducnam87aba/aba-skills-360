<?php
/**
 * Plugin Name: ABA Skills Core
 * Plugin URI:  https://github.com/ducnam87aba/aba-skills-360
 * Description: Core backend engine for ABA Skills 360 (Assessment Engine, Skills Profile, Recommendations, Gamification).
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
 * Plugin Activation
 */
register_activation_hook(__FILE__, function () {
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
    // Custom post types for editorial content only
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

    // Current user me endpoint
    register_rest_route($namespace, '/me', [
        'methods' => 'GET',
        'callback' => function () {
            return rest_ensure_response([
                'id' => 101,
                'username' => 'namduong',
                'email' => 'nam.duong@example.edu.vn',
                'displayName' => 'Dương Đức Nam',
                'roles' => ['student'],
                'studentId' => 1,
                'schoolId' => 10,
            ]);
        },
        'permission_callback' => '__return_true',
    ]);

    // Student me endpoint
    register_rest_route($namespace, '/students/me', [
        'methods' => 'GET',
        'callback' => function () {
            $studentRepo = new \ABA\Core\Repositories\StudentRepository();
            $student = $studentRepo->findById(1);
            if ($student) {
                return rest_ensure_response($student);
            }
            return rest_ensure_response([
                'id' => 1,
                'userId' => 101,
                'studentCode' => 'ABA-2026-001',
                'firstName' => 'Nam',
                'lastName' => 'Dương Đức',
                'fullName' => 'Dương Đức Nam',
                'gradeLevel' => 4,
                'gradeGroup' => '4_6',
                'schoolId' => 10,
                'schoolName' => 'Trường Tiểu Học Quốc Tế Á Châu',
                'classId' => 402,
                'className' => '4A2',
                'avatarUrl' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?w=150&auto=format&fit=crop&q=80',
                'totalXp' => 1450,
                'currentStreak' => 7,
                'longestStreak' => 14,
                'level' => 8,
                'lastActiveAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
        },
        'permission_callback' => '__return_true',
    ]);

    // Controllers
    $assessmentController = new \ABA\Core\REST\AssessmentController();
    $assessmentController->registerRoutes();

    $skillsController = new \ABA\Core\REST\SkillsController();
    $skillsController->registerRoutes();

    $parentController = new \ABA\Core\REST\ParentController();
    $parentController->registerRoutes();

    $learningController = new \ABA\Core\REST\LearningController();
    $learningController->registerRoutes();
});
