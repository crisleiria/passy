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
            'passwords' => Inertia::merge(fn () => $passwords),
        ]);
    }
}
