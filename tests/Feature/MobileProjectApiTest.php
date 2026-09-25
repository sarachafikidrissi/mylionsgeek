<?php

use App\Models\Project;
use App\Models\ProjectMessage;
use App\Models\ProjectUser;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Migrated sqlite still FKs project_users.project_id to student_projects
    // after that table was renamed. Recreate without that FK for these tests.
    Schema::dropIfExists('project_users');
    Schema::create('project_users', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('project_id');
        $table->unsignedBigInteger('user_id');
        $table->string('role')->default('member');
        $table->timestamp('invited_at')->nullable();
        $table->timestamp('joined_at')->nullable();
        $table->timestamps();
    });
});

function mobileProjectUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => ['coach'],
        'status' => 'Studying',
        'email_verified_at' => now(),
        'account_state' => 0,
    ], $overrides));
}

function mobileProject(User $owner, string $name = 'Mobile Project'): Project
{
    return Project::query()->create([
        'name' => $name,
        'description' => 'A collaborative project',
        'status' => 'active',
        'created_by' => $owner->id,
        'is_updated' => false,
        'last_activity' => now(),
    ]);
}

function attachProjectMember(Project $project, User $user, string $role = 'member'): void
{
    ProjectUser::query()->create([
        'project_id' => $project->id,
        'user_id' => $user->id,
        'role' => $role,
        'joined_at' => now(),
    ]);
}

test('unauthenticated cannot list mobile projects', function () {
    $this->getJson('/api/mobile/projects')->assertUnauthorized();
});

test('member can list their projects with progress', function () {
    $owner = mobileProjectUser();
    $member = mobileProjectUser(['role' => ['pro']]);
    $stranger = mobileProjectUser();

    $project = mobileProject($owner, 'Shared Build');
    attachProjectMember($project, $owner, 'owner');
    attachProjectMember($project, $member, 'member');

    Task::query()->create([
        'title' => 'Done task',
        'status' => 'completed',
        'priority' => 'medium',
        'project_id' => $project->id,
        'created_by' => $owner->id,
        'assigned_to' => $member->id,
    ]);
    Task::query()->create([
        'title' => 'Open task',
        'status' => 'todo',
        'priority' => 'high',
        'project_id' => $project->id,
        'created_by' => $owner->id,
        'assigned_to' => $member->id,
    ]);

    $other = mobileProject($stranger, 'Secret');
    attachProjectMember($other, $stranger, 'owner');

    $this->actingAs($member, 'sanctum')
        ->getJson('/api/mobile/projects')
        ->assertOk()
        ->assertJsonCount(1, 'projects')
        ->assertJsonPath('projects.0.name', 'Shared Build')
        ->assertJsonPath('projects.0.tasks_count', 2)
        ->assertJsonPath('projects.0.completed_tasks_count', 1)
        ->assertJsonPath('projects.0.progress_percentage', 50)
        ->assertJsonPath('projects.0.my_role', 'member');
});

test('non member cannot view project detail', function () {
    $owner = mobileProjectUser();
    $stranger = mobileProjectUser();
    $project = mobileProject($owner);
    attachProjectMember($project, $owner, 'owner');

    $this->actingAs($stranger, 'sanctum')
        ->getJson("/api/mobile/projects/{$project->id}")
        ->assertNotFound();
});

test('member can view project detail with team and tasks', function () {
    $owner = mobileProjectUser(['name' => 'Owner']);
    $member = mobileProjectUser(['name' => 'Member', 'role' => ['pro']]);
    $project = mobileProject($owner);
    attachProjectMember($project, $owner, 'owner');
    attachProjectMember($project, $member, 'member');

    Task::query()->create([
        'title' => 'Ship mobile',
        'status' => 'in_progress',
        'priority' => 'urgent',
        'project_id' => $project->id,
        'created_by' => $owner->id,
        'assigned_to' => $member->id,
    ]);

    $this->actingAs($member, 'sanctum')
        ->getJson("/api/mobile/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('project.name', $project->name)
        ->assertJsonPath('project.can_manage_tasks', false)
        ->assertJsonCount(2, 'team')
        ->assertJsonCount(1, 'tasks')
        ->assertJsonPath('tasks.0.title', 'Ship mobile')
        ->assertJsonPath('tasks.0.can_update_status', true);
});

test('assignee can advance task status but not complete without admin', function () {
    $owner = mobileProjectUser();
    $member = mobileProjectUser(['role' => ['pro']]);
    $project = mobileProject($owner);
    attachProjectMember($project, $owner, 'owner');
    attachProjectMember($project, $member, 'member');

    $task = Task::query()->create([
        'title' => 'Write API',
        'status' => 'todo',
        'priority' => 'medium',
        'project_id' => $project->id,
        'created_by' => $owner->id,
        'assigned_to' => $member->id,
    ]);

    $this->actingAs($member, 'sanctum')
        ->postJson("/api/mobile/projects/{$project->id}/tasks/{$task->id}/status", [
            'status' => 'in_progress',
        ])
        ->assertOk()
        ->assertJsonPath('task.status', 'in_progress');

    $this->actingAs($member, 'sanctum')
        ->postJson("/api/mobile/projects/{$project->id}/tasks/{$task->id}/status", [
            'status' => 'completed',
        ])
        ->assertForbidden();
});

test('project owner can complete task and members can chat', function () {
    $owner = mobileProjectUser();
    $member = mobileProjectUser(['role' => ['coach']]);
    $project = mobileProject($owner);
    attachProjectMember($project, $owner, 'owner');
    attachProjectMember($project, $member, 'member');

    $task = Task::query()->create([
        'title' => 'Review PR',
        'status' => 'review',
        'priority' => 'high',
        'project_id' => $project->id,
        'created_by' => $owner->id,
        'assigned_to' => $member->id,
        'subtasks' => [],
    ]);

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/mobile/projects/{$project->id}/tasks/{$task->id}/status", [
            'status' => 'completed',
        ])
        ->assertOk()
        ->assertJsonPath('task.status', 'completed');

    $this->actingAs($member, 'sanctum')
        ->postJson("/api/mobile/projects/{$project->id}/messages", [
            'content' => 'Looks good on mobile',
        ])
        ->assertCreated()
        ->assertJsonPath('message.content', 'Looks good on mobile');

    $this->actingAs($owner, 'sanctum')
        ->getJson("/api/mobile/projects/{$project->id}/messages")
        ->assertOk()
        ->assertJsonCount(1, 'messages');

    expect(ProjectMessage::query()->where('project_id', $project->id)->count())->toBe(1);
});

test('staff can create update and delete a project', function () {
    $admin = mobileProjectUser(['role' => ['admin']]);
    $student = mobileProjectUser(['role' => ['student']]);

    $create = $this->actingAs($admin, 'sanctum')
        ->postJson('/api/mobile/projects', [
            'name' => 'Mobile Campaign',
            'description' => 'Full CRUD',
            'status' => 'active',
            'predefined_tasks' => ['creation_du_site_web'],
        ])
        ->assertCreated()
        ->assertJsonPath('project.name', 'Mobile Campaign');

    $projectId = $create->json('project.id');
    expect(Task::query()->where('project_id', $projectId)->count())->toBe(1);

    $this->actingAs($student, 'sanctum')
        ->postJson('/api/mobile/projects', ['name' => 'Nope'])
        ->assertForbidden();

    $this->actingAs($admin, 'sanctum')
        ->postJson("/api/mobile/projects/{$projectId}", [
            'name' => 'Mobile Campaign v2',
            'status' => 'on_hold',
        ])
        ->assertOk()
        ->assertJsonPath('project.name', 'Mobile Campaign v2')
        ->assertJsonPath('project.status', 'on_hold');

    $this->actingAs($admin, 'sanctum')
        ->deleteJson("/api/mobile/projects/{$projectId}")
        ->assertOk();

    expect(Project::query()->find($projectId))->toBeNull();
});

test('owner can assign tasks manage team and notes', function () {
    $owner = mobileProjectUser(['role' => ['admin']]);
    $member = mobileProjectUser(['role' => ['pro'], 'email' => 'member@example.com']);
    $project = mobileProject($owner);
    attachProjectMember($project, $owner, 'owner');

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/mobile/projects/{$project->id}/members", [
            'user_id' => $member->id,
            'role' => 'member',
        ])
        ->assertOk();

    expect(
        ProjectUser::query()
            ->where('project_id', $project->id)
            ->where('user_id', $member->id)
            ->where('role', 'member')
            ->exists()
    )->toBeTrue();

    $taskCreate = $this->actingAs($owner, 'sanctum')
        ->postJson("/api/mobile/projects/{$project->id}/tasks", [
            'title' => 'Design landing',
            'priority' => 'high',
            'assigned_to' => $member->id,
            'status' => 'todo',
        ])
        ->assertCreated()
        ->assertJsonPath('task.title', 'Design landing')
        ->assertJsonPath('task.assigned_to.id', $member->id);

    $taskId = $taskCreate->json('task.id');

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/mobile/projects/{$project->id}/tasks/{$taskId}", [
            'title' => 'Design landing page',
            'status' => 'in_progress',
        ])
        ->assertOk()
        ->assertJsonPath('task.title', 'Design landing page')
        ->assertJsonPath('task.status', 'in_progress');

    $this->actingAs($owner, 'sanctum')
        ->putJson("/api/mobile/projects/{$project->id}/members/{$member->id}", [
            'role' => 'admin',
        ])
        ->assertOk()
        ->assertJsonPath('role', 'admin');

    $this->actingAs($owner, 'sanctum')
        ->postJson("/api/mobile/projects/{$project->id}/notes", [
            'title' => 'Kickoff',
            'content' => 'Ship MVP this sprint',
        ])
        ->assertCreated()
        ->assertJsonPath('note.title', 'Kickoff');

    $this->actingAs($owner, 'sanctum')
        ->getJson("/api/mobile/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('project.can_manage_team', true)
        ->assertJsonCount(1, 'notes')
        ->assertJsonPath('notes.0.title', 'Kickoff');

    $this->actingAs($owner, 'sanctum')
        ->deleteJson("/api/mobile/projects/{$project->id}/tasks/{$taskId}")
        ->assertOk();

    expect(Task::query()->whereKey($taskId)->exists())->toBeFalse();
});

test('non staff cannot create projects', function () {
    $student = mobileProjectUser(['role' => ['student']]);

    $this->actingAs($student, 'sanctum')
        ->postJson('/api/mobile/projects', ['name' => 'Student project'])
        ->assertForbidden();
});

test('non member cannot mutate project and member cannot manage team', function () {
    $owner = mobileProjectUser(['role' => ['admin']]);
    $member = mobileProjectUser(['role' => ['pro']]);
    $stranger = mobileProjectUser(['role' => ['coach']]);
    $project = mobileProject($owner);
    attachProjectMember($project, $owner, 'owner');
    attachProjectMember($project, $member, 'member');

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/mobile/projects/{$project->id}/tasks", [
            'title' => 'Intrusion',
        ])
        ->assertNotFound();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/mobile/projects/{$project->id}", [
            'name' => 'Hijacked',
        ])
        ->assertForbidden();

    $this->actingAs($member, 'sanctum')
        ->postJson("/api/mobile/projects/{$project->id}/members", [
            'user_id' => $stranger->id,
            'role' => 'member',
        ])
        ->assertForbidden();
});

test('attachment task_id must belong to the same project', function () {
    Storage::fake('attachments');

    $owner = mobileProjectUser(['role' => ['admin']]);
    $otherOwner = mobileProjectUser(['role' => ['coach']]);
    $project = mobileProject($owner);
    $other = mobileProject($otherOwner, 'Other project');
    attachProjectMember($project, $owner, 'owner');
    attachProjectMember($other, $otherOwner, 'owner');

    $foreignTask = Task::query()->create([
        'title' => 'Foreign task',
        'status' => 'todo',
        'priority' => 'medium',
        'project_id' => $other->id,
        'created_by' => $otherOwner->id,
    ]);

    $this->actingAs($owner, 'sanctum')
        ->post("/api/mobile/projects/{$project->id}/attachments", [
            'file' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            'task_id' => $foreignTask->id,
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'task_id must belong to this project.');
});
