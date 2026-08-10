<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Sharing\ShareableRegistry;
use Modules\Notifications\Notify;
use App\Support\Sharing\ShareAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

// Generic management for any shareable entity, addressed as {type}/{id}. Only the entity's owner may
// change visibility, member roles, or links. External (non-family) grants are out of scope for now,
// so member targets must belong to the entity's family.
class ShareController extends Controller
{
    public function __construct(private ShareAccess $access) {}

    public function visibility(Request $request, string $type, int $id)
    {
        $entity = $this->manageable($type, $id);
        $entity->setVisibility($request->validate(['visibility' => 'required|in:family,invited,private'])['visibility']);

        return back();
    }

    public function putMember(Request $request, string $type, int $id, User $user)
    {
        $entity = $this->manageable($type, $id);
        $role = $request->validate(['role' => 'required|in:editor,viewer'])['role'];

        // Can't reassign the owner, and can only grant to members of the entity's family for now.
        abort_if($this->access->roleFor($user, $entity) === 'owner', 422, 'Cannot change the owner.');
        abort_unless($user->families()->whereKey($entity->sharingFamilyId())->exists(), 422, 'User is not in this family.');

        $share = $entity->shares()->updateOrCreate(['user_id' => $user->id], ['role' => $role, 'invited_by' => $request->user()->id]);

        // Push the newly-granted person (not on a mere role change, not yourself), if they allow it.
        if ($share->wasRecentlyCreated) {
            $name = $entity->name ?? 'something';
            Notify::push([$user], 'sharing', $request->user()->id, "{$request->user()->name} shared with you", $name, self::entityUrl($type, $id));
        }

        return back();
    }

    // Deep link to a shared entity by its share type. Falls back to the dashboard for unknown types.
    private static function entityUrl(string $type, int $id): string
    {
        return match ($type) {
            'project' => "/m/projects/{$id}",
            'shopping_list' => "/m/shopping/lists/{$id}",
            'task_list' => "/m/tasks/lists/{$id}",
            'spend_event' => "/m/spending/events/{$id}",
            default => '/dashboard',
        };
    }

    public function removeMember(string $type, int $id, User $user)
    {
        $entity = $this->manageable($type, $id);
        abort_if($this->access->roleFor($user, $entity) === 'owner', 422, 'Cannot remove the owner.');
        $entity->shares()->where('user_id', $user->id)->delete();

        return back();
    }

    public function createLink(Request $request, string $type, int $id)
    {
        $entity = $this->manageable($type, $id);
        // One active link is enough for now; reuse it so "Copy" is stable.
        $entity->shareLinks()->firstOrCreate([], ['role' => 'viewer', 'created_by' => $request->user()->id]);

        return back();
    }

    public function deleteLink(string $type, int $id, int $link)
    {
        $entity = $this->manageable($type, $id);
        $entity->shareLinks()->whereKey($link)->delete();

        return back();
    }

    // Resolve the entity and assert the current user owns it (the only role that may manage sharing).
    private function manageable(string $type, int $id): Model
    {
        $entity = ShareableRegistry::resolve($type, $id);
        abort_unless($this->access->canManage(request()->user(), $entity), 403);

        return $entity;
    }
}
