<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\AttendanceReminderNotification;
use App\Models\DisciplineNotification;
use App\Models\ExerciseReviewNotification;
use App\Models\PostNotification;
use App\Models\StoryNotification;
use App\Models\FollowNotification;
use App\Models\ProjectSubmissionNotification;
use App\Models\ProjectStatusNotification;
use App\Models\AccessRequestNotification;
use App\Models\AccessRequestResponseNotification;
use App\Models\TaskAssignmentNotification;
use App\Models\ProjectMessageNotification;
use App\Models\JobApplicationNotification;
use App\Models\PostReportNotification;
use App\Models\UserReportNotification;
use App\Models\UserBlockNotification;
use App\Models\AnnouncementNotification;
use App\Models\Announcement;
use App\Models\EventNotification;
use App\Models\EventNotificationRead;
use App\Models\Formation;
use App\Models\User;
use Ably\AblyRest;

class NotificationController extends Controller
{
    /**
     * Get all notifications for the authenticated user
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['notifications' => []]);
        }

        $notifications = [];

        try {
            $roles = is_array($user->role) ? $user->role : [$user->role];
            $isAdmin = in_array('admin', $roles);
            $isSuperAdmin = in_array('super_admin', $roles);
            $isModerator = in_array('moderateur', $roles);
            $isStudioResponsable = in_array('studio_responsable', $roles);
            $isCoach = in_array('coach', $roles);
            $isRecruiter = in_array('recruiter', $roles);
            $isStaff = $isAdmin || $isSuperAdmin || $isModerator || $isCoach || $isStudioResponsable;

            //  1. DISCIPLINE CHANGE NOTIFICATIONS (Admin, Moderator, Coach)
            // Using new DisciplineNotification model
            if ($isAdmin || $isModerator) {
                // Admins/Moderators get ALL discipline notifications
                $disciplineNotifications = DisciplineNotification::with('user')
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get();

                foreach ($disciplineNotifications as $notif) {
                    // Skip notifications for users with status == "left"
                    if ($notif->user && strtolower($notif->user->status ?? '') === 'left') {
                        continue;
                    }

                    $notifications[] = [
                        'id' => 'discipline-' . $notif->id,
                        'type' => 'discipline_change',
                        'sender_name' => $notif->user->name ?? 'Unknown',
                        'sender_image' => $notif->user->image ?? null,
                        'message' => $notif->message_notification ?? '',
                        'link' => $notif->path ?? "/admin/users/{$notif->user_id}",
                        'mobile_link' => '/profile/' . $notif->user_id,
                        'icon_type' => 'user',
                        'discipline_value' => $notif->discipline_change,
                        'change_type' => $notif->type, // 'increase' or 'decrease'
                        'created_at' => $notif->created_at->toISOString(),
                        'read_at' => $notif->read_at ? $notif->read_at->toISOString() : null,
                    ];
                }
            } elseif ($isCoach) {
                // Coaches get only their students' discipline notifications
                $coachFormations = Formation::where('user_id', $user->id)->pluck('id');
                $studentIds = User::whereIn('formation_id', $coachFormations)->pluck('id');

                $disciplineNotifications = DisciplineNotification::with('user')
                    ->whereIn('user_id', $studentIds)
                    ->orderByDesc('created_at')
                    ->limit(20)
                    ->get();

                foreach ($disciplineNotifications as $notif) {
                    // Skip notifications for users with status == "left"
                    if ($notif->user && strtolower($notif->user->status ?? '') === 'left') {
                        continue;
                    }

                    $notifications[] = [
                        'id' => 'discipline-' . $notif->id,
                        'type' => 'discipline_change',
                        'sender_name' => $notif->user->name ?? 'Unknown',
                        'sender_image' => $notif->user->image ?? null,
                        'message' => $notif->message_notification ?? '',
                        'link' => $notif->path ?? "/admin/users/{$notif->user_id}",
                        'mobile_link' => '/profile/' . $notif->user_id,
                        'icon_type' => 'user',
                        'discipline_value' => $notif->discipline_change,
                        'change_type' => $notif->type,
                        'created_at' => $notif->created_at->toISOString(),
                        'read_at' => $notif->read_at ? $notif->read_at->toISOString() : null,
                    ];
                }
            }

            // Exercise Review Notifications (Coach) - Show for all coaches regardless of other roles
            if ($isCoach) {
                try {
                    $exerciseReviewNotifications = ExerciseReviewNotification::with(['user', 'exercice.training'])
                        ->where('coach_id', $user->id)
                        ->orderByDesc('created_at')
                        ->limit(20)
                        ->get();

                    Log::info('Exercise review notifications query', [
                        'coach_id' => $user->id,
                        'found_count' => $exerciseReviewNotifications->count()
                    ]);

                    foreach ($exerciseReviewNotifications as $notif) {
                        // Only add if user relationship exists
                        if ($notif->user) {
                            // Get training_id from exercice if path doesn't have it
                            $link = $notif->path;
                            if (!$link || $link === "/admin/exercices" || $link === "/trainings") {
                                // Get training_id from exercice
                                $trainingId = null;
                                if ($notif->exercice) {
                                    $trainingId = $notif->exercice->training_id ?? ($notif->exercice->training->id ?? null);
                                }
                                if ($trainingId) {
                                    $link = "/trainings/{$trainingId}";
                                } else {
                                    $link = "/trainings";
                                }
                            }
                            
                            $notifications[] = [
                                'id' => 'exercise-review-' . $notif->id,
                                'type' => 'exercise_review',
                                'sender_name' => $notif->user->name ?? 'Unknown',
                                'sender_image' => $notif->user->image ?? null,
                                'message' => $notif->message_notification ?? 'Student asked you to review his exercise',
                                'link' => $link,
                                'mobile_link' => '/training',
                                'icon_type' => 'file-text',
                                'created_at' => $notif->created_at->toISOString(),
                                'read_at' => $notif->read_at ? $notif->read_at->toISOString() : null,
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Error fetching exercise review notifications: ' . $e->getMessage());
                }
            }

            //  1.5. PROJECT SUBMISSION NOTIFICATIONS (Admin and Coach)
            if (($isAdmin || $isModerator || $isCoach) && Schema::hasTable('project_submission_notifications')) {
                try {
                    $projectNotifications = ProjectSubmissionNotification::with(['student', 'project'])
                        ->where('notified_user_id', $user->id)
                        ->orderByDesc('created_at')
                        ->limit(20)
                        ->get();

                    foreach ($projectNotifications as $notif) {
                        if ($notif->student && $notif->project) {
                            $link = $notif->path ?? "/students/project/{$notif->project_id}";
                            
                            $notifications[] = [
                                'id' => 'project-submission-' . $notif->id,
                                'type' => 'project_submission',
                                'sender_name' => $notif->student->name ?? 'Unknown',
                                'sender_image' => $notif->student->image ?? null,
                                'message' => $notif->message_notification ?? 'A student submitted a new project',
                                'link' => $link,
                                'mobile_link' => '/projects',
                                'icon_type' => 'folder',
                                'created_at' => $notif->created_at->toISOString(),
                                'read_at' => $notif->read_at ? $notif->read_at->toISOString() : null,
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Error fetching project submission notifications: ' . $e->getMessage());
                }
            }

            //  1.6. ACCESS REQUEST NOTIFICATIONS (Admin only)
            if ($isAdmin && Schema::hasTable('access_request_notifications')) {
                try {
                    $accessRequests = AccessRequestNotification::with('user')
                        ->where('status', 'pending')
                        ->orderByDesc('created_at')
                        ->limit(20)
                        ->get();

                    foreach ($accessRequests as $notif) {
                        if ($notif->user) {
                            $accessTypeLabel = match($notif->requested_access_type) {
                                'studio' => 'Studio',
                                'cowork' => 'Cowork',
                                'both' => 'Studio & Cowork',
                                default => 'Access'
                            };
                            
                            $notifications[] = [
                                'id' => 'access-request-' . $notif->id,
                                'type' => 'access_request',
                                'sender_name' => $notif->user->name ?? 'Unknown',
                                'sender_image' => $notif->user->image ?? null,
                                'message' => $notif->message ?? "{$notif->user->name} requested {$accessTypeLabel} access",
                                'link' => "/admin/users/{$notif->user_id}",
                                'mobile_link' => '/reservations',
                                'icon_type' => 'lock',
                                'access_type' => $notif->requested_access_type,
                                'notification_id' => $notif->id,
                                'created_at' => $notif->created_at->toISOString(),
                                'read_at' => $notif->read_at ? $notif->read_at->toISOString() : null,
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Error fetching access request notifications: ' . $e->getMessage());
                }
            }

            //  2. PENDING RESERVATIONS (Studio Responsable)
            if ($isStudioResponsable && Schema::hasTable('reservations')) {
                $pendingReservations = DB::table('reservations')
                    ->leftJoin('users', 'users.id', '=', 'reservations.user_id')
                    ->where('reservations.canceled', 0)
                    ->where('reservations.approved', 0)
                    ->select(
                        'reservations.id',
                        'reservations.title',
                        'reservations.day',
                        'reservations.start',
                        'reservations.end',
                        'reservations.type',
                        'reservations.created_at',
                        'users.name as sender_name',
                        'users.image as sender_image'
                    )
                    ->orderByDesc('reservations.created_at')
                    ->limit(5)
                    ->get();

                foreach ($pendingReservations as $reservation) {
                    $message = $reservation->title ?? "Reservation #{$reservation->id}";
                    if ($reservation->day && $reservation->start && $reservation->end) {
                        $message .= " - {$reservation->day} {$reservation->start}-{$reservation->end}";
                    }
                    if ($reservation->type) {
                        $message .= " ({$reservation->type})";
                    }

                    $notifications[] = [
                        'id' => 'reservation-' . $reservation->id,
                        'type' => 'reservation',
                        'sender_name' => $reservation->sender_name ?? 'Unknown',
                        'sender_image' => $reservation->sender_image,
                        'message' => $message,
                        'created_at' => $reservation->created_at
                            ? \Illuminate\Support\Carbon::parse($reservation->created_at)->toISOString()
                            : now()->toISOString(),
                        'read_at' => $this->isSyntheticNotificationDismissed($user->id, 'reservation', (int) $reservation->id)
                            ? now()->toISOString()
                            : null,
                        'link' => '/admin/reservations/' . $reservation->id . '/details',
                        'mobile_link' => '/admin/reservations/' . $reservation->id . '/details',
                        'icon_type' => 'calendar',
                    ];
                }
            }

            //  3. APPOINTMENTS (Admin)
            if ($isAdmin && Schema::hasTable('appointments')) {
                $userEmail = strtolower($user->email ?? '');
                if ($userEmail) {
                    $pendingAppointments = DB::table('appointments as a')
                        ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
                        ->whereRaw('LOWER(a.person_email) = ?', [$userEmail])
                        ->where('a.status', 'pending')
                        ->select(
                            'a.id',
                            'a.day',
                            'a.start',
                            'a.end',
                            'a.created_at',
                            'u.name as requester_name',
                            'u.image as requester_image'
                        )
                        ->orderByDesc('a.created_at')
                        ->limit(10)
                        ->get();

                    foreach ($pendingAppointments as $appointment) {
                        $message = "Appointment request";
                        if ($appointment->day && $appointment->start && $appointment->end) {
                            $message .= " - {$appointment->day} {$appointment->start}-{$appointment->end}";
                        }

                        $notifications[] = [
                            'id' => 'appointment-' . $appointment->id,
                            'type' => 'appointment',
                            'sender_name' => $appointment->requester_name ?? 'Unknown',
                            'sender_image' => $appointment->requester_image,
                            'message' => $message,
                            'created_at' => $appointment->created_at
                                ? \Illuminate\Support\Carbon::parse($appointment->created_at)->toISOString()
                                : now()->toISOString(),
                            'read_at' => $this->isSyntheticNotificationDismissed($user->id, 'appointment', (int) $appointment->id)
                                ? now()->toISOString()
                                : null,
                            'link' => '/admin/appointments',
                            'mobile_link' => '/admin/appointments',
                            'icon_type' => 'calendar',
                        ];
                    }
                }
            }

            // 3.5. ACCESS REQUEST RESPONSE NOTIFICATIONS (Users who requested access)
            if (Schema::hasTable('access_request_response_notifications')) {
                try {
                    $accessResponseNotifications = AccessRequestResponseNotification::with(['reviewer', 'accessRequest'])
                        ->where('user_id', $user->id)
                        ->orderByDesc('created_at')
                        ->limit(20)
                        ->get();

                    foreach ($accessResponseNotifications as $notif) {
                        $reviewerName = $notif->reviewer ? $notif->reviewer->name : 'Admin';
                        $message = $notif->message_notification;
                        
                        // Add denial reason to message if denied
                        if ($notif->status === 'denied' && $notif->denial_reason) {
                            $message .= ' Reason: ' . $notif->denial_reason;
                        }

                        $notifications[] = [
                            'id' => 'access-request-response-' . $notif->id,
                            'type' => 'access_request_response',
                            'sender_name' => $reviewerName,
                            'sender_image' => $notif->reviewer ? $notif->reviewer->image : null,
                            'message' => $message,
                            'link' => $notif->path ?? '/student/spaces',
                            'mobile_link' => '/reservations',
                            'icon_type' => $notif->status === 'approved' ? 'check-circle' : 'x-circle',
                            'status' => $notif->status,
                            'denial_reason' => $notif->denial_reason,
                            'created_at' => $notif->created_at->toISOString(),
                            'read_at' => $notif->read_at ? $notif->read_at->toISOString() : null,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::error('Error fetching access request response notifications: ' . $e->getMessage());
                }
            }

            // 4. POST NOTIFICATIONS (All users)
            $postNotifications = PostNotification::with(['sender', 'post'])
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();

            foreach ($postNotifications as $notif) {
                $senderName = $notif->sender ? $notif->sender->name : 'Unknown';
                $senderImage = $notif->sender ? $notif->sender->image : null;
                
                if ($notif->type === 'like') {
                    $message = "{$senderName} liked your post";
                } elseif ($notif->type === 'comment') {
                    $message = "{$senderName} commented on your post";
                } elseif ($notif->type === 'comment_like') {
                    $message = "{$senderName} liked your comment";
                } elseif ($notif->type === 'mention') {
                    $message = "{$senderName} mentioned you in a post";
                } elseif ($notif->type === 'repost') {
                    $message = "{$senderName} reposted your post";
                } elseif ($notif->type === 'share') {
                    $message = "{$senderName} shared a post with you";
                } elseif ($notif->type === 'repost_like') {
                    $message = "{$senderName} liked the post you reposted";
                } elseif ($notif->type === 'repost_comment') {
                    $message = "{$senderName} commented on the post you reposted";
                } else {
                    $message = "{$senderName} interacted with your post";
                }

                $notifications[] = [
                    'id' => 'post-' . $notif->id,
                    'type' => 'post_interaction',
                    'sender_name' => $senderName,
                    'sender_image' => $senderImage,
                    'message' => $message,
                    'link' => '/students/feed#post-' . $notif->post_id,
                    'mobile_link' => '/posts/' . $notif->post_id,
                    'post_id' => $notif->post_id,
                    'icon_type' => 'user',
                    'created_at' => $notif->created_at->toISOString(),
                    'read_at' => $notif->read_at ? $notif->read_at->toISOString() : null,
                ];
            }

            if (Schema::hasTable('story_notifications')) {
                try {
                    $storyNotifs = StoryNotification::with(['sender', 'story'])
                        ->where('user_id', $user->id)
                        ->orderByDesc('created_at')
                        ->limit(20)
                        ->get();
                    foreach ($storyNotifs as $notif) {
                        $senderName = $notif->sender ? $notif->sender->name : 'Someone';
                        $senderImage = $notif->sender ? $notif->sender->image : null;
                        $senderId = (int) $notif->sender_id;
                        $notifications[] = [
                            'id' => 'story-mention-'.$notif->id,
                            'type' => 'story_mention',
                            'sender_name' => $senderName,
                            'sender_image' => $senderImage,
                            'message' => "{$senderName} mentioned you in a story",
                            'link' => '/students/feed',
                            'mobile_link' => '/stories/viewer?startUserId='.$senderId,
                            'story_id' => (int) $notif->story_id,
                            'icon_type' => 'at',
                            'created_at' => $notif->created_at?->toIso8601String() ?? now()->toIso8601String(),
                            'read_at' => $notif->read_at ? $notif->read_at->toIso8601String() : null,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::error('Error fetching story mention notifications: '.$e->getMessage());
                }
            }

            // 4.5. POST REPORT NOTIFICATIONS (Staff)
            if ($isStaff && Schema::hasTable('post_report_notifications')) {
                try {
                    $reportNotifs = PostReportNotification::query()
                        ->with([
                            'report',
                            'report.reporter:id,name,image',
                        ])
                        ->where('notified_user_id', $user->id)
                        ->orderByDesc('created_at')
                        ->limit(20)
                        ->get();

                    foreach ($reportNotifs as $rn) {
                        $report = $rn->report;
                        $reporter = $report?->reporter;
                        if (!$report || !$reporter) {
                            continue;
                        }

                        $notifications[] = [
                            'id' => 'post-report-' . $rn->id,
                            'type' => 'post_report',
                            'sender_name' => $reporter->name ?? 'User',
                            'sender_image' => $reporter->image ?? null,
                            'message' => ($reporter->name ?? 'Someone') . ' reported a post',
                            'link' => "/admin/post-reports/{$report->id}",
                            'mobile_link' => "/posts/{$report->post_id}?reportId={$report->id}",
                            'icon_type' => 'flag',
                            'created_at' => $rn->created_at?->toISOString() ?? now()->toISOString(),
                            'read_at' => $rn->read_at ? $rn->read_at->toISOString() : null,
                            'post_id' => (int) $report->post_id,
                            'report_id' => (int) $report->id,
                            'report_status' => (string) $report->status,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::error('Error fetching post report notifications: ' . $e->getMessage());
                }
            }

            // 4.6. USER REPORT NOTIFICATIONS (Staff)
            if ($isStaff && Schema::hasTable('user_report_notifications')) {
                try {
                    $userReportNotifs = UserReportNotification::query()
                        ->with([
                            'report',
                            'report.reporter:id,name,image',
                            'report.reportedUser:id,name',
                        ])
                        ->where('notified_user_id', $user->id)
                        ->orderByDesc('created_at')
                        ->limit(20)
                        ->get();

                    foreach ($userReportNotifs as $rn) {
                        $report = $rn->report;
                        $reporter = $report?->reporter;
                        $reported = $report?->reportedUser;
                        if (! $report || ! $reporter) {
                            continue;
                        }

                        $reportedName = $reported?->name ?? 'a user';
                        $notifications[] = [
                            'id' => 'user-report-'.$rn->id,
                            'type' => 'user_report',
                            'sender_name' => $reporter->name ?? 'User',
                            'sender_image' => $reporter->image ?? null,
                            'message' => ($reporter->name ?? 'Someone').' reported '.$reportedName,
                            'link' => '/admin/users/'.((int) $report->reported_user_id),
                            'mobile_link' => '/profile/'.((int) $report->reported_user_id),
                            'icon_type' => 'flag',
                            'created_at' => $rn->created_at?->toISOString() ?? now()->toISOString(),
                            'read_at' => $rn->read_at ? $rn->read_at->toISOString() : null,
                            'reported_user_id' => (int) $report->reported_user_id,
                            'report_id' => (int) $report->id,
                            'report_status' => (string) $report->status,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::error('Error fetching user report notifications: '.$e->getMessage());
                }
            }

            // 4.7. USER BLOCK NOTIFICATIONS (Staff)
            if ($isStaff && Schema::hasTable('user_block_notifications')) {
                try {
                    $blockNotifs = UserBlockNotification::query()
                        ->with([
                            'block',
                            'block.blocker:id,name,image',
                            'block.blocked:id,name',
                        ])
                        ->where('notified_user_id', $user->id)
                        ->orderByDesc('created_at')
                        ->limit(20)
                        ->get();

                    foreach ($blockNotifs as $bn) {
                        $block = $bn->block;
                        $blocker = $block?->blocker;
                        $blocked = $block?->blocked;
                        if (! $block || ! $blocker) {
                            continue;
                        }

                        $blockedName = $blocked?->name ?? 'a user';
                        $notifications[] = [
                            'id' => 'user-block-'.$bn->id,
                            'type' => 'user_block',
                            'sender_name' => $blocker->name ?? 'User',
                            'sender_image' => $blocker->image ?? null,
                            'message' => ($blocker->name ?? 'Someone').' blocked '.$blockedName,
                            'link' => '/admin/users/'.((int) $block->blocked_id),
                            'mobile_link' => '/profile/'.((int) $block->blocked_id),
                            'icon_type' => 'ban',
                            'created_at' => $bn->created_at?->toISOString() ?? now()->toISOString(),
                            'read_at' => $bn->read_at ? $bn->read_at->toISOString() : null,
                            'blocked_user_id' => (int) $block->blocked_id,
                            'block_id' => (int) $block->id,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::error('Error fetching user block notifications: '.$e->getMessage());
                }
            }

            // 5. FOLLOW NOTIFICATIONS (All users)
            $followNotifications = FollowNotification::with('follower')
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();

            foreach ($followNotifications as $notif) {
                $senderName = $notif->follower ? $notif->follower->name : 'Unknown';
                $senderImage = $notif->follower ? $notif->follower->image : null;
                
                $notifications[] = [
                    'id' => 'follow-' . $notif->id,
                    'type' => 'follow',
                    'sender_name' => $senderName,
                    'sender_image' => $senderImage,
                    'message' => "{$senderName} started following you",
                    'link' => "/students/{$notif->follower_id}",
                    'mobile_link' => '/profile/' . $notif->follower_id,
                    'icon_type' => 'user',
                    'created_at' => $notif->created_at->toISOString(),
                    'read_at' => $notif->read_at ? $notif->read_at->toISOString() : null,
                    'follower_id' => $notif->follower_id,
                ];
            }

            if ($isRecruiter && Schema::hasTable('job_application_notifications')) {
                try {
                    $jobApplicationNotifications = JobApplicationNotification::with([
                        'applicant:id,name,image',
                        'jobApplication.job:id,title',
                    ])
                        ->where('notified_user_id', $user->id)
                        ->orderByDesc('created_at')
                        ->limit(20)
                        ->get();

                    foreach ($jobApplicationNotifications as $notif) {
                        $applicant = $notif->applicant;
                        $jobTitle = $notif->jobApplication?->job?->title ?? 'a job';
                        $applicantName = $applicant?->name ?? 'Someone';
                        $jobId = $notif->jobApplication?->job_posting_id;

                        $notifications[] = [
                            'id' => 'job-application-' . $notif->id,
                            'type' => 'job_application',
                            'sender_name' => $applicantName,
                            'sender_image' => $applicant?->image,
                            'message' => "{$applicantName} applied to {$jobTitle}",
                            'link' => $jobId ? "/recruiter/applications/jobs/{$jobId}" : '/recruiter/applications',
                            'mobile_link' => '/home',
                            'icon_type' => 'briefcase',
                            'created_at' => $notif->created_at->toISOString(),
                            'read_at' => $notif->read_at ? $notif->read_at->toISOString() : null,
                            'job_application_id' => $notif->job_application_id,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::error('Error fetching job application notifications: ' . $e->getMessage());
                }
            }

            // 6. PROJECT STATUS NOTIFICATIONS (Students - when project is approved/rejected)
            if (Schema::hasTable('project_status_notifications')) {
                try {
                    $projectStatusNotifications = ProjectStatusNotification::with(['project', 'reviewer'])
                        ->where('student_id', $user->id)
                        ->orderByDesc('created_at')
                        ->limit(20)
                        ->get();

                    foreach ($projectStatusNotifications as $notif) {
                        if ($notif->project) {
                            $link = $notif->path ?? "/students/project/{$notif->project_id}";
                            $reviewerName = $notif->reviewer ? $notif->reviewer->name : 'Admin';
                            
                            $iconType = $notif->status === 'approved' ? 'check-circle' : 'x-circle';
                            
                            $notifications[] = [
                                'id' => 'project-status-' . $notif->id,
                                'type' => 'project_status',
                                'sender_name' => $reviewerName,
                                'sender_image' => $notif->reviewer ? $notif->reviewer->image : null,
                                'message' => $notif->message_notification,
                                'link' => $link,
                                'mobile_link' => '/projects',
                                'icon_type' => $iconType,
                                'created_at' => $notif->created_at->toISOString(),
                                'read_at' => $notif->read_at ? $notif->read_at->toISOString() : null,
                                'status' => $notif->status,
                                'rejection_reason' => $notif->rejection_reason,
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Error fetching project status notifications: ' . $e->getMessage());
                }
            }

            // 7. TASK ASSIGNMENT NOTIFICATIONS (Users assigned to tasks)
            if (Schema::hasTable('task_assignment_notifications')) {
                try {
                    $taskAssignmentNotifications = TaskAssignmentNotification::with(['task', 'assignedByUser'])
                        ->where('assigned_to_user_id', $user->id)
                        ->orderByDesc('created_at')
                        ->limit(20)
                        ->get();

                    foreach ($taskAssignmentNotifications as $notif) {
                        if ($notif->assignedByUser) {
                            $link = $notif->path ?? "/admin/projects";
                            
                            $notifications[] = [
                                'id' => 'task-assignment-' . $notif->id,
                                'type' => 'task_assignment',
                                'sender_name' => $notif->assignedByUser->name ?? 'Unknown',
                                'sender_image' => $notif->assignedByUser->image ?? null,
                                'message' => $notif->message_notification,
                                'link' => $link,
                                'mobile_link' => '/projects',
                                'icon_type' => 'briefcase',
                                'created_at' => $notif->created_at->toISOString(),
                                'read_at' => $notif->read_at ? $notif->read_at->toISOString() : null,
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Error fetching task assignment notifications: ' . $e->getMessage());
                }
            }

            // 8. PROJECT MESSAGE NOTIFICATIONS (Users who receive project chat messages)
            if (Schema::hasTable('project_message_notifications')) {
                try {
                    $projectMessageNotifications = ProjectMessageNotification::with(['sender', 'project'])
                        ->where('notified_user_id', $user->id)
                        ->orderByDesc('created_at')
                        ->limit(20)
                        ->get();

                    foreach ($projectMessageNotifications as $notif) {
                        if ($notif->sender && $notif->project) {
                            $link = $notif->path ?? "/admin/projects/{$notif->project_id}";
                            
                            $notifications[] = [
                                'id' => 'project-message-' . $notif->id,
                                'type' => 'project_message',
                                'sender_name' => $notif->sender->name ?? 'Unknown',
                                'sender_image' => $notif->sender->image ?? null,
                                'message' => $notif->message_notification,
                                'link' => $link,
                                'mobile_link' => '/projects',
                                'icon_type' => 'message-square',
                                'created_at' => $notif->created_at->toISOString(),
                                'read_at' => $notif->read_at ? $notif->read_at->toISOString() : null,
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Error fetching project message notifications: ' . $e->getMessage());
                }
            }

            // Announcements (loaded on bell open / poll — no real-time push)
            if (Schema::hasTable('announcements')) {
                try {
                    $announcements = Announcement::with('creator')
                        ->latest()
                        ->limit(20)
                        ->get();

                    $readStates = Schema::hasTable('announcement_notifications')
                        ? AnnouncementNotification::where('user_id', $user->id)
                            ->whereIn('announcement_id', $announcements->pluck('id'))
                            ->pluck('read_at', 'announcement_id')
                        : collect();

                    foreach ($announcements as $announcement) {
                        $readAt = $readStates->get($announcement->id);

                        $notifications[] = [
                            'id' => 'announcement-' . $announcement->id,
                            'type' => 'announcement',
                            'sender_name' => $announcement->title,
                            'sender_image' => $announcement->creator?->image,
                            'message' => $announcement->message,
                            'link' => '/dashboard',
                            'mobile_link' => '/home',
                            'icon_type' => 'megaphone',
                            'created_at' => $announcement->created_at->toISOString(),
                            'read_at' => $readAt ? $readAt->toISOString() : null,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::error('Error fetching announcement notifications: ' . $e->getMessage());
                }
            }

            // Public events from lionsgeek.ma (stored when webhook fires)
            if (Schema::hasTable('event_notifications')) {
                try {
                    $eventNotifications = EventNotification::latest()
                        ->limit(20)
                        ->get();

                    $readStates = Schema::hasTable('event_notification_reads')
                        ? EventNotificationRead::where('user_id', $user->id)
                            ->whereIn('event_notification_id', $eventNotifications->pluck('id'))
                            ->pluck('read_at', 'event_notification_id')
                        : collect();

                    foreach ($eventNotifications as $eventNotification) {
                        $readAt = $readStates->get($eventNotification->id);

                        $notifications[] = [
                            'id' => 'event-' . $eventNotification->id,
                            'type' => 'event',
                            'event_id' => $eventNotification->lionsgeek_event_id,
                            'sender_name' => $eventNotification->title,
                            'message' => $eventNotification->message,
                            'mobile_link' => '/events/' . $eventNotification->lionsgeek_event_id,
                            'icon_type' => 'calendar',
                            'created_at' => $eventNotification->created_at->toISOString(),
                            'read_at' => $readAt ? $readAt->toISOString() : null,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::error('Error fetching event notifications: ' . $e->getMessage());
                }
            }

            // Attendance slot reminders — student-owned only (NOT staff-scoped like discipline)
            if (Schema::hasTable('attendance_reminder_notifications')) {
                try {
                    $attendanceReminders = AttendanceReminderNotification::query()
                        ->where('user_id', $user->id)
                        ->orderByDesc('created_at')
                        ->limit(20)
                        ->get();

                    $slotLabels = [
                        'morning' => 'Morning',
                        'lunch' => 'Coffee break',
                        'evening' => 'Lunch',
                    ];

                    foreach ($attendanceReminders as $notif) {
                        $slotLabel = $slotLabels[$notif->slot] ?? $notif->slot;
                        $message = $notif->message_notification
                            ?: "Check in for {$slotLabel}";

                        $notifications[] = [
                            'id' => 'attendance_reminder-' . $notif->id,
                            'type' => 'attendance_reminder',
                            'sender_name' => 'Attendance',
                            'sender_image' => null,
                            'message' => $message,
                            'link' => $notif->path ?? '/students/attendance',
                            'mobile_link' => '/training/check-in',
                            'icon_type' => 'clock',
                            'slot' => $notif->slot,
                            'date' => $notif->date?->format('Y-m-d'),
                            'created_at' => $notif->created_at->toISOString(),
                            'read_at' => $notif->read_at ? $notif->read_at->toISOString() : null,
                        ];
                    }
                } catch (\Exception $e) {
                    Log::error('Error fetching attendance reminder notifications: ' . $e->getMessage());
                }
            }

            // Sort by created_at (newest first)
            usort($notifications, function ($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });

            return response()->json(['notifications' => array_slice($notifications, 0, 50)]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch notifications: ' . $e->getMessage());
            return response()->json(['notifications' => []], 500);
        }
    }

    /**
     * Mark a specific notification as read
     */
    public function markAsRead(Request $request, $type, $id)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            switch ($type) {
                case 'follow':
                    $notification = FollowNotification::where('id', $id)
                        ->where('user_id', $user->id)
                        ->first();
                    if ($notification) {
                        $notification->read_at = now();
                        $notification->save();
                    }
                    break;
                case 'post':
                    $notification = PostNotification::where('id', $id)
                        ->where('user_id', $user->id)
                        ->first();
                    if ($notification) {
                        $notification->read_at = now();
                        $notification->save();
                    }
                    break;
                case 'story-mention':
                case 'story_mention':
                    if (Schema::hasTable('story_notifications')) {
                        $notification = StoryNotification::where('id', $id)
                            ->where('user_id', $user->id)
                            ->first();
                        if ($notification) {
                            $notification->read_at = now();
                            $notification->save();
                        }
                    }
                    break;
                case 'exercise-review':
                    $notification = ExerciseReviewNotification::where('id', $id)
                        ->where('coach_id', $user->id)
                        ->first();
                    if ($notification) {
                        $notification->read_at = now();
                        $notification->save();
                    }
                    break;
                case 'project-submission':
                    if (Schema::hasTable('project_submission_notifications')) {
                        $notification = ProjectSubmissionNotification::where('id', $id)
                            ->where('notified_user_id', $user->id)
                            ->first();
                        if ($notification) {
                            $notification->read_at = now();
                            $notification->save();
                        }
                    }
                    break;
                case 'project-status':
                    if (Schema::hasTable('project_status_notifications')) {
                        $notification = ProjectStatusNotification::where('id', $id)
                            ->where('student_id', $user->id)
                            ->first();
                        if ($notification) {
                            $notification->read_at = now();
                            $notification->save();
                        }
                    }
                    break;
                case 'discipline':
                case 'discipline_change':
                    $roles = is_array($user->role) ? $user->role : [$user->role];
                    $isAdmin = in_array('admin', $roles);
                    $isModerator = in_array('moderateur', $roles);
                    $isCoach = in_array('coach', $roles);
                    
                    $query = DisciplineNotification::where('id', $id);
                    
                    if ($isAdmin || $isModerator) {
                        // Admins/Moderators can mark any discipline notification as read
                        $notification = $query->first();
                    } elseif ($isCoach) {
                        // Coaches can only mark discipline notifications for their students
                        $coachFormations = Formation::where('user_id', $user->id)->pluck('id');
                        $studentIds = User::whereIn('formation_id', $coachFormations)->pluck('id');
                        $notification = $query->whereIn('user_id', $studentIds)->first();
                    } else {
                        // Regular users can mark their own discipline notifications
                        $notification = $query->where('user_id', $user->id)->first();
                    }
                    
                    if ($notification) {
                        $notification->read_at = now();
                        $notification->save();
                    }
                    break;
                case 'access-request':
                case 'access_request':
                    $roles = is_array($user->role) ? $user->role : [$user->role];
                    $isAdmin = in_array('admin', $roles);
                    if ($isAdmin && Schema::hasTable('access_request_notifications')) {
                        $notification = AccessRequestNotification::where('id', $id)->first();
                        if ($notification) {
                            $notification->read_at = now();
                            $notification->save();
                        }
                    }
                    break;
                case 'access-request-response':
                case 'access_request_response':
                    if (Schema::hasTable('access_request_response_notifications')) {
                        $notification = AccessRequestResponseNotification::where('id', $id)
                            ->where('user_id', $user->id)
                            ->first();
                        if ($notification) {
                            $notification->read_at = now();
                            $notification->save();
                        }
                    }
                    break;
                case 'task-assignment':
                case 'task_assignment':
                    if (Schema::hasTable('task_assignment_notifications')) {
                        $notification = TaskAssignmentNotification::where('id', $id)
                            ->where('assigned_to_user_id', $user->id)
                            ->first();
                        if ($notification) {
                            $notification->read_at = now();
                            $notification->save();
                        }
                    }
                    break;
                case 'project-message':
                case 'project_message':
                    if (Schema::hasTable('project_message_notifications')) {
                        $notification = ProjectMessageNotification::where('id', $id)
                            ->where('notified_user_id', $user->id)
                            ->first();
                        if ($notification) {
                            $notification->read_at = now();
                            $notification->save();
                        }
                    }
                    break;
                case 'post-report':
                case 'post_report':
                    if (Schema::hasTable('post_report_notifications')) {
                        $notification = PostReportNotification::where('id', $id)
                            ->where('notified_user_id', $user->id)
                            ->first();
                        if ($notification) {
                            $notification->read_at = now();
                            $notification->save();
                        }
                    }
                    break;
                case 'user-report':
                case 'user_report':
                    if (Schema::hasTable('user_report_notifications')) {
                        $notification = UserReportNotification::where('id', $id)
                            ->where('notified_user_id', $user->id)
                            ->first();
                        if ($notification) {
                            $notification->read_at = now();
                            $notification->save();
                        }
                    }
                    break;
                case 'user-block':
                case 'user_block':
                    if (Schema::hasTable('user_block_notifications')) {
                        $notification = UserBlockNotification::where('id', $id)
                            ->where('notified_user_id', $user->id)
                            ->first();
                        if ($notification) {
                            $notification->read_at = now();
                            $notification->save();
                        }
                    }
                    break;
                case 'job-application':
                case 'job_application':
                    if (Schema::hasTable('job_application_notifications')) {
                        $notification = JobApplicationNotification::where('id', $id)
                            ->where('notified_user_id', $user->id)
                            ->first();
                        if ($notification) {
                            $notification->read_at = now();
                            $notification->save();
                        }
                    }
                    break;
                case 'announcement':
                    if (Schema::hasTable('announcement_notifications')) {
                        $notification = AnnouncementNotification::firstOrCreate(
                            [
                                'user_id' => $user->id,
                                'announcement_id' => $id,
                            ]
                        );
                        $notification->read_at = now();
                        $notification->save();
                    }
                    break;
                case 'event':
                    if (Schema::hasTable('event_notification_reads')) {
                        $notification = EventNotificationRead::firstOrCreate(
                            [
                                'user_id' => $user->id,
                                'event_notification_id' => $id,
                            ]
                        );
                        $notification->read_at = now();
                        $notification->save();
                    }
                    break;
                case 'attendance_reminder':
                case 'attendance-reminder':
                    if (Schema::hasTable('attendance_reminder_notifications')) {
                        $notification = AttendanceReminderNotification::where('id', $id)
                            ->where('user_id', $user->id)
                            ->first();
                        if ($notification) {
                            $notification->read_at = now();
                            $notification->save();
                        }
                    }
                    break;
                case 'reservation':
                case 'appointment':
                    // Synthetic inbox items (pending reservations/appointments) have no
                    // dedicated read table — persist a dismissal so mark-read sticks after refresh.
                    $this->dismissSyntheticNotification((int) $user->id, $type === 'appointment' ? 'appointment' : 'reservation', (int) $id);
                    break;
                default:
                    return response()->json(['error' => 'Invalid notification type'], 400);
            }

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Failed to mark notification as read: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to mark as read'], 500);
        }
    }

    /**
     * Mark all notifications as read for the authenticated user
     */
    public function markAllAsRead(Request $request)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            // Mark all follow notifications as read
            FollowNotification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            // Mark all post notifications as read
            PostNotification::where('user_id', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            if (Schema::hasTable('story_notifications')) {
                StoryNotification::where('user_id', $user->id)
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);
            }

            // Mark all exercise review notifications as read (for coaches)
            ExerciseReviewNotification::where('coach_id', $user->id)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            // Mark all project submission notifications as read (for admins/coaches)
            if (Schema::hasTable('project_submission_notifications')) {
                ProjectSubmissionNotification::where('notified_user_id', $user->id)
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);
            }

            // Mark all project status notifications as read (for students)
            if (Schema::hasTable('project_status_notifications')) {
                ProjectStatusNotification::where('student_id', $user->id)
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);
            }

            // Mark all task assignment notifications as read (for users)
            if (Schema::hasTable('task_assignment_notifications')) {
                TaskAssignmentNotification::where('assigned_to_user_id', $user->id)
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);
            }

            // Mark all project message notifications as read (for users)
            if (Schema::hasTable('project_message_notifications')) {
                ProjectMessageNotification::where('notified_user_id', $user->id)
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);
            }

            // Mark all post report notifications as read (for staff)
            if (Schema::hasTable('post_report_notifications')) {
                PostReportNotification::where('notified_user_id', $user->id)
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);
            }

            if (Schema::hasTable('user_report_notifications')) {
                UserReportNotification::where('notified_user_id', $user->id)
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);
            }

            if (Schema::hasTable('user_block_notifications')) {
                UserBlockNotification::where('notified_user_id', $user->id)
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);
            }

            if (Schema::hasTable('job_application_notifications')) {
                JobApplicationNotification::where('notified_user_id', $user->id)
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);
            }

            if (Schema::hasTable('announcement_notifications') && Schema::hasTable('announcements')) {
                $announcementIds = Announcement::latest()->limit(50)->pluck('id');

                foreach ($announcementIds as $announcementId) {
                    AnnouncementNotification::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'announcement_id' => $announcementId,
                        ],
                        ['read_at' => now()]
                    );
                }
            }

            if (Schema::hasTable('event_notification_reads') && Schema::hasTable('event_notifications')) {
                $eventNotificationIds = EventNotification::latest()->limit(50)->pluck('id');

                foreach ($eventNotificationIds as $eventNotificationId) {
                    EventNotificationRead::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'event_notification_id' => $eventNotificationId,
                        ],
                        ['read_at' => now()]
                    );
                }
            }

            // Mark all access request response notifications as read (for users)
            if (Schema::hasTable('access_request_response_notifications')) {
                AccessRequestResponseNotification::where('user_id', $user->id)
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);
            }

            $roles = is_array($user->role) ? $user->role : [$user->role];
            $isAdmin = in_array('admin', $roles);
            $isModerator = in_array('moderateur', $roles);
            $isCoach = in_array('coach', $roles);

            // Mark pending access-request notifications as read when user explicitly taps Mark all
            if (Schema::hasTable('access_request_notifications') && ($isAdmin || $isModerator)) {
                AccessRequestNotification::whereNull('read_at')
                    ->update(['read_at' => now()]);
            }

            // Mark all discipline notifications as read
            if ($isAdmin || $isModerator) {
                // Admins/Moderators mark all discipline notifications as read
                DisciplineNotification::whereNull('read_at')
                    ->update(['read_at' => now()]);
            } elseif ($isCoach) {
                // Coaches mark discipline notifications for their students as read
                $coachFormations = Formation::where('user_id', $user->id)->pluck('id');
                $studentIds = User::whereIn('formation_id', $coachFormations)->pluck('id');
                DisciplineNotification::whereIn('user_id', $studentIds)
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);
            } else {
                // Regular users mark their own discipline notifications as read
                DisciplineNotification::where('user_id', $user->id)
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);
            }

            // Mark all attendance reminder notifications as read (owning student only)
            if (Schema::hasTable('attendance_reminder_notifications')) {
                AttendanceReminderNotification::where('user_id', $user->id)
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);
            }

            // Dismiss synthetic pending reservation / appointment inbox items
            if (Schema::hasTable('reservations')) {
                $pendingReservationIds = DB::table('reservations')
                    ->where('canceled', 0)
                    ->where('approved', 0)
                    ->orderByDesc('created_at')
                    ->limit(50)
                    ->pluck('id');
                foreach ($pendingReservationIds as $reservationId) {
                    $this->dismissSyntheticNotification((int) $user->id, 'reservation', (int) $reservationId);
                }
            }

            if (Schema::hasTable('appointments')) {
                $userEmail = strtolower($user->email ?? '');
                if ($userEmail) {
                    $pendingAppointmentIds = DB::table('appointments')
                        ->whereRaw('LOWER(person_email) = ?', [$userEmail])
                        ->where('status', 'pending')
                        ->orderByDesc('created_at')
                        ->limit(50)
                        ->pluck('id');
                    foreach ($pendingAppointmentIds as $appointmentId) {
                        $this->dismissSyntheticNotification((int) $user->id, 'appointment', (int) $appointmentId);
                    }
                }
            }

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('Failed to mark all notifications as read: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to mark all as read'], 500);
        }
    }

    private function syntheticNotificationDismissKey(int $userId, string $type, int $id): string
    {
        return "notif_dismissed:{$userId}:{$type}:{$id}";
    }

    private function dismissSyntheticNotification(int $userId, string $type, int $id): void
    {
        if ($id < 1) {
            return;
        }
        Cache::put($this->syntheticNotificationDismissKey($userId, $type, $id), true, now()->addDays(60));
    }

    private function isSyntheticNotificationDismissed(int $userId, string $type, int $id): bool
    {
        return Cache::has($this->syntheticNotificationDismissKey($userId, $type, $id));
    }

    /**
     * Get Ably token for real-time notifications
     */
    public function getAblyToken(Request $request)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $ablyKey = config('services.ably.key');
            if (!$ablyKey) {
                return response()->json(['error' => 'Ably not configured'], 500);
            }

            // Generate token request for notifications channel
            $tokenRequest = [
                'capability' => json_encode([
                    "notifications:{$user->id}" => ['subscribe'], // Only subscribe to own notifications
                ]),
                'clientId' => (string) $user->id,
            ];

            // Use Ably REST client to create token request
            $ably = new AblyRest($ablyKey);
            $tokenDetails = $ably->auth->requestToken($tokenRequest);

            return response()->json([
                'token' => $tokenDetails->token,
                'channelName' => "notifications:{$user->id}",
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate Ably token for notifications: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to generate token'], 500);
        }
    }
}

