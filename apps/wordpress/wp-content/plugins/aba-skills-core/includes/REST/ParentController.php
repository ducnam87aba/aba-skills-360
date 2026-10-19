<?php

namespace ABA\Core\REST;

use ABA\Core\Repositories\StudentRepository;
use ABA\Core\Services\SkillsProfileService;
use ABA\Core\Services\RecommendationService;
use WP_REST_Request;
use WP_REST_Response;

class ParentController {
    private StudentRepository $studentRepo;
    private SkillsProfileService $skillsService;
    private RecommendationService $recService;

    public function __construct() {
        $this->studentRepo = new StudentRepository();
        $this->skillsService = new SkillsProfileService();
        $this->recService = new RecommendationService();
    }

    public function registerRoutes(): void {
        $namespace = 'aba/v1';

        // GET /aba/v1/parent/children
        register_rest_route($namespace, '/parent/children', [
            'methods' => 'GET',
            'callback' => [$this, 'getChildren'],
            'permission_callback' => '__return_true',
        ]);

        // GET /aba/v1/students/{id}/reports
        register_rest_route($namespace, '/students/(?P<id>\d+)/reports', [
            'methods' => 'GET',
            'callback' => [$this, 'getStudentReports'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function getChildren(): WP_REST_Response {
        $children = [
            [
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
            ],
        ];

        return rest_ensure_response($children);
    }

    public function getStudentReports(WP_REST_Request $request): WP_REST_Response {
        $studentId = (int)$request->get_param('id');
        $scores = $this->skillsService->getCurrentScores($studentId);
        $recs = $this->recService->getRecommendations($scores, '4_6');

        $reports = [
            [
                'id' => 101,
                'studentId' => $studentId,
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'title' => 'Báo cáo Định kỳ Năng lực Kỹ năng Tháng 10/2026',
                'summary' => 'Học sinh Dương Đức Nam thể hiện sự tự tin và tiến bộ rõ nét trong nhóm năng lực Tự nhận thức và Giải quyết vấn đề. Năng lực Giao tiếp và Quản lý cảm xúc có thể đẩy mạnh thêm thông qua bài tập gia đình.',
                'overallScore' => 81,
                'strengths' => [
                    'Khả năng tập trung và nhận diện vấn đề logic mạch lạc',
                    'Chủ động tương tác và tuân thủ nguyên tắc khi học tập',
                ],
                'areasForGrowth' => [
                    'Cần thêm bài tập thực hành kiềm chế cảm xúc khi gặp áp lực thời gian',
                ],
                'recommendations' => $recs,
            ],
        ];

        return rest_ensure_response($reports);
    }
}
