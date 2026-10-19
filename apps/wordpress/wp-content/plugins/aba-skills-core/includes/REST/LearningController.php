<?php

namespace ABA\Core\REST;

use ABA\Core\Repositories\LessonProgressRepository;
use ABA\Core\Repositories\XpTransactionRepository;
use WP_REST_Request;
use WP_REST_Response;

class LearningController {
    private LessonProgressRepository $progressRepo;
    private XpTransactionRepository $xpRepo;

    public function __construct() {
        $this->progressRepo = new LessonProgressRepository();
        $this->xpRepo = new XpTransactionRepository();
    }

    public function registerRoutes(): void {
        $namespace = 'aba/v1';

        // GET /aba/v1/learning/path
        register_rest_route($namespace, '/learning/path', [
            'methods' => 'GET',
            'callback' => [$this, 'getLearningPath'],
            'permission_callback' => '__return_true',
        ]);

        // POST /aba/v1/lessons/(?P<id>[a-zA-Z0-9_\-]+)/complete
        register_rest_route($namespace, '/lessons/(?P<id>[a-zA-Z0-9_\-]+)/complete', [
            'methods' => 'POST',
            'callback' => [$this, 'completeLessonNode'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function getLearningPath(WP_REST_Request $request): WP_REST_Response {
        $studentId = (int)($request->get_param('studentId') ?? 1);
        $completedNodes = $this->progressRepo->getCompletedNodeIds($studentId);

        $path = [
            'studentId' => $studentId,
            'currentUnitId' => 'unit-1',
            'currentNodeId' => 'node-2',
            'units' => [
                [
                    'id' => 'unit-1',
                    'title' => 'Chương 1: Nền tảng Giao tiếp Chủ động',
                    'description' => 'Làm quen với việc lắng nghe tích cực và phản hồi hòa nhã',
                    'competencyTarget' => 'CO',
                    'order' => 1,
                    'status' => 'active',
                    'nodes' => [
                        [
                            'id' => 'node-1',
                            'title' => 'Nhận biết tín hiệu cảm xúc bạn bè',
                            'description' => 'Học cách quan sát nét mặt và cử chỉ',
                            'type' => 'lesson',
                            'status' => 'completed',
                            'score' => 100,
                            'xpReward' => 30,
                            'order' => 1,
                        ],
                        [
                            'id' => 'node-2',
                            'title' => 'Thực hành Lắng nghe không phán xét',
                            'description' => 'Bài tập nhập vai tình huống làm việc nhóm',
                            'type' => 'lesson',
                            'status' => in_array('node-2', $completedNodes) ? 'completed' : 'unlocked',
                            'xpReward' => 40,
                            'order' => 2,
                        ],
                        [
                            'id' => 'node-3',
                            'title' => 'Thử thách: Hóa giải hiểu lầm nhỏ',
                            'description' => 'Trắc nghiệm lựa chọn cách diễn đạt phù hợp',
                            'type' => 'quiz',
                            'status' => in_array('node-2', $completedNodes) ? 'unlocked' : 'locked',
                            'xpReward' => 60,
                            'order' => 3,
                        ],
                        [
                            'id' => 'node-4',
                            'title' => 'Boss Challenge: Buổi thuyết trình tự tin',
                            'description' => 'Vượt qua thử thách chương để mở khóa',
                            'type' => 'boss_challenge',
                            'status' => 'locked',
                            'xpReward' => 100,
                            'order' => 4,
                        ],
                    ],
                ],
            ],
        ];

        return rest_ensure_response($path);
    }

    public function completeLessonNode(WP_REST_Request $request): WP_REST_Response {
        $nodeId = (string)$request->get_param('id');
        $params = $request->get_json_params() ?: [];
        $studentId = (int)($params['studentId'] ?? 1);
        $xpReward = (int)($params['xpReward'] ?? 40);

        // Record progress in custom table
        $this->progressRepo->recordProgress($studentId, $nodeId, 'completed', 100.0, $xpReward);

        // Record XP transaction
        $this->xpRepo->recordTransaction(
            $studentId,
            $xpReward,
            'lesson',
            $nodeId,
            "Hoàn thành bài học {$nodeId}"
        );

        return rest_ensure_response([
            'success' => true,
            'nodeId' => $nodeId,
            'xpAwarded' => $xpReward,
            'status' => 'completed',
        ]);
    }
}
