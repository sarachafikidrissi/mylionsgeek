<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Route definitions live in grouped files under routes/general, routes/admin,
| and routes/students. This file only loads them.
|
*/

// General — public & cross-cutting routes
require __DIR__ . '/general/home.php';
require __DIR__ . '/general/appointments.php';
require __DIR__ . '/general/authenticated.php';
require __DIR__ . '/general/notifications.php';
require __DIR__ . '/general/learning.php';
// One-shot /inject-student is local-only — do not load in production.
// require __DIR__ . '/general/inject-students.php';
require __DIR__ . '/general/debug.php';

// Auth & user settings
require __DIR__ . '/auth.php';
require __DIR__ . '/settings.php';

// Admin
require __DIR__ . '/admin/dashboard.php';
require __DIR__ . '/admin/appversion.php';
require __DIR__ . '/admin/users.php';
require __DIR__ . '/admin/face-enrollment.php';
require __DIR__ . '/admin/computers.php';
require __DIR__ . '/admin/leaderboard.php';
require __DIR__ . '/admin/training.php';
require __DIR__ . '/admin/courses.php';
require __DIR__ . '/admin/exercices.php';
require __DIR__ . '/admin/geeko.php';
require __DIR__ . '/admin/equipment.php';
require __DIR__ . '/admin/places.php';
require __DIR__ . '/admin/reservations.php';
require __DIR__ . '/admin/projects.php';
require __DIR__ . '/admin/recruitment.php';
require __DIR__ . '/admin/games.php';
require __DIR__ . '/admin/project-approvals.php';
require __DIR__ . '/admin/post-reports.php';
require __DIR__ . '/admin/story-reports.php';
require __DIR__ . '/admin/announcements.php';
require __DIR__ . '/admin/newsletter.php';

// Students
require __DIR__ . '/students/exercises.php';
require __DIR__ . '/students/students.php';
require __DIR__ . '/students/posts.php';
require __DIR__ . '/studentProjects.php';
require __DIR__.'/students/attendance.php';

// Organisation & recruiter
require __DIR__ . '/organisation.php';
require __DIR__ . '/recruiter.php';

// Shared
require __DIR__ . '/chat.php';