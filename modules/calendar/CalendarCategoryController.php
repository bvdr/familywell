<?php

namespace Modules\Calendar;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A family's own event categories (design C14). Adding is open to every member: "New category" sits inside the event
 * sheet, so anyone who can add an event must be able to give it a category without asking an admin first. Renaming
 * and deleting change what everyone's filter chips show, so those are admin-only, like spending's categories.
 * Route binding runs before the family scope, so the row is resolved through the scoped model (404 across families).
 */
class CalendarCategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        CalendarCategory::add(CalendarCategory::validated($request->all()));

        return back();
    }

    public function update(Request $request, int $category): RedirectResponse
    {
        $this->authorizeAdmin();
        $row = CalendarCategory::findOrFail($category);
        $row->change(CalendarCategory::validated($request->all(), $row->id));

        return back();
    }

    public function destroy(int $category): RedirectResponse
    {
        $this->authorizeAdmin();
        CalendarCategory::findOrFail($category)->remove();

        return back();
    }

    private function authorizeAdmin(): void
    {
        $this->authorize('manageMembers', Family::findOrFail(CurrentFamily::id()));
    }
}
