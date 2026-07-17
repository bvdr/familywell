<?php

namespace Modules\Todos;

use App\Events\TodosChanged;
use App\Http\Controllers\Controller;
use App\Support\Realtime;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\Request;

class TodosController extends Controller
{
    public function index(Request $request)
    {
        return TodoItem::visibleTo($request->user()->id)->latest()->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'visibility' => ['required', 'in:shared,private'],
        ]);

        $item = TodoItem::create([
            'title' => $data['title'],
            'visibility' => $data['visibility'],
            'created_by' => $request->user()->id,
        ]);
        Realtime::broadcast(new TodosChanged((int) CurrentFamily::id()));

        return $item;
    }

    public function update(Request $request, TodoItem $item)
    {
        abort_unless($item->visibility === 'shared' || $item->created_by === $request->user()->id, 403);
        $item->update(['done' => ! $item->done]);
        Realtime::broadcast(new TodosChanged((int) CurrentFamily::id()));

        return $item;
    }

    public function destroy(Request $request, TodoItem $item)
    {
        abort_unless($item->created_by === $request->user()->id, 403);
        $item->delete();
        Realtime::broadcast(new TodosChanged((int) CurrentFamily::id()));

        return response()->json(['ok' => true]);
    }
}
