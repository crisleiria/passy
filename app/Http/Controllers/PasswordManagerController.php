<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PasswordManagerController extends Controller
{
    public function index(Request $request): Response
    {
        $passwords = $request->user()->passwords()
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('domain', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            })
            ->orderBy('domain')
            ->cursorPaginate(15);

        return Inertia::render('Passwords', [
            'passwords' => Inertia::merge(fn () => $passwords->items()),
            'next_cursor' => fn () => $passwords->nextCursor()?->encode(),
        ]);
    }


    public function store(Request $request)
    {
        $validated = $request->validate([
            'domain' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'password' => 'required|string',
        ]);

        $request->user()->passwords()->create($validated);

        return redirect()->back();
    }

    public function destroy(Request $request, \App\Models\Password $password)
    {
        if ($request->user()->id !== $password->user_id) {
            abort(403);
        }

        $password->delete();

        return redirect()->back();
    }
}
