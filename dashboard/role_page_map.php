<?php
// role_page_map.php
// Central role-to-page mapping for header/sidebar integration.

if (!function_exists('vh_normalize_role')) {
    function vh_normalize_role($role): string
    {
        $r = strtolower(trim((string) $role));
        $aliases = [
            'dean_academic' => 'dean_academics',
            'a.o' => 'ao',
            'administrative_officer' => 'ao',
            'administrative officer' => 'ao',
            'counselor' => 'counsellor',
            'class advisor' => 'class_advisor',
            'classadvisor' => 'class_advisor',
        ];
        return $aliases[$r] ?? $r;
    }
}

if (!function_exists('vh_role_page_map')) {
    function vh_role_page_map(): array
    {
        return [
            'public' => [
                ['label' => 'Dashboard', 'path' => '/dashboard/dashboard.php', 'icon' => 'fas fa-th-large'],
                ['label' => 'Forgot Password', 'path' => '/forgot_password.php', 'icon' => 'fas fa-key'],
            ],
            'student' => [
                ['label' => 'Dashboard', 'path' => '/dashboard/dashboard.php', 'icon' => 'fas fa-th-large'],
                ['label' => 'Attendance', 'path' => '/dashboard/attendance.php', 'icon' => 'fas fa-calendar-check'],
                ['label' => 'Topic Coverage', 'path' => '/dashboard/topic_coverage.php', 'icon' => 'fas fa-book-open'],
                ['label' => 'Bonafide', 'path' => '/bonafide/bonafide.php', 'icon' => 'fas fa-file-signature'],
                ['label' => 'Profile', 'path' => '/profile.php', 'icon' => 'fas fa-user'],
            ],
            'faculty' => [
                ['label' => 'Dashboard', 'path' => '/dashboard/dashboard.php', 'icon' => 'fas fa-th-large'],
                ['label' => 'Post Attendance', 'path' => '/dashboard/attendance.php', 'icon' => 'fas fa-edit'],
                ['label' => 'Topic Coverage', 'path' => '/dashboard/topic_coverage.php', 'icon' => 'fas fa-book-open'],
                ['label' => 'My Timetable', 'path' => '/dashboard/timetable.php?tab=personal', 'icon' => 'fas fa-calendar-alt'],
                ['label' => 'Seminar Hall Booking', 'path' => '/dashboard/seminar_hall_booking.php', 'icon' => 'fas fa-door-open'],
                ['label' => 'Mentor Hub', 'path' => '/dashboard/mentor_hub.php', 'icon' => 'fas fa-user-friends'],
                ['label' => 'Circulars', 'path' => '/admin/circulars.php', 'icon' => 'fas fa-bullhorn'],
                ['label' => 'Profile', 'path' => '/profile.php', 'icon' => 'fas fa-user'],
            ],
            'class_advisor' => [
                ['label' => 'Dashboard', 'path' => '/dashboard/dashboard.php', 'icon' => 'fas fa-th-large'],
                ['label' => 'Post Attendance', 'path' => '/dashboard/attendance.php', 'icon' => 'fas fa-edit'],
                ['label' => 'Topic Coverage', 'path' => '/dashboard/topic_coverage.php', 'icon' => 'fas fa-book-open'],
                ['label' => 'Class Advisor Manager', 'path' => '/dashboard/class_advisor_manager.php', 'icon' => 'fas fa-chalkboard-teacher'],
                ['label' => 'Manage Mentees', 'path' => '/dashboard/manage_mentees.php', 'icon' => 'fas fa-user-graduate'],
                ['label' => 'Mentor Hub', 'path' => '/dashboard/mentor_hub.php', 'icon' => 'fas fa-user-friends'],
                ['label' => 'My Timetable', 'path' => '/dashboard/timetable.php?tab=personal', 'icon' => 'fas fa-calendar-alt'],
                ['label' => 'Circulars', 'path' => '/admin/circulars.php', 'icon' => 'fas fa-bullhorn'],
                ['label' => 'Profile', 'path' => '/profile.php', 'icon' => 'fas fa-user'],
            ],
            'hod' => [
                ['label' => 'Dashboard', 'path' => '/dashboard/dashboard.php', 'icon' => 'fas fa-th-large'],
                ['label' => 'Post Attendance', 'path' => '/dashboard/attendance.php', 'icon' => 'fas fa-edit'],
                ['label' => 'Topic Coverage', 'path' => '/dashboard/topic_coverage.php', 'icon' => 'fas fa-book-open'],
                ['label' => 'Seminar Hall Booking', 'path' => '/dashboard/seminar_hall_booking.php', 'icon' => 'fas fa-door-open'],
                ['label' => 'Class Advisor Manager', 'path' => '/dashboard/class_advisor_manager.php', 'icon' => 'fas fa-chalkboard-teacher'],
                ['label' => 'Mentor Hub', 'path' => '/dashboard/mentor_hub.php', 'icon' => 'fas fa-user-friends'],
                ['label' => 'My Timetable', 'path' => '/dashboard/timetable.php?tab=personal', 'icon' => 'fas fa-calendar-alt'],
                ['label' => 'Timetable', 'path' => '/dashboard/timetable.php?tab=institutional', 'icon' => 'fas fa-table'],
                ['label' => 'Circulars', 'path' => '/admin/circulars.php', 'icon' => 'fas fa-bullhorn'],
                ['label' => 'Profile', 'path' => '/profile.php', 'icon' => 'fas fa-user'],
            ],
            'counsellor' => [
                ['label' => 'Dashboard', 'path' => '/dashboard/dashboard.php', 'icon' => 'fas fa-th-large'],
                ['label' => 'Counseling Dashboard', 'path' => '/counselor/counseling_dashboard.php', 'icon' => 'fas fa-hand-holding-heart'],
                ['label' => 'Profile', 'path' => '/profile.php', 'icon' => 'fas fa-user'],
            ],
            'dean' => [
                ['label' => 'Dashboard', 'path' => '/dashboard/dashboard.php', 'icon' => 'fas fa-th-large'],
                ['label' => 'Post Attendance', 'path' => '/dashboard/attendance.php', 'icon' => 'fas fa-edit'],
                ['label' => 'Attendance Monitor', 'path' => '/attendance_selection.php', 'icon' => 'fas fa-chart-line'],
                ['label' => 'Topic Coverage', 'path' => '/dashboard/topic_coverage.php', 'icon' => 'fas fa-book-open'],
                ['label' => 'My Timetable', 'path' => '/dashboard/timetable.php?tab=personal', 'icon' => 'fas fa-calendar-alt'],
                ['label' => 'Timetable', 'path' => '/dashboard/timetable.php?tab=institutional', 'icon' => 'fas fa-table'],
                ['label' => 'Circular Admin', 'path' => '/admin/manage_circulars.php', 'icon' => 'fas fa-bullhorn'],
                ['label' => 'Mentor Hub', 'path' => '/dashboard/mentor_hub.php', 'icon' => 'fas fa-user-friends'],
                ['label' => 'Seminar Hall Booking', 'path' => '/dashboard/seminar_hall_booking.php', 'icon' => 'fas fa-door-open'],
                ['label' => 'Profile', 'path' => '/profile.php', 'icon' => 'fas fa-user'],
            ],
            'dean_academics' => [
                ['label' => 'Dashboard', 'path' => '/dashboard/dashboard.php', 'icon' => 'fas fa-th-large'],
                ['label' => 'Post Attendance', 'path' => '/dashboard/attendance.php', 'icon' => 'fas fa-edit'],
                ['label' => 'Attendance Monitor', 'path' => '/attendance_selection.php', 'icon' => 'fas fa-chart-line'],
                ['label' => 'Topic Coverage', 'path' => '/dashboard/topic_coverage.php', 'icon' => 'fas fa-book-open'],
                ['label' => 'My Timetable', 'path' => '/dashboard/timetable.php?tab=personal', 'icon' => 'fas fa-calendar-alt'],
                ['label' => 'Timetable', 'path' => '/dashboard/timetable.php?tab=institutional', 'icon' => 'fas fa-table'],
                ['label' => 'Circular Admin', 'path' => '/admin/manage_circulars.php', 'icon' => 'fas fa-bullhorn'],
                ['label' => 'Mentor Hub', 'path' => '/dashboard/mentor_hub.php', 'icon' => 'fas fa-user-friends'],
                ['label' => 'Seminar Hall Booking', 'path' => '/dashboard/seminar_hall_booking.php', 'icon' => 'fas fa-door-open'],
                ['label' => 'Profile', 'path' => '/profile.php', 'icon' => 'fas fa-user'],
            ],
            'principal' => [
                ['label' => 'Dashboard', 'path' => '/dashboard/dashboard.php', 'icon' => 'fas fa-th-large'],
                ['label' => 'Analytics', 'path' => '/dashboard/analytics.php', 'icon' => 'fas fa-chart-pie'],
                ['label' => 'Post Attendance', 'path' => '/dashboard/attendance.php', 'icon' => 'fas fa-edit'],
                ['label' => 'Attendance Monitor', 'path' => '/attendance_selection.php', 'icon' => 'fas fa-chart-line'],
                ['label' => 'Topic Coverage', 'path' => '/dashboard/topic_coverage.php', 'icon' => 'fas fa-book-open'],
                ['label' => 'My Timetable', 'path' => '/dashboard/timetable.php?tab=personal', 'icon' => 'fas fa-calendar-alt'],
                ['label' => 'Circular Admin', 'path' => '/admin/manage_circulars.php', 'icon' => 'fas fa-bullhorn'],
                ['label' => 'Seminar Hall Booking', 'path' => '/dashboard/seminar_hall_booking.php', 'icon' => 'fas fa-door-open'],
                ['label' => 'Mentor Hub', 'path' => '/dashboard/mentor_hub.php', 'icon' => 'fas fa-user-friends'],
                ['label' => 'Bonafide', 'path' => '/bonafide/bonafide.php', 'icon' => 'fas fa-file-signature'],
                ['label' => 'Profile', 'path' => '/profile.php', 'icon' => 'fas fa-user'],
            ],
            'admin' => [
                ['label' => 'Dashboard', 'path' => '/dashboard/dashboard.php', 'icon' => 'fas fa-th-large'],
                ['label' => 'Analytics', 'path' => '/dashboard/analytics.php', 'icon' => 'fas fa-chart-pie'],
                ['label' => 'Post Attendance', 'path' => '/dashboard/attendance.php', 'icon' => 'fas fa-edit'],
                ['label' => 'Attendance Monitor', 'path' => '/attendance_selection.php', 'icon' => 'fas fa-chart-line'],
                ['label' => 'Topic Coverage', 'path' => '/dashboard/topic_coverage.php', 'icon' => 'fas fa-book-open'],
                ['label' => 'My Timetable', 'path' => '/dashboard/timetable.php?tab=personal', 'icon' => 'fas fa-calendar-alt'],
                ['label' => 'Circular Admin', 'path' => '/admin/manage_circulars.php', 'icon' => 'fas fa-bullhorn'],
                ['label' => 'Seminar Hall Booking', 'path' => '/dashboard/seminar_hall_booking.php', 'icon' => 'fas fa-door-open'],
                ['label' => 'Mentor Hub', 'path' => '/dashboard/mentor_hub.php', 'icon' => 'fas fa-user-friends'],
                ['label' => 'Seminar Hall DB Fix', 'path' => '/dashboard/fix_seminar_hall_db.php', 'icon' => 'fas fa-database'],
                ['label' => 'Topic DB Fix', 'path' => '/dashboard/fix_topic_coverage_db.php', 'icon' => 'fas fa-database'],
                ['label' => 'Attendance DB Fix', 'path' => '/dashboard/fix_attendance_db.php', 'icon' => 'fas fa-database'],
                ['label' => 'Profile', 'path' => '/profile.php', 'icon' => 'fas fa-user'],
            ],
            'ao' => [
                ['label' => 'Dashboard', 'path' => '/dashboard/dashboard.php', 'icon' => 'fas fa-th-large'],
                ['label' => 'Seminar Hall Booking', 'path' => '/dashboard/seminar_hall_booking.php', 'icon' => 'fas fa-door-open'],
                ['label' => 'Seminar Hall DB Fix', 'path' => '/dashboard/fix_seminar_hall_db.php', 'icon' => 'fas fa-database'],
                ['label' => 'Profile', 'path' => '/profile.php', 'icon' => 'fas fa-user'],
            ],
        ];
    }
}

if (!function_exists('vh_pages_for_role')) {
    function vh_pages_for_role($role): array
    {
        $role = vh_normalize_role($role);
        $map = vh_role_page_map();
        return $map[$role] ?? ($map['public'] ?? []);
    }
}
?>
