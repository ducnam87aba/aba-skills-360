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

// Authorization layer exposes namespaced helper functions (not just a class),
// so it is required explicitly rather than relying on class-based autoload.
require_once ABA_SKILLS_CORE_PATH . 'includes/Auth/Authorization.php';

/**
 * PA37 — local headless auth over plain HTTP. WordPress core refuses
 * Application Passwords when !is_ssl() and the environment is not "local"
 * (wp_is_application_passwords_available). The docker stack serves HTTP on
 * :8080, so the Next.js front end could only authenticate via the cookie
 * session — which WP scopes to /wp-admin/ and therefore never reaches
 * /wp-json/. Two opt-ins unlock app passwords here, both deliberately
 * explicit: the "local" environment type, and the aba_headless_auth flag the
 * DemoUserSeeder writes (an un-seeded production site stays on WP's own
 * decision). The Authorization-header restore covers Apache builds that strip
 * it, and the login flow is Basic — the scheme WP 6.5+ retains for app
 * passwords (Bearer was dropped from rest_cookie_auth_navigation, wp-includes).
 */
if (function_exists('add_filter')) {
    $aba_headless_allowed = static function () {
        if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local') {
            return true;
        }
        return get_option('aba_headless_auth') === '1';
    };
    add_filter('wp_is_application_passwords_available', function ($available) use ($aba_headless_allowed) {
        return $aba_headless_allowed() ? true : $available;
    });
    add_filter('wp_is_application_passwords_available_for_user', function ($available) use ($aba_headless_allowed) {
        return $aba_headless_allowed() ? true : $available;
    });
    add_action('init', function () {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, 'Authorization') === 0) {
                $_SERVER['HTTP_AUTHORIZATION'] = (string) $value;
                $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = (string) $value;
            }
        }
        if (!isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/Basic\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
                $decoded = base64_decode($m[1], true);
                if ($decoded !== false && strpos($decoded, ':') !== false) {
                    list($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) = explode(':', $decoded, 2);
                }
            }
        }
    }, 0);
    /*
     * PA38/PA39 — Basic app-password must win over any stale login cookie.
     * Core's chain runs cookie auth first and rest_cookie_check_errors() then
     * wipes the user when no X-WP-Nonce rides along, so a browser that replays a
     * wordpress_logged_in cookie 401s every request even with valid Basic
     * credentials (curl/PowerShell carry no cookie — which is why they never saw
     * it). Verifying Basic at priority 80 — before the 100 cookie check — short-
     * circuits the wipe: the 100 filter returns early whenever a non-cookie auth
     * already logged the user in. PA39 also stopped /auth/login from setting the
     * cookie in the Basic flow, so this guard is now defence-in-depth against a
     * stray /wp-admin session on the same host rather than the primary fix.
     * Gated on the same explicit headless opt-ins as the availability filters.
     */
    add_filter('rest_authentication_errors', function ($result) use ($aba_headless_allowed) {
        if ($result !== null || !isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
            return $result;
        }
        if (!$aba_headless_allowed() || !class_exists('WP_Application_Passwords')) {
            return $result;
        }
        $authenticated = wp_authenticate_application_password(null, (string) $_SERVER['PHP_AUTH_USER'], (string) $_SERVER['PHP_AUTH_PW']);
        if ($authenticated instanceof WP_User) {
            wp_set_current_user($authenticated->ID);
            return true;
        }
        return $result;
    }, 80);
}

/**
 * Schema migrations (database-schema.md §41). Runs when WP core upgrade
 * helpers are available; the activation hook covers the install path.
 */
add_action('plugins_loaded', function () {
    if (function_exists('wp_get_environment_type')) {
        \ABA\Core\Database\MigrationRunner::run();
        \ABA\Core\Database\AssessmentVersionSeeder::seed();
        \ABA\Core\Database\ContentSeeder::seed();
        \ABA\Core\Database\SchoolSeeder::seed();
        \ABA\Core\Database\DemoUserSeeder::seed();
    }
}, 1);

/**
 * Plugin Activation
 */
register_activation_hook(__FILE__, function () {
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    \ABA\Core\Database\MigrationRunner::run();
    \ABA\Core\Database\AssessmentVersionSeeder::seed();
    \ABA\Core\Database\ContentSeeder::seed();
    \ABA\Core\Database\SchoolSeeder::seed();
    \ABA\Core\Database\DemoUserSeeder::seed();
    \ABA\Core\Auth\aba_grant_role_capabilities();
    flush_rewrite_rules();
});

/**
 * Plugin Deactivation
 */
register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

/**
 * PA43 — administration-only screens (AGENTS.md §2 permits WP admin UI, never
 * the student/parent application). Registers the hooks; capability checks inside
 * gate what any given user can open. Kept out of the REST/front-end surface.
 */
add_action('init', function () {
    \ABA\Core\Admin\LinkRequestsAdminPage::init();
});

/**
 * Initialize Plugin & REST APIs
 */
add_action('init', function () {
    // Ensure ABA capabilities exist even if roles changed after activation.
    \ABA\Core\Auth\aba_grant_role_capabilities();

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

    register_post_type('aba_module', [
        'labels' => [
            'name' => __('Modules', 'aba-skills-core'),
            'singular_name' => __('Module', 'aba-skills-core'),
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

    // Standardize every aba/v1 error into the contract shape (api-contract §6).
    \ABA\Core\REST\ErrorNormalizer::register();

    $userToDto = function (\WP_User $user, ?array $student): array {
        $roles = $user->roles ?: [];
        return [
            'id' => (int)$user->ID,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'displayName' => $user->display_name,
            'roles' => $roles,
            'studentId' => $student ? (int)$student['id'] : null,
            'schoolId' => $student ? (int)($student['school_id'] ?? 0) : null,
            'parentId' => in_array('aba_parent', $roles, true) || get_user_meta($user->ID, 'aba_parent', true) ? (int)$user->ID : null,
        ];
    };

    // Current user me endpoint (server-derived identity)
    register_rest_route($namespace, '/me', [
        'methods' => 'GET',
        'callback' => function () use ($userToDto) {
            $user = wp_get_current_user();
            if (!$user || $user->ID === 0) {
                return \ABA\Core\Auth\aba_error('ABA_UNAUTHENTICATED', 'Authentication required.', 401);
            }
            $studentRepo = new \ABA\Core\Repositories\StudentRepository();
            $student = $studentRepo->findByUserId($user->ID);
            return rest_ensure_response($userToDto($user, $student));
        },
        'permission_callback' => 'is_user_logged_in',
    ]);

    // Student me endpoint (resolved from the authenticated user, never hardcoded)
    register_rest_route($namespace, '/students/me', [
        'methods' => 'GET',
        'callback' => function () {
            $user = wp_get_current_user();
            $studentRepo = new \ABA\Core\Repositories\StudentRepository();

            $student = $user && $user->ID ? $studentRepo->findByUserId($user->ID) : null;
            if (!$student) {
                return \ABA\Core\Auth\aba_error('ABA_STUDENT_NOT_FOUND', 'No student profile linked to this account.', 404);
            }
            return rest_ensure_response($studentRepo->toApiShape($student));
        },
        'permission_callback' => 'is_user_logged_in',
    ]);

    // Controllers
    $authController = new \ABA\Core\REST\AuthController();
    $authController->registerRoutes();

    $assessmentController = new \ABA\Core\REST\AssessmentController();
    $assessmentController->registerRoutes();

    $skillsController = new \ABA\Core\REST\SkillsController();
    $skillsController->registerRoutes();

    $parentController = new \ABA\Core\REST\ParentController();
    $parentController->registerRoutes();

    $learningController = new \ABA\Core\REST\LearningController();
    $learningController->registerRoutes();

    $recommendationController = new \ABA\Core\REST\RecommendationController();
    $recommendationController->registerRoutes();

    $gamificationController = new \ABA\Core\REST\GamificationController();
    $gamificationController->registerRoutes();

    $schoolController = new \ABA\Core\REST\SchoolController();
    $schoolController->registerRoutes();
});
