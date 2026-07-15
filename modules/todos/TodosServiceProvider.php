<?php

namespace Modules\Todos;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class TodosServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'module:todos'])->group(function () {
            Route::get('/m/todos/items', [TodosController::class, 'index'])->name('todos.index');
            Route::post('/m/todos/items', [TodosController::class, 'store'])->name('todos.store');
            Route::patch('/m/todos/items/{item}', [TodosController::class, 'update'])->name('todos.update');
            Route::delete('/m/todos/items/{item}', [TodosController::class, 'destroy'])->name('todos.destroy');
        });
    }
}
