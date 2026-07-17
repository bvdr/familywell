<?php

namespace Modules\Shopping;

use App\Events\ShoppingChanged;
use App\Http\Controllers\Controller;
use App\Support\Realtime;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\Request;

class ShoppingController extends Controller
{
    public function index(Request $request)
    {
        return ShoppingItem::visibleTo($request->user()->id)->latest()->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'string', 'max:50'],
            'visibility' => ['required', 'in:shared,private'],
        ]);
        $item = ShoppingItem::create([
            'name' => $data['name'],
            'quantity' => $data['quantity'] ?? null,
            'visibility' => $data['visibility'],
            'created_by' => $request->user()->id,
        ]);
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return $item;
    }

    public function update(Request $request, ShoppingItem $item)
    {
        abort_unless($item->visibility === 'shared' || $item->created_by === $request->user()->id, 403);
        $item->update(['bought' => ! $item->bought]);
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return $item;
    }

    public function destroy(Request $request, ShoppingItem $item)
    {
        abort_unless($item->created_by === $request->user()->id, 403);
        $item->delete();
        Realtime::broadcast(new ShoppingChanged((int) CurrentFamily::id()));

        return response()->json(['ok' => true]);
    }
}
