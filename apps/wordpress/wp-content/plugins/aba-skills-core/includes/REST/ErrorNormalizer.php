<?php
/**
 * Normalizes every aba/v1 error response into the standard contract shape
 * (api-contract.md §6):
 *
 *   { "error": { "code": "...", "message": "...", "details": {} } }
 *
 * WordPress core turns a WP_Error into { code, message, data: { status } }, so
 * we rewrite the body after dispatch while preserving the HTTP status. This lets
 * the Next.js client branch on error.code instead of parsing messages (§143).
 *
 * Scoped to the aba/v1 namespace so core routes keep their native error format.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\REST;

use WP_REST_Response;
use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

class ErrorNormalizer {
    public static function register(): void {
        add_filter('rest_post_dispatch', [self::class, 'filter'], 10, 3);
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    public static function filter($result, $server, WP_REST_Request $request = null) {
        if (!($result instanceof WP_REST_Response)) {
            return $result;
        }
        if ($result->get_status() < 400) {
            return $result;
        }
        if (strpos($request ? $request->get_route() : '', '/aba/v1') !== 0) {
            return $result;
        }

        $data = $result->get_data();
        // Already normalized, or not a standard WP error envelope.
        if (!is_array($data) || isset($data['error']) || !isset($data['code'])) {
            return $result;
        }

        $inner = $data['data'] ?? [];
        $details = is_array($inner) && array_key_exists('details', $inner)
            ? $inner['details']
            : new \stdClass();

        // PA53 — a throttle refusal states its wait in the body (retryAfter) and
        // also in the standard header, so a proxy or a client that only reads
        // headers still backs off instead of hammering the door.
        if ((string) $data['code'] === 'ACCOUNT_LOCKED' && is_array($details) && !empty($details['retryAfter'])) {
            $result->header('Retry-After', (string) max(1, (int) $details['retryAfter']));
        }

        $result->set_data([
            'error' => [
                'code' => (string)$data['code'],
                'message' => is_string($data['message'] ?? null) ? $data['message'] : '',
                'details' => $details,
            ],
        ]);

        return $result;
    }
}
