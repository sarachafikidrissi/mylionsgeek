<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMessage;
use App\Models\ProjectUser;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $status = $request->query('status');
        $search = trim((string) $request->query('search', ''));

        $query = Project::query()
            ->where(function ($q) use ($user) {
                $q->where('created_by', $user->id)
                    ->orWhereHas('users', fn ($u) => $u->where('users.id', $user->id));
            })
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'completed'),
                'tasks as active_tasks_count' => fn ($q) => $q->whereIn('status', ['todo', 'in_progress', 'review']),
                'users as members_count',
            ])
            ->with(['users' => fn ($q) => $q->where('users.id', $user->id)])
            ->orderByDesc('last_activity')
            ->orderByDesc('updated_at');

        if (is_string($status) && in_array($status, ['active', 'completed', 'on_hold', 'cancelled'], true)) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%');
            });
        }

        $projects = $query->get()->map(function (Project $project) use ($user) {
            $pivotRole = $project->users->first()?->pivot?->role;
            $isOwner = (int) $project->created_by === (int) $user->id;
            $myRole = $isOwner ? 'owner' : ($pivotRole ?: 'member');
            $total = (int) $project->tasks_count;
            $completed = (int) $project->completed_tasks_count;

            return [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status ?? 'active',
                'photo' => $this->projectPhotoUrl($project->photo),
                'start_date' => optional($project->start_date)?->toDateString(),
                'end_date' => optional($project->end_date)?->toDateString(),
                'my_role' => $myRole,
                'is_owner' => $isOwner,
                'members_count' => (int) $project->members_count,
                'tasks_count' => $total,
                'active_tasks_count' => (int) $project->active_tasks_count,
                'completed_tasks_count' => $completed,
                'progress_percentage' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
                'last_activity' => optional($project->last_activity)?->toDateTimeString(),
                'created_at' => optional($project->created_at)?->toDateTimeString(),
                'updated_at' => optional($project->updated_at)?->toDateTimeString(),
            ];
        })->values()->all();

        return response()->json(['projects' => $projects]);
    }

    /**
     * Mobile project detail payload (breaking vs older thin responses).
     *
     * Returns:
     * - project: id, name, description, status, photo, dates, my_role, is_owner,
     *   can_manage_tasks, can_manage_team, can_create_projects, counts, progress, creator, timestamps
     * - team: [{ id, name, email, avatar, role, is_owner }]
     * - tasks: serialized tasks (incl. can_update_status)
     * - notes: [{ id, title, content, is_pinned, tags, color, user, created_at }]
     * - attachments: [{ id, name, mime_type, size, task_id, uploader, created_at }]
     *
     * Clients must not assume a flat project-only body or the absence of notes/attachments.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = $this->findAccessibleProject((int) $id, (int) $user->id);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $project->load([
            'creator:id,name,image',
            'tasks' => fn ($q) => $q->with(['assignedTo:id,name,image', 'creator:id,name,image'])
                ->orderByDesc('is_pinned')
                ->orderBy('sort_order')
                ->orderByDesc('updated_at'),
            'attachments',
            'notes',
        ]);

        $teamMembers = ProjectUser::with('user:id,name,image,email')
            ->where('project_id', $project->id)
            ->get()
            ->filter(fn ($row) => $row->user !== null)
            ->map(function (ProjectUser $row) use ($project) {
                $isOwner = (int) $project->created_by === (int) $row->user_id || $row->role === 'owner';

                return [
                    'id' => $row->user->id,
                    'name' => $row->user->name ?? 'Unknown',
                    'email' => $row->user->email ?? '',
                    'avatar' => $this->profileImageUrl($row->user->image),
                    'role' => $row->role ?? 'member',
                    'is_owner' => $isOwner,
                ];
            })
            ->values()
            ->all();

        // Ensure creator appears even without a pivot row.
        $memberIds = collect($teamMembers)->pluck('id')->all();
        if ($project->creator && ! in_array((int) $project->creator->id, array_map('intval', $memberIds), true)) {
            array_unshift($teamMembers, [
                'id' => $project->creator->id,
                'name' => $project->creator->name ?? 'Unknown',
                'email' => '',
                'avatar' => $this->profileImageUrl($project->creator->image),
                'role' => 'owner',
                'is_owner' => true,
            ]);
        }

        $isOwner = (int) $project->created_by === (int) $user->id;
        $pivot = ProjectUser::query()
            ->where('project_id', $project->id)
            ->where('user_id', $user->id)
            ->first();
        $myRole = $isOwner ? 'owner' : ($pivot?->role ?: 'member');
        $canManageTasks = $this->canManageProjectTasks($project, (int) $user->id);

        $tasks = $project->tasks->map(fn (Task $task) => $this->serializeTask($task, (int) $user->id, $canManageTasks))->values()->all();
        $total = count($tasks);
        $completed = collect($tasks)->where('status', 'completed')->count();

        $notes = $project->notes()
            ->with('user:id,name,image')
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($note) => [
                'id' => $note->id,
                'title' => $note->title,
                'content' => $note->content,
                'is_pinned' => (bool) $note->is_pinned,
                'tags' => is_array($note->tags) ? $note->tags : [],
                'color' => $note->color,
                'user' => $note->user ? [
                    'id' => $note->user->id,
                    'name' => $note->user->name,
                    'avatar' => $this->profileImageUrl($note->user->image),
                ] : null,
                'created_at' => optional($note->created_at)?->toDateTimeString(),
            ])
            ->values()
            ->all();

        $attachments = $project->attachments()
            ->with('uploader:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->original_name ?: $a->name,
                'mime_type' => $a->mime_type,
                'size' => $a->size,
                'task_id' => $a->task_id,
                'uploader' => $a->uploader?->name,
                'created_at' => optional($a->created_at)?->toDateTimeString(),
            ])
            ->values()
            ->all();

        return response()->json([
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status ?? 'active',
                'photo' => $this->projectPhotoUrl($project->photo),
                'start_date' => optional($project->start_date)?->toDateString(),
                'end_date' => optional($project->end_date)?->toDateString(),
                'my_role' => $myRole,
                'is_owner' => $isOwner,
                'can_manage_tasks' => $canManageTasks,
                'can_manage_team' => $isOwner,
                'can_create_projects' => $this->userIsStaff($user),
                'members_count' => count($teamMembers),
                'tasks_count' => $total,
                'completed_tasks_count' => $completed,
                'active_tasks_count' => $total - $completed,
                'progress_percentage' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
                'attachments_count' => count($attachments),
                'notes_count' => count($notes),
                'creator' => $project->creator ? [
                    'id' => $project->creator->id,
                    'name' => $project->creator->name,
                    'avatar' => $this->profileImageUrl($project->creator->image),
                ] : null,
                'created_at' => optional($project->created_at)?->toDateTimeString(),
                'updated_at' => optional($project->updated_at)?->toDateTimeString(),
            ],
            'team' => $teamMembers,
            'tasks' => $tasks,
            'notes' => $notes,
            'attachments' => $attachments,
        ]);
    }

    public function updateTaskStatus(Request $request, $projectId, $taskId): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = $this->findAccessibleProject((int) $projectId, (int) $user->id);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $task = Task::query()
            ->where('project_id', $project->id)
            ->whereKey($taskId)
            ->first();

        if (! $task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        if (! $this->canWorkOnTask($task, (int) $user->id)) {
            return response()->json(['message' => 'You cannot update this task.'], 403);
        }

        $data = $request->validate([
            'status' => 'required|in:todo,in_progress,review,completed',
        ]);

        $canManage = $this->canManageProjectTasks($project, (int) $user->id);
        if ($data['status'] === 'completed' && ! $canManage) {
            return response()->json(['message' => 'Only project admins or the owner can mark a task as completed.'], 403);
        }

        if ($data['status'] === 'completed') {
            $subtasks = $task->subtasks ?? [];
            $hasIncomplete = collect($subtasks)->contains(fn ($s) => ! ($s['completed'] ?? false));
            if ($hasIncomplete) {
                return response()->json(['message' => 'Complete all subtasks first.'], 422);
            }
        }

        $payload = ['status' => $data['status']];
        if ($data['status'] === 'in_progress' && ! $task->started_at) {
            $payload['started_at'] = now();
        }
        if ($data['status'] === 'completed' && ! $task->completed_at) {
            $payload['completed_at'] = now();
            $payload['progress'] = 100;
        }

        $task->update($payload);
        $project->update(['last_activity' => now()]);
        $task->load(['assignedTo:id,name,image', 'creator:id,name,image']);

        return response()->json([
            'task' => $this->serializeTask($task, (int) $user->id, $canManage),
        ]);
    }

    public function messages(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = $this->findAccessibleProject((int) $id, (int) $user->id);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $messages = $project->messages()
            ->with(['user:id,name,image', 'replyTo.user:id,name'])
            ->orderBy('created_at', 'asc')
            ->limit(200)
            ->get()
            ->map(fn (ProjectMessage $message) => $this->serializeMessage($message))
            ->values()
            ->all();

        return response()->json(['messages' => $messages]);
    }

    public function sendMessage(Request $request, $id): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = $this->findAccessibleProject((int) $id, (int) $user->id);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $data = $request->validate([
            'content' => 'required|string|max:5000',
            'reply_to' => 'nullable|integer|exists:project_messages,id',
        ]);

        $content = trim($data['content']);
        if ($content === '') {
            return response()->json(['message' => 'Message content is required.'], 422);
        }

        if (! empty($data['reply_to'])) {
            $replyBelongs = ProjectMessage::query()
                ->whereKey($data['reply_to'])
                ->where('project_id', $project->id)
                ->exists();
            if (! $replyBelongs) {
                return response()->json(['message' => 'Invalid reply target.'], 422);
            }
        }

        $message = ProjectMessage::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'content' => $content,
            'reply_to' => $data['reply_to'] ?? null,
        ]);

        $message->load(['user:id,name,image', 'replyTo.user:id,name']);
        $project->update(['last_activity' => now()]);

        return response()->json([
            'message' => $this->serializeMessage($message),
        ], 201);
    }

    private function findAccessibleProject(int $projectId, int $userId): ?Project
    {
        return Project::query()
            ->whereKey($projectId)
            ->where(function ($q) use ($userId) {
                $q->where('created_by', $userId)
                    ->orWhereHas('users', fn ($u) => $u->where('users.id', $userId));
            })
            ->first();
    }

    private function canManageProjectTasks(Project $project, int $userId): bool
    {
        if ((int) $project->created_by === $userId) {
            return true;
        }

        return $project->users()
            ->where('users.id', $userId)
            ->wherePivot('role', 'admin')
            ->exists();
    }

    private function canWorkOnTask(Task $task, int $userId): bool
    {
        if ($this->canManageProjectTasks($task->project, $userId)) {
            return true;
        }

        if ((int) $task->assigned_to === $userId) {
            return true;
        }

        $assignees = is_array($task->assignees) ? $task->assignees : [];

        return collect($assignees)->contains(fn ($id) => (int) $id === $userId);
    }

    public function serializeTaskPublic(Task $task, int $userId, bool $canManageTasks): array
    {
        return $this->serializeTask($task, $userId, $canManageTasks);
    }

    private function serializeTask(Task $task, int $userId, bool $canManageTasks): array
    {
        $assigneeIds = is_array($task->assignees) ? $task->assignees : [];
        $isAssignee = (int) $task->assigned_to === $userId
            || collect($assigneeIds)->contains(fn ($id) => (int) $id === $userId);

        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'priority' => $task->priority ?? 'medium',
            'status' => $task->status ?? 'todo',
            'progress' => (int) ($task->progress ?? 0),
            'is_pinned' => (bool) $task->is_pinned,
            'due_date' => optional($task->due_date)?->toDateString(),
            'subtasks' => is_array($task->subtasks) ? $task->subtasks : [],
            'tags' => is_array($task->tags) ? $task->tags : [],
            'assigned_to' => $task->assignedTo ? [
                'id' => $task->assignedTo->id,
                'name' => $task->assignedTo->name,
                'avatar' => $this->profileImageUrl($task->assignedTo->image),
            ] : null,
            'creator' => $task->creator ? [
                'id' => $task->creator->id,
                'name' => $task->creator->name,
                'avatar' => $this->profileImageUrl($task->creator->image),
            ] : null,
            'can_update_status' => $canManageTasks || $isAssignee,
            'updated_at' => optional($task->updated_at)?->toDateTimeString(),
        ];
    }

    private function serializeMessage(ProjectMessage $message): array
    {
        return [
            'id' => $message->id,
            'content' => $message->content,
            'timestamp' => optional($message->created_at)?->toISOString(),
            'reply_to' => $message->reply_to && $message->replyTo ? [
                'id' => $message->replyTo->id,
                'content' => $message->replyTo->content,
                'user' => [
                    'id' => $message->replyTo->user?->id,
                    'name' => $message->replyTo->user?->name,
                ],
            ] : null,
            'attachment_path' => $message->attachment_path ? url('storage/'.$message->attachment_path) : null,
            'attachment_type' => $message->attachment_type,
            'attachment_name' => $message->attachment_name,
            'audio_duration' => $message->audio_duration,
            'user' => [
                'id' => $message->user?->id,
                'name' => $message->user?->name,
                'avatar' => $this->profileImageUrl($message->user?->image),
            ],
        ];
    }

    private function projectPhotoUrl(?string $photo): ?string
    {
        if (! is_string($photo) || $photo === '') {
            return null;
        }
        if (str_starts_with($photo, 'http://') || str_starts_with($photo, 'https://')) {
            return $photo;
        }

        return url('storage/'.ltrim($photo, '/'));
    }

    private function profileImageUrl(?string $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        if (str_contains($path, 'storage/')) {
            return url(ltrim($path, '/'));
        }

        return url('storage/img/profile/'.ltrim($path, '/'));
    }

    private function userIsStaff($user): bool
    {
        if (! $user || ! method_exists($user, 'normalizedRoles')) {
            return false;
        }
        $roles = $user->normalizedRoles();

        return count(array_intersect($roles, ['admin', 'super_admin', 'moderateur', 'coach', 'pro'])) > 0;
    }
}
