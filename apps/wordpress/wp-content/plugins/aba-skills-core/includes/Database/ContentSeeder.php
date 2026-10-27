<?php
/**
 * Seeds the Content Library (PA17, multi-band since PA17b).
 *
 * Content is editorial, so it lives in CPTs (AGENTS.md §6): one course, six
 * competency modules, and eighteen lessons per grade band (three per competency:
 * a lesson, a quiz, a boss challenge) matching the node types the Learning Path
 * emits. The path binds its deterministic nodes to these lessons by
 * (competency, type, band) — PA17b fills the 7_9 and 10_12 bands so the
 * Recommendation Engine's per-band delivery styles (§13) resolve to real,
 * age-appropriate bodies instead of falling back to the 4_6 starter.
 *
 * Idempotent by deterministic slug — re-running never duplicates, and it works
 * whether or not the option guard was reset (mirrors AssessmentVersionSeeder's
 * "DB is the source of truth" stance). The option guard only skips the sweep;
 * bumping VERSION makes the new bands seed on the next load.
 *
 * @package ABA_Skills_Core
 */

namespace ABA\Core\Database;

use ABA\Core\Repositories\ContentRepository;

if (!defined('ABSPATH')) {
    exit;
}

class ContentSeeder {
    public const VERSION = 'band-v2';
    public const GRADE_GROUP = '4_6';

    /** Bands the library now covers; the keys also drive the module/course slugs. */
    private const BANDS = ['4_6', '7_9', '10_12'];

    /** competency code => [Vietnamese name, [slug => [type, title, body]]] */
    private const CURRICULUM = [
        'SA' => ['Tự nhận thức', [
            'sa-lesson' => ['lesson', 'Nhận biết cảm xúc của mình', 'Em học gọi tên cảm xúc đang có và hiểu rằng mỗi cảm xúc đều có lý do. Nhận biết đúng là bước đầu để tự điều chỉnh.'],
            'sa-quiz' => ['quiz', 'Kiểm tra: Điểm mạnh của em', 'Em liệt kê ba việc mình làm tốt và một việc muốn cải thiện. Tự đánh giá trung thực giúp em đặt mục tiêu phù hợp.'],
            'sa-boss' => ['boss_challenge', 'Thử thách: Nhật ký cảm xúc', 'Trong một tuần, mỗi ngày em ghi lại một cảm xúc nổi bật và điều đã gây ra nó. Cuối tuần em tìm ra mẫu cảm xúc của mình.'],
        ]],
        'ER' => ['Quản lý cảm xúc', [
            'er-lesson' => ['lesson', 'Bình tĩnh khi tức giận', 'Khi khó chịu, em dừng lại, hít thở sâu ba lần và đếm đến mười trước khi phản ứng. Khoảng lặng nhỏ giúp em chọn cách nói phù hợp.'],
            'er-quiz' => ['quiz', 'Kiểm tra: Chiến lược tự kiềm chế', 'Em sắp xếp các tình huống theo mức độ gây khó chịu và chọn chiến lược bình tĩnh phù hợp cho từng tình huống.'],
            'er-boss' => ['boss_challenge', 'Thử thách: Vượt qua thất bại', 'Khi một việc không như ý, em mô tả cảm xúc, nêu nguyên nhân và đề xuất một bước thử lại. Không bỏ cuộc là một kỹ năng.'],
        ]],
        'SM' => ['Tự quản lý', [
            'sm-lesson' => ['lesson', 'Bắt đầu và hoàn thành nhiệm vụ', 'Em chia việc lớn thành các bước nhỏ, làm bước đầu tiên ngay, và tự kiểm tra trước khi nộp. Hoàn thành đúng hẹn tạo nên sự tin tưởng.'],
            'sm-quiz' => ['quiz', 'Kiểm tra: Quản lý thời gian', 'Em lập thời gian biểu cho một buổi học và sắp xếp thứ tự ưu tiên theo mức độ quan trọng.'],
            'sm-boss' => ['boss_challenge', 'Thử thách: Kế hoạch một tuần', 'Em tự đặt mục tiêu cho tuần này, theo dõi tiến độ mỗi ngày và tổng kết xem điều gì đã hiệu quả.'],
        ]],
        'CO' => ['Giao tiếp', [
            'co-lesson' => ['lesson', 'Lắng nghe chủ động', 'Em nhìn người nói, không ngắt lời, và nhắc lại ý của bạn trước khi nêu ý mình. Lắng nghe khiến bạn cảm được tôn trọng.'],
            'co-quiz' => ['quiz', 'Kiểm tra: Diễn đạt nhu cầu', 'Em tập nói rõ mình muốn gì bằng câu "Em cảm thấy... vì... Em mong..." mà không buộc lỗi người khác.'],
            'co-boss' => ['boss_challenge', 'Thử thách: Hóa giải hiểu lầm', 'Khi bị hiểu nhầm, em giữ bình tĩnh, hỏi lại để hiểu góc nhìn của bạn, rồi giải thích lại ý mình một cách tôn trọng.'],
        ]],
        'CE' => ['Hợp tác và đồng cảm', [
            'ce-lesson' => ['lesson', 'Phối hợp trong nhóm', 'Em lắng nghe ý kiến của các bạn, nhận một vai phù hợp và hỗ trợ bạn khi gặp khó. Nhóm mạnh khi mỗi người cùng góp sức.'],
            'ce-quiz' => ['quiz', 'Kiểm tra: Thấu hiểu bạn bè', 'Em đoán cảm xúc của bạn trong các tình huống và đề xuất cách an ủi, hỗ trợ phù hợp.'],
            'ce-boss' => ['boss_challenge', 'Thử thách: Cùng nhau hoàn thành', 'Cùng nhóm giải một nhiệm vụ, em đảm bảo mọi bạn đều được nghe và phần việc được chia công bằng.'],
        ]],
        'PD' => ['Giải quyết vấn đề và ra quyết định', [
            'pd-lesson' => ['lesson', 'Nhận diện vấn đề', 'Em mô tả vấn đề bằng một câu rõ ràng, tách khỏi cảm xúc, rồi xác định điều mình thực sự muốn đạt.'],
            'pd-quiz' => ['quiz', 'Kiểm tra: Nhiều giải pháp', 'Với một tình huống, em nghĩ ra ít nhất ba cách giải quyết và liệt kê ưu - nhược của từng cách.'],
            'pd-boss' => ['boss_challenge', 'Thử thách: Cân nhắc hệ quả', 'Trước khi quyết định, em dự đoán hệ quả cho mình và cho người khác, rồi chọn cách công bằng và an toàn nhất.'],
        ]],
    ];

    /**
     * Middle band (grades 7-9): delivery through discussion and group projects —
     * mirrors RecommendationService::BAND_STYLE['7_9'] (§13).
     */
    private const CURRICULUM_7_9 = [
        'SA' => ['Tự nhận thức', [
            'sa-79-lesson' => ['lesson', 'Điểm mạnh và giới hạn của em', 'Em lập bảng hai cột: việc mình làm tốt và việc còn khó khăn. Nhận diện trung thực giúp em đặt mục tiêu vừa sức mà không so sánh mình với bạn.'],
            'sa-79-quiz' => ['quiz', 'Kiểm tra: Hồ sơ năng lực cá nhân', 'Em tự đánh giá sáu năng lực cốt lõi theo thang 1-5 và giải thích một đánh giá bằng ví dụ cụ thể.'],
            'sa-79-boss' => ['boss_challenge', 'Thử thách: Phỏng vấn bạn bè', 'Em phỏng vấn một bạn về điểm mạnh của em, rồi so sánh với tự đánh giá của mình. Góc nhìn người khác giúp em hiểu mình đầy đủ hơn.'],
        ]],
        'ER' => ['Quản lý cảm xúc', [
            'er-79-lesson' => ['lesson', 'Áp lực học đường', 'Khi bị điểm kém hay so sánh, em gọi tên cảm xúc, tách sự việc khỏi giá trị bản thân và chọn một bước tiến tiếp. Cảm xúc là tín hiệu, không phải lệnh.'],
            'er-79-quiz' => ['quiz', 'Kiểm tra: Đọc tín hiệu cơ thể', 'Em nối các dấu hiệu cơ thể (tim nhanh, vai căng) với cảm xúc tương ứng và chiến lược hạ nhiệt phù hợp.'],
            'er-79-boss' => ['boss_challenge', 'Thử thách: Nhật ký cảm xúc hai tuần', 'Trong hai tuần, em ghi lại tình huống gây căng nhất và cách em phản ứng. Cuối kỳ em tìm ra yếu tố kích hoạt và điều thực sự giúp mình bình tĩnh.'],
        ]],
        'SM' => ['Tự quản lý', [
            'sm-79-lesson' => ['lesson', 'Đặt mục tiêu SMART', 'Em tập chuyển "muốn học giỏi" thành mục tiêu cụ thể, đo được, vừa sức và có hạn deadline. Mục tiêu rõ thì kế hoạch rõ.'],
            'sm-79-quiz' => ['quiz', 'Kiểm tra: Ưu tiên việc cần làm', 'Em xếp loại các việc của một tuần theo ma trận khẩn - quan trọng và giải thích lựa chọn của mình.'],
            'sm-79-boss' => ['boss_challenge', 'Thử thách: Dự án cá nhân 30 ngày', 'Em chọn một mục tiêu, tự lập kế hoạch theo tuần, tự theo dõi tiến độ và báo cáo kết quả. Thất bại giữa đường được phép sửa kế hoạch.'],
        ]],
        'CO' => ['Giao tiếp', [
            'co-79-lesson' => ['lesson', 'Nói trước đám đông', 'Em tập trình bày 3 phút theo cấu trúc mở - thân - kết, quan sát phản ứng người nghe và điều chỉnh giọng nói. Nói rõ là kỹ năng luyện được.'],
            'co-79-quiz' => ['quiz', 'Kiểm tra: Phản hồi xây dựng', 'Em thực hành góp ý cho bạn theo cấu trúc "ghi nhận - đề xuất - hỏi lại", không phê phán cá nhân.'],
            'co-79-boss' => ['boss_challenge', 'Thử thách: Tranh luận văn minh', 'Em tranh luận một chủ đề lớp học theo nhóm: nêu bằng chứng, lắng nghe phe ngược và bảo vệ ý mình bằng lý lẽ, không bằng to tiếng.'],
        ]],
        'CE' => ['Hợp tác và đồng cảm', [
            'ce-79-lesson' => ['lesson', 'Làm nhóm hiệu quả', 'Em học cách chia vai trong nhóm, cam kết phần việc và kiểm tra chéo. Nhóm mạnh khi mỗi người chịu trách nhiệm phần mình.'],
            'ce-79-quiz' => ['quiz', 'Kiểm tra: Thấu cảm trong xung đột', 'Em đóng vai tình huống nhóm mâu thuẫn và đề xuất cách giải quyết để các bên đều cảm được tôn trọng.'],
            'ce-79-boss' => ['boss_challenge', 'Thử thách: Dự án nhóm vì trường lớp', 'Cùng nhóm thực hiện một dự án nhỏ cải thiện lớp học, em đảm bảo ý kiến mỗi bạn đều được ghi nhận khi ra quyết định.'],
        ]],
        'PD' => ['Giải quyết vấn đề và ra quyết định', [
            'pd-79-lesson' => ['lesson', 'Phân tích nguyên nhân', 'Trước một vấn đề, em tách hiện tượng khỏi nguyên nhân gốc bằng cách hỏi "tại sao" năm lần. Trị gốc bền hơn trị ngọn.'],
            'pd-79-quiz' => ['quiz', 'Kiểm tra: So sánh lựa chọn', 'Với một quyết định của lớp, em liệt kê các phương án, chấm điểm ưu - nhược và bảo vệ phương án chọn.'],
            'pd-79-boss' => ['boss_challenge', 'Thử thách: Giải một vấn đề của trường', 'Em chọn một vấn đề thực tế của trường, phân tích, đề xuất giải pháp và trình bày trước lớp để lấy phản hồi.'],
        ]],
    ];

    /**
     * Top band (grades 10-12): delivery through real-world projects — mirrors
     * RecommendationService::BAND_STYLE['10_12'] (§13).
     */
    private const CURRICULUM_10_12 = [
        'SA' => ['Tự nhận thức', [
            'sa-1012-lesson' => ['lesson', 'Giá trị cá nhân và định hướng', 'Em xếp hạng các giá trị nghề nghiệp và cuộc sống, đối chiếu với lựa chọn hiện tại. Hiểu giá trị của mình giúp chọn đường ít hối tiếc.'],
            'sa-1012-quiz' => ['quiz', 'Kiểm tra: Tự đánh giá bằng bằng chứng', 'Em chọn ba năng lực và bảo vệ mức độ của bản thân bằng ví dụ thực, không bằng cảm tính.'],
            'sa-1012-boss' => ['boss_challenge', 'Thử thách: Hồ sơ năng lực thật', 'Em soạn một hồ sơ giới thiệu bản thân (điểm mạnh, dự án đã làm, mục tiêu) như gửi cho một cố vấn hoặc nhà tuyển dụng.'],
        ]],
        'ER' => ['Quản lý cảm xúc', [
            'er-1012-lesson' => ['lesson', 'Quản trị căng thẳng dài hạn', 'Em phân biệt áp lực lành mạnh và mãn tính, thiết kế nhịp học - nghỉ - vận động bền vững thay vì chống kiệt sức bằng ý chí.'],
            'er-1012-quiz' => ['quiz', 'Kiểm tra: Kế hoạch vượt khủng hoảng', 'Em lập kịch bản xử lý một tuần quá tải: việc nào cắt, việc nào uỷ, việc nào giữ, và nói rõ giới hạn của mình.'],
            'er-1012-boss' => ['boss_challenge', 'Thử thách: 30 ngày tự điều hành', 'Em theo dõi mức năng lượng và cảm xúc suốt 30 ngày, phân tích dữ liệu của mình và điều chỉnh một thói quen rõ rệt.'],
        ]],
        'SM' => ['Tự quản lý', [
            'sm-1012-lesson' => ['lesson', 'Điều phối dự án dài hạn', 'Em học chia dự án nhiều tuần thành mốc kiểm tra, tự cảnh báo trễ hẹn và điều chỉnh tiến độ trước khi hỏng việc.'],
            'sm-1012-quiz' => ['quiz', 'Kiểm tra: Ngân sách thời gian', 'Em theo dõi 3 ngày sử dụng thời gian thực tế, đối chiếu với kế hoạch tự cho và tìm ra chỗ rò rỉ.'],
            'sm-1012-boss' => ['boss_challenge', 'Thử thách: Kế hoạch 6 tháng cá nhân', 'Em lập kế hoạch 6 tháng cho một mục tiêu thật của bản thân với các mốc, bằng chứng hoàn thành và phương án dự phòng.'],
        ]],
        'CO' => ['Giao tiếp', [
            'co-1012-lesson' => ['lesson', 'Thuyết trình có cấu trúc', 'Em xây bài thuyết trình theo tuyến vấn đề - bằng chứng - kiến nghị, tập nói trước người khác và nhận phản hồi để sửa.'],
            'co-1012-quiz' => ['quiz', 'Kiểm tra: Giao tiếp khác biệt', 'Em thực hành bất đồng với người trên (giáo viên, phụ huynh): giữ lập trường, tôn trọng và tìm tiếng nói chung.'],
            'co-1012-boss' => ['boss_challenge', 'Thử thách: Bảo vệ quan điểm trước hội đồng', 'Em trình bày một quan điểm trước nhóm phản biện, trả lời câu hỏi bằng lập luận và dữ liệu, không bằng cảm xúc.'],
        ]],
        'CE' => ['Hợp tác và đồng cảm', [
            'ce-1012-lesson' => ['lesson', 'Dung hòa xung đột lợi ích', 'Em học tách con người khỏi vấn đề, tìm lợi ích chung ẩn sau lập trường đối đầu để mở khoá bế tắc trong nhóm.'],
            'ce-1012-quiz' => ['quiz', 'Kiểm tra: Vai trò trong đội', 'Em đánh giá cách mình đóng vai trò lãnh đạo hay người hỗ trợ trong nhóm tuỳ tình huống và đề xuất điều chỉnh.'],
            'ce-1012-boss' => ['boss_challenge', 'Thử thách: Dự án cộng đồng', 'Cùng nhóm triển khai một hoạt động phục vụ cộng đồng, em điều phối phần việc và bảo đảm mọi thành viên được lắng nghe.'],
        ]],
        'PD' => ['Giải quyết vấn đề và ra quyết định', [
            'pd-1012-lesson' => ['lesson', 'Ra quyết định khi thiếu dữ liệu', 'Em học khung quyết định trong điều kiện không chắc chắn: xác định rủi ro chấp nhận được, hỏi chuyên gia, và lên phương án lùi bước.'],
            'pd-1012-quiz' => ['quiz', 'Kiểm tra: Phân tích hệ quả dài hạn', 'Với một lựa chọn lớn (chọn ngành, công việc thêm), em sơ đồ hoá hệ quả 1 - 3 - 5 năm cho mình và người liên quan.'],
            'pd-1012-boss' => ['boss_challenge', 'Thử thách: Tư vấn cho bạn cùng lớp', 'Em đóng vai người tư vấn cho một vấn đề thật của bạn, phân tích khách quan và giúp bạn tự ra quyết định, không làm hộ.'],
        ]],
    ];

    /** band => curriculum table. */
    private const BAND_CURRICULA = [
        '4_6' => self::CURRICULUM,
        '7_9' => self::CURRICULUM_7_9,
        '10_12' => self::CURRICULUM_10_12,
    ];

    public static function seed(): void {
        if (get_option('aba_content_seeded') === self::VERSION) {
            return;
        }

        $courseId = self::ensurePost(ContentRepository::COURSE_CPT, 'aba-starter-course', 'Nền tảng Kỹ năng 360°', 'Thư viện bài học khởi đầu cho các năng lực cốt lõi.');

        foreach (self::BANDS as $band) {
            $suffix = str_replace('_', '', $band);
            $curriculum = self::BAND_CURRICULA[$band];

            foreach ($curriculum as $code => [$name, $lessons]) {
                $moduleSlug = $band === self::GRADE_GROUP
                    ? 'aba-module-' . strtolower($code)
                    : 'aba-module-' . strtolower($code) . '-' . $suffix;
                $moduleId = self::ensurePost(ContentRepository::MODULE_CPT, $moduleSlug, 'Chương: ' . $name . ' (' . $band . ')', 'Đơn vị năng lực ' . $code . ', nhóm lớp ' . $band . '.');
                update_post_meta($moduleId, ContentRepository::META_COMPETENCY, $code);

                $order = 0;
                foreach ($lessons as $slug => [$type, $title, $body]) {
                    $order++;
                    $xp = $type === 'lesson' ? 30 : ($type === 'quiz' ? 60 : 100);
                    self::ensureLesson($slug, $courseId, $moduleId, $code, $band, $type, $title, $body, $order, $xp);
                }
            }
        }

        update_option('aba_content_seeded', self::VERSION, false);
    }

    /** Create a course/module post if its slug is free; always return its id. */
    private static function ensurePost(string $postType, string $slug, string $title, string $excerpt): int {
        $existing = get_page_by_path($slug, 'OBJECT', $postType);
        if ($existing instanceof \WP_Post) {
            return (int) $existing->ID;
        }
        return (int) wp_insert_post([
            'post_type' => $postType,
            'post_status' => 'publish',
            'post_title' => $title,
            'post_name' => $slug,
            'post_excerpt' => $excerpt,
        ]);
    }

    private static function ensureLesson(string $slug, int $courseId, int $moduleId, string $code, string $band, string $type, string $title, string $body, int $order, int $xp): void {
        if (get_page_by_path($slug, 'OBJECT', ContentRepository::LESSON_CPT) instanceof \WP_Post) {
            return;
        }

        $postId = (int) wp_insert_post([
            'post_type' => ContentRepository::LESSON_CPT,
            'post_status' => 'publish',
            'post_title' => $title,
            'post_name' => $slug,
            'post_excerpt' => $title,
            'post_content' => $body,
            'meta_input' => [
                ContentRepository::META_COMPETENCY => $code,
                ContentRepository::META_GRADE_GROUP => $band,
                ContentRepository::META_LESSON_TYPE => $type,
                ContentRepository::META_ORDER => $order,
                ContentRepository::META_XP => $xp,
                ContentRepository::META_MODULE => $moduleId,
                'aba_course_id' => $courseId,
                'aba_content_version' => self::VERSION,
            ],
        ]);
    }
}
