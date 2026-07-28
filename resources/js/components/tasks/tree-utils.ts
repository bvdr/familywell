import { arrayMove } from '@dnd-kit/sortable';

import type { SubTask, TaskNode } from './task-tree';

// A task flattened out of the top-level/sub-task tree for drag-and-drop. Depth is 0 (top-level) or 1
// (sub-task) only — the module allows a single level of nesting. `hasChildren` marks a top-level task
// that owns sub-tasks; it can be reordered but never nested (that would make a second level).
export type FlatTask = SubTask & {
    depth: number;
    parentId: number | null;
    hasChildren: boolean;
};

export const INDENT = 28; // px of indentation per depth level
const MAX_DEPTH = 1;

export function flatten(tasks: TaskNode[]): FlatTask[] {
    const out: FlatTask[] = [];
    for (const t of tasks) {
        const { subtasks, ...leaf } = t;
        out.push({ ...leaf, depth: 0, parentId: null, hasChildren: subtasks.length > 0 });
        for (const s of subtasks) out.push({ ...s, depth: 1, parentId: t.id, hasChildren: false });
    }
    return out;
}

// Re-nest a flat, ordered list back into the top-level/sub-task tree shape the UI renders.
export function buildTree(flat: FlatTask[]): TaskNode[] {
    const roots: TaskNode[] = [];
    const byId = new Map<number, TaskNode>();
    for (const f of flat) {
        if (f.depth === 0) {
            const node: TaskNode = { ...stripMeta(f), subtasks: [] };
            byId.set(f.id, node);
            roots.push(node);
        } else {
            // A sub-task attaches to the nearest preceding root (its parentId is set during projection).
            const parent = f.parentId != null ? byId.get(f.parentId) : roots[roots.length - 1];
            (parent ?? roots[roots.length - 1])?.subtasks.push(stripMeta(f));
        }
    }
    return roots;
}

function stripMeta(f: FlatTask): SubTask {
    return { id: f.id, title: f.title, note: f.note, done: f.done, dueDate: f.dueDate, assignedTo: f.assignedTo };
}

// Drop items that are children of any id in `ids` — used to drag a parent as a single unit (its
// sub-tasks travel with it instead of being reordered independently).
export function removeChildrenOf(items: FlatTask[], ids: number[]): FlatTask[] {
    const exclude = new Set(ids);
    return items.filter((i) => !(i.parentId != null && exclude.has(i.parentId)));
}

function getMaxDepth(prev: FlatTask | undefined) {
    return prev ? Math.min(prev.depth + 1, MAX_DEPTH) : 0;
}
function getMinDepth(next: FlatTask | undefined) {
    return next ? next.depth : 0;
}

// Where the active row would land: its projected depth (0/1) and the parent it would belong to, given
// how far right it has been dragged. Mirrors dnd-kit's sortable-tree projection, clamped to one level.
export function getProjection(items: FlatTask[], activeId: number, overId: number, dragOffset: number, indentWidth: number) {
    const overIndex = items.findIndex((i) => i.id === overId);
    const activeIndex = items.findIndex((i) => i.id === activeId);
    const activeItem = items[activeIndex];
    const newItems = arrayMove(items, activeIndex, overIndex);
    const prev = newItems[overIndex - 1];
    const next = newItems[overIndex + 1];

    const dragDepth = Math.round(dragOffset / indentWidth);
    const projected = activeItem.depth + dragDepth;
    const maxDepth = getMaxDepth(prev);
    const minDepth = getMinDepth(next);

    let depth = projected;
    if (depth >= maxDepth) depth = maxDepth;
    else if (depth < minDepth) depth = minDepth;
    // A task that owns sub-tasks can never itself be nested (keeps nesting to one level).
    if (activeItem.hasChildren && depth > 0) depth = 0;

    const parentId = getParentId();

    return { depth, maxDepth, minDepth, parentId };

    function getParentId(): number | null {
        if (depth === 0 || !prev) return null;
        if (depth === prev.depth) return prev.parentId;
        if (depth > prev.depth) return prev.id;
        return newItems
            .slice(0, overIndex)
            .reverse()
            .find((i) => i.depth === depth)?.parentId ?? null;
    }
}
