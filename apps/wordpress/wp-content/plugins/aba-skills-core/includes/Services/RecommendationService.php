<?php

namespace ABA\Core\Services;

class RecommendationService {
    /**
     * Deterministic recommendation engine based on competency scores and grade group
     */
    public function getRecommendations(array $competencyScores, string $gradeGroup = '4_6'): array {
        $recommendations = [];

        // Identify lowest competency
        $lowestCode = null;
        $lowestScore = 101.0;

        foreach ($competencyScores as $item) {
            $score = (float)($item['score'] ?? 0);
            if ($score < $lowestScore) {
                $lowestScore = $score;
                $lowestCode = $item['skillCode'];
            }
        }

        // Rule-based recommendations
        switch ($lowestCode) {
            case 'CO': // Giao tiếp
                $recommendations[] = [
                    'id' => 'rec-co-1',
                    'title' => 'Chương 1: Nền tảng Giao tiếp Chủ động',
                    'description' => 'Bài học thực hành lắng nghe không ngắt lời và phản hồi tích cực.',
                    'type' => 'lesson',
                    'targetCompetency' => 'CO',
                ];
                $recommendations[] = [
                    'id' => 'rec-co-2',
                    'title' => 'Thực hành bữa ăn gia đình',
                    'description' => 'Khuyến nghị phụ huynh: Dành 15 phút trò chuyện cởi mở cùng con mỗi tối.',
                    'type' => 'home_exercise',
                    'targetCompetency' => 'CO',
                ];
                break;

            case 'ER': // Quản lý cảm xúc
                $recommendations[] = [
                    'id' => 'rec-er-1',
                    'title' => 'Bài tập Thở sâu 3 phút khi căng thẳng',
                    'description' => 'Phương pháp điều hòa nhịp tim và giữ bình tĩnh trước giờ làm bài.',
                    'type' => 'home_exercise',
                    'targetCompetency' => 'ER',
                ];
                $recommendations[] = [
                    'id' => 'rec-er-2',
                    'title' => 'Khóa học: Làm quen với Cơn giận và Lo lắng',
                    'description' => 'Nhận biết tín hiệu sinh học khi cơ thể bắt đầu cáu kỉnh.',
                    'type' => 'lesson',
                    'targetCompetency' => 'ER',
                ];
                break;

            case 'PD': // Giải quyết vấn đề
                $recommendations[] = [
                    'id' => 'rec-pd-1',
                    'title' => 'Chương 2: Tư duy Phân tích Nút thắt Vấn đề',
                    'description' => 'Kỹ thuật chia nhỏ vấn đề lớn thành các phần dễ xử lý.',
                    'type' => 'lesson',
                    'targetCompetency' => 'PD',
                ];
                break;

            default:
                $recommendations[] = [
                    'id' => 'rec-gen-1',
                    'title' => 'Rèn luyện Thói quen Học tập Bền bỉ',
                    'description' => 'Duy trì chuỗi học tập 7 ngày liên tiếp để nâng cao tính tự lập.',
                    'type' => 'activity',
                    'targetCompetency' => $lowestCode ?: 'SM',
                ];
                break;
        }

        return $recommendations;
    }
}
