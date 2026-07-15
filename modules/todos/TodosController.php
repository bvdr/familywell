<?php

namespace Modules\Todos;

use App\Http\Controllers\Controller;
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

        return TodoItem::create([
            'title' => $data['title'],
            'visibility' => $data['visibility'],
            'created_by' => $request->user()->id,
        ]);
    }

    public function update(Request $request, TodoItem $item)
    {
        abort_unless($item->visibility === 'shared' || $item->created_by === $request->user()->id, 403);
        $item->update(['done' => ! $item->done]);

        return $item;
    }

    public function destroy(Request $request, TodoItem $item)
    {
        abort_unless($item->created_by === $request->user()->id, 403);
        $item->delete();

        return response()->json(['ok' => true]);
    }
}
