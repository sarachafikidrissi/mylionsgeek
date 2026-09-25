<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Mail\ProjectInvitationMail;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\ProjectNote;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\TaskAssignmentNotification;
use App\Models\User;
use App\Services\ExpoPushNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Write/mutation endpoints for collaborative projects on mobile.
 * Mirrors Admin Project/Task/Note/Attachment rules (owner vs admin vs member).
 */
class MobileProjectMutationController extends Controller
{
    private const STAFF_ROLES = ['admin', 'super_admin', 'moderateur', 'coach', 'pro'];

    private const ATTACHMENT_MIMES = 'jpeg,jpg,png,webp,gif,pdf,doc,docx,xls,xlsx,ppt,pptx,mp3,mp4,webm';

    public function store(Request $request): JsonResponse
    {
        $user = $this->authUser();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }
        if (! $this->isStaff($user)) {
            return response()->json(['message' => 'Only staff can create projects.'], 403);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif|max:2048',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
            'status' => 'nullable|in:active,completed,on_hold,cancelled',
            'predefined_tasks' => 'nullable|array',
            'predefined_tasks.*' => 'string',
        ]);

        $payload = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'status' => $data['status'] ?? 'active',
            'created_by' => $user->id,
            'last_activity' => now(),
            'is_updated' => false,
        ];

        if ($request->hasFile('photo')) {
            $payload['photo'] = $request->file('photo')->store('projects', 'public');
        }

        $project = Project::create($payload);

        ProjectUser::query()->create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        $taskTitles = [
            'creation_du_site_web' => 'Creation du site web',
            'creation_de_contenue_reseaux_sociaux' => 'Creation de contenue sur les reseau sociaux',
            'shooting_images_videos' => 'Shooting and images and videos',
        ];
        foreach ($data['predefined_tasks'] ?? [] as $key) {
            if (! isset($taskTitles[$key])) {
                continue;
            }
            Task::create([
                'title' => $taskTitles[$key],
                'project_id' => $project->id,
                'created_by' => $user->id,
                'priority' => 'medium',
                'status' => 'todo',
                'progress' => 0,
                'sort_order' => 0,
            ]);
        }

        return response()->json([
            'project' => $this->projectSummary($project->fresh()),
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $user = $this->authUser();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = Project::query()->find($id);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }
        if ((int) $project->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Only the project owner can update this project.'], 403);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif|max:2048',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
            'status' => 'sometimes|required|in:active,completed,on_hold,cancelled',
        ]);

        if ($request->hasFile('photo')) {
            if ($project->photo) {
                Storage::disk('public')->delete($project->photo);
            }
            $data['photo'] = $request->file('photo')->store('projects', 'public');
        }

        $project->fill($data);
        $project->is_updated = true;
        $project->last_activity = now();
        $project->save();

        return response()->json(['project' => $this->projectSummary($project)]);
    }

    public function destroy($id): JsonResponse
    {
        $user = $this->authUser();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = Project::query()->find($id);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }
        if ((int) $project->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Only the project owner can delete this project.'], 403);
        }

        if ($project->photo) {
            Storage::disk('public')->delete($project->photo);
        }
        $project->delete();

        return response()->json(['ok' => true]);
    }

    public function addMember(Request $request, $id): JsonResponse
    {
        $user = $this->authUser();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = Project::query()->find($id);
        if (! $project || (int) $project->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Only the project owner can manage the team.'], 403);
        }

        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'required|in:admin,member',
        ]);

        $memberId = (int) $data['user_id'];
        if ($memberId === (int) $project->created_by) {
            return response()->json(['message' => 'Cannot change the project owner via this endpoint.'], 422);
        }

        $existing = ProjectUser::query()
            ->where('project_id', $project->id)
            ->where('user_id', $memberId)
            ->first();

        if ($existing) {
            $existing->update(['role' => $data['role']]);
        } else {
            ProjectUser::query()->create([
                'project_id' => $project->id,
                'user_id' => $memberId,
                'role' => $data['role'],
                'joined_at' => now(),
            ]);
        }

        $project->update(['last_activity' => now()]);

        return response()->json(['ok' => true]);
    }

    public function invite(Request $request, $id): JsonResponse
    {
        $user = $this->authUser();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = Project::query()->find($id);
        if (! $project || (int) $project->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Only the project owner can invite members.'], 403);
        }

        $data = $request->validate([
            'email' => 'required|email',
            'role' => 'required|in:admin,member',
            'message' => 'nullable|string|max:500',
        ]);

        $invitation = ProjectInvitation::createInvitation(
            $project->id,
            $data['email'],
            null,
            $data['role'],
            $data['message'] ?? null
        );

        try {
            Mail::to($data['email'])->send(new ProjectInvitationMail(
                $project,
                $invitation,
                $data['message'] ?? null
            ));
        } catch (\Throwable $e) {
            Log::warning('Project invitation mail failed', ['error' => $e->getMessage()]);
        }

        // If invitee already has an account, attach immediately for mobile UX.
        $invitee = User::query()->where('email', $data['email'])->first();
        if ($invitee && (int) $invitee->id !== (int) $project->created_by) {
            ProjectUser::query()->updateOrCreate(
                ['project_id' => $project->id, 'user_id' => $invitee->id],
                ['role' => $data['role'], 'joined_at' => now()]
            );
            $invitation->update(['is_used' => true]);
        }

        $project->update(['last_activity' => now()]);

        return response()->json([
            'ok' => true,
            'invitation_id' => $invitation->id,
            'attached' => (bool) $invitee,
        ], 201);
    }

    public function removeMember($id, $userId): JsonResponse
    {
        $user = $this->authUser();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = Project::query()->find($id);
        if (! $project || (int) $project->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Only the project owner can remove members.'], 403);
        }

        if ((int) $userId === (int) $project->created_by) {
            return response()->json(['message' => 'Cannot remove the project owner.'], 422);
        }

        $row = ProjectUser::query()
            ->where('project_id', $project->id)
            ->where('user_id', $userId)
            ->first();

        if (! $row) {
            return response()->json(['message' => 'Member not found.'], 404);
        }
        if ($row->role === 'owner') {
            return response()->json(['message' => 'Cannot remove an owner.'], 422);
        }

        $row->delete();
        $project->update(['last_activity' => now()]);

        return response()->json(['ok' => true]);
    }

    public function updateMemberRole(Request $request, $id, $userId): JsonResponse
    {
        $user = $this->authUser();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = Project::query()->find($id);
        if (! $project || (int) $project->created_by !== (int) $user->id) {
            return response()->json(['message' => 'Only the project owner can change roles.'], 403);
        }

        if ((int) $userId === (int) $project->created_by) {
            return response()->json(['message' => 'Cannot change the owner role.'], 422);
        }

        $data = $request->validate([
            'role' => 'required|in:admin,member',
        ]);

        $row = ProjectUser::query()
            ->where('project_id', $project->id)
            ->where('user_id', $userId)
            ->first();

        if (! $row || $row->role === 'owner') {
            return response()->json(['message' => 'Member not found.'], 404);
        }

        $row->update(['role' => $data['role']]);

        return response()->json(['ok' => true, 'role' => $data['role']]);
    }

    public function storeTask(Request $request, $id): JsonResponse
    {
        $user = $this->authUser();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = $this->findAccessible($id, (int) $user->id);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'status' => 'nullable|in:todo,in_progress,review,completed',
            'assigned_to' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
            'progress' => 'nullable|integer|min:0|max:100',
        ]);

        $canManage = $this->canManageTasks($project, (int) $user->id);
        if (! $canManage) {
            $data['assigned_to'] = $user->id;
            if (($data['status'] ?? null) === 'completed') {
                $data['status'] = 'review';
            }
        }

        if (! empty($data['assigned_to']) && ! $this->isProjectMember($project, (int) $data['assigned_to'])) {
            return response()->json(['message' => 'Assigned user must be a project member.'], 422);
        }

        $task = Task::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? 'medium',
            'status' => $data['status'] ?? 'todo',
            'project_id' => $project->id,
            'created_by' => $user->id,
            'assigned_to' => $data['assigned_to'] ?? null,
            'assignees' => [],
            'due_date' => $data['due_date'] ?? null,
            'progress' => $data['progress'] ?? 0,
            'is_pinned' => false,
            'is_editable' => true,
            'subtasks' => [],
            'tags' => [],
            'comments' => [],
        ]);

        if ($task->assigned_to && (int) $task->assigned_to !== (int) $user->id) {
            $this->notifyAssignment($task, (int) $task->assigned_to, (int) $user->id);
        }

        $project->update(['last_activity' => now(), 'is_updated' => true]);
        $task->load(['assignedTo:id,name,image', 'creator:id,name,image']);

        return response()->json([
            'task' => app(ProjectController::class)->serializeTaskPublic($task, (int) $user->id, $canManage),
        ], 201);
    }

    public function updateTask(Request $request, $projectId, $taskId): JsonResponse
    {
        $user = $this->authUser();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = $this->findAccessible($projectId, (int) $user->id);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $task = Task::query()->where('project_id', $project->id)->whereKey($taskId)->first();
        if (! $task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $canManage = $this->canManageTasks($project, (int) $user->id);
        if (! $canManage && ! $this->canWorkOnTask($task, (int) $user->id)) {
            return response()->json(['message' => 'You cannot update this task.'], 403);
        }

        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|in:low,medium,high,urgent',
            'status' => 'nullable|in:todo,in_progress,review,completed',
            'assigned_to' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
            'progress' => 'nullable|integer|min:0|max:100',
            'is_pinned' => 'nullable|boolean',
            'subtasks' => 'nullable|array',
        ]);

        if (! $canManage) {
            unset($data['assigned_to'], $data['is_pinned']);
            if (($data['status'] ?? null) === 'completed') {
                return response()->json(['message' => 'Only project admins or the owner can mark a task as completed.'], 403);
            }
        }

        if (array_key_exists('assigned_to', $data) && $data['assigned_to'] && ! $this->isProjectMember($project, (int) $data['assigned_to'])) {
            return response()->json(['message' => 'Assigned user must be a project member.'], 422);
        }

        $previousAssignee = $task->assigned_to ? (int) $task->assigned_to : null;
        $task->fill($data);
        if (($data['status'] ?? null) === 'in_progress' && ! $task->started_at) {
            $task->started_at = now();
        }
        if (($data['status'] ?? null) === 'completed') {
            $task->completed_at = $task->completed_at ?: now();
            $task->progress = 100;
        }
        $task->save();

        $newAssignee = $task->assigned_to ? (int) $task->assigned_to : null;
        if ($newAssignee && $newAssignee !== $previousAssignee && $newAssignee !== (int) $user->id) {
            $this->notifyAssignment($task, $newAssignee, (int) $user->id);
        }

        $project->update(['last_activity' => now(), 'is_updated' => true]);
        $task->load(['assignedTo:id,name,image', 'creator:id,name,image']);

        return response()->json([
            'task' => app(ProjectController::class)->serializeTaskPublic($task, (int) $user->id, $canManage),
        ]);
    }

    public function destroyTask($projectId, $taskId): JsonResponse
    {
        $user = $this->authUser();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = $this->findAccessible($projectId, (int) $user->id);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $task = Task::query()->where('project_id', $project->id)->whereKey($taskId)->first();
        if (! $task) {
            return response()->json(['message' => 'Task not found'], 404);
        }

        $canManage = $this->canManageTasks($project, (int) $user->id);
        if (! $canManage && (int) $task->created_by !== (int) $user->id) {
            return response()->json(['message' => 'You can only delete tasks you created.'], 403);
        }

        $task->delete();
        $project->update(['last_activity' => now(), 'is_updated' => true]);

        return response()->json(['ok' => true]);
    }

    public function storeNote(Request $request, $id): JsonResponse
    {
        $user = $this->authUser();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = $this->findAccessible($id, (int) $user->id);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'is_pinned' => 'nullable|boolean',
            'tags' => 'nullable|array',
            'color' => 'nullable|string',
        ]);

        $color = $data['color'] ?? ProjectNote::DEFAULT_COLOR;
        if (! in_array($color, ProjectNote::ALLOWED_COLORS, true)) {
            $color = ProjectNote::DEFAULT_COLOR;
        }

        $note = new ProjectNote([
            'title' => $data['title'],
            'content' => $data['content'],
            'is_pinned' => (bool) ($data['is_pinned'] ?? false),
            'tags' => $data['tags'] ?? [],
            'color' => $color,
        ]);
        $note->project_id = $project->id;
        $note->user_id = $user->id;
        $note->save();

        return response()->json(['note' => $this->serializeNote($note->load('user:id,name,image'))], 201);
    }

    public function updateNote(Request $request, $id, $noteId): JsonResponse
    {
        $user = $this->authUser();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = $this->findAccessible($id, (int) $user->id);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $note = ProjectNote::query()->where('project_id', $project->id)->whereKey($noteId)->first();
        if (! $note) {
            return response()->json(['message' => 'Note not found'], 404);
        }

        $data = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string',
            'is_pinned' => 'nullable|boolean',
            'tags' => 'nullable|array',
            'color' => 'nullable|string',
        ]);

        if (isset($data['color']) && ! in_array($data['color'], ProjectNote::ALLOWED_COLORS, true)) {
            unset($data['color']);
        }

        $note->fill($data);
        $note->save();

        return response()->json(['note' => $this->serializeNote($note->load('user:id,name,image'))]);
    }

    public function destroyNote($id, $noteId): JsonResponse
    {
        $user = $this->authUser();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = $this->findAccessible($id, (int) $user->id);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $note = ProjectNote::query()->where('project_id', $project->id)->whereKey($noteId)->first();
        if (! $note) {
            return response()->json(['message' => 'Note not found'], 404);
        }

        $note->delete();

        return response()->json(['ok' => true]);
    }

    public function storeAttachment(Request $request, $id): JsonResponse
    {
        $user = $this->authUser();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = $this->findAccessible($id, (int) $user->id);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $request->validate([
            'file' => 'required|file|mimes:'.self::ATTACHMENT_MIMES.'|max:10240',
            'task_id' => 'nullable|integer',
        ]);

        $taskId = $request->input('task_id');
        if ($taskId !== null && $taskId !== '') {
            $taskId = (int) $taskId;
            $belongsToProject = Task::query()
                ->where('project_id', $project->id)
                ->whereKey($taskId)
                ->exists();
            if (! $belongsToProject) {
                return response()->json([
                    'message' => 'task_id must belong to this project.',
                ], 422);
            }
        } else {
            $taskId = null;
        }

        $file = $request->file('file');
        $path = $file->store('attachments', 'attachments');

        $attachment = Attachment::create([
            'name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'project_id' => $project->id,
            'task_id' => $taskId,
            'uploaded_by' => $user->id,
        ]);

        $project->update(['last_activity' => now(), 'is_updated' => true]);

        return response()->json(['attachment' => $this->serializeAttachment($attachment)], 201);
    }

    public function destroyAttachment($id, $attachmentId): JsonResponse
    {
        $user = $this->authUser();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $project = $this->findAccessible($id, (int) $user->id);
        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $attachment = Attachment::query()
            ->where('project_id', $project->id)
            ->whereKey($attachmentId)
            ->first();

        if (! $attachment) {
            return response()->json(['message' => 'Attachment not found'], 404);
        }

        try {
            Storage::disk('attachments')->delete($attachment->path);
        } catch (\Throwable $e) {
            // ignore missing disk file
        }
        $attachment->delete();

        return response()->json(['ok' => true]);
    }

    private function authUser(): ?User
    {
        return Auth::guard('sanctum')->user();
    }

    private function isStaff(User $user): bool
    {
        $roles = method_exists($user, 'normalizedRoles') ? $user->normalizedRoles() : [];

        return count(array_intersect($roles, self::STAFF_ROLES)) > 0;
    }

    private function findAccessible($id, int $userId): ?Project
    {
        return Project::query()
            ->whereKey($id)
            ->where(function ($q) use ($userId) {
                $q->where('created_by', $userId)
                    ->orWhereHas('users', fn ($u) => $u->where('users.id', $userId));
            })
            ->first();
    }

    private function canManageTasks(Project $project, int $userId): bool
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
        if ($this->canManageTasks($task->project, $userId)) {
            return true;
        }
        if ((int) $task->assigned_to === $userId) {
            return true;
        }
        $assignees = is_array($task->assignees) ? $task->assignees : [];

        return collect($assignees)->contains(fn ($id) => (int) $id === $userId);
    }

    private function isProjectMember(Project $project, int $userId): bool
    {
        if ((int) $project->created_by === $userId) {
            return true;
        }

        return $project->users()->where('users.id', $userId)->exists();
    }

    private function projectSummary(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status ?? 'active',
            'photo' => $project->photo
                ? (str_starts_with($project->photo, 'http') ? $project->photo : url('storage/'.ltrim($project->photo, '/')))
                : null,
            'start_date' => optional($project->start_date)?->toDateString(),
            'end_date' => optional($project->end_date)?->toDateString(),
            'is_owner' => true,
            'my_role' => 'owner',
        ];
    }

    private function serializeNote(ProjectNote $note): array
    {
        return [
            'id' => $note->id,
            'title' => $note->title,
            'content' => $note->content,
            'is_pinned' => (bool) $note->is_pinned,
            'tags' => is_array($note->tags) ? $note->tags : [],
            'color' => $note->color,
            'user' => $note->user ? [
                'id' => $note->user->id,
                'name' => $note->user->name,
            ] : null,
            'created_at' => optional($note->created_at)?->toDateTimeString(),
            'updated_at' => optional($note->updated_at)?->toDateTimeString(),
        ];
    }

    private function serializeAttachment(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name ?: $attachment->name,
            'mime_type' => $attachment->mime_type,
            'size' => $attachment->size,
            'task_id' => $attachment->task_id,
            'created_at' => optional($attachment->created_at)?->toDateTimeString(),
        ];
    }

    private function notifyAssignment(Task $task, int $assignedTo, int $assignedBy): void
    {
        try {
            $to = User::query()->find($assignedTo);
            $by = User::query()->find($assignedBy);
            if (! $to || ! $by) {
                return;
            }
            $project = $task->project;
            $message = "{$by->name} assigned you the task \"{$task->title}\"";
            if ($project) {
                $message .= " in project \"{$project->name}\"";
            }

            TaskAssignmentNotification::create([
                'task_id' => $task->id,
                'assigned_to_user_id' => $assignedTo,
                'assigned_by_user_id' => $assignedBy,
                'message_notification' => $message,
                'path' => "/admin/projects/{$task->project_id}?task={$task->id}",
            ]);

            if ($to->expo_push_token) {
                app(ExpoPushNotificationService::class)->sendToUser(
                    $to,
                    'Task assigned',
                    $message,
                    [
                        'type' => 'task_assignment',
                        'project_id' => $task->project_id,
                        'task_id' => $task->id,
                    ]
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Mobile task assignment notify failed', ['error' => $e->getMessage()]);
        }
    }
}
