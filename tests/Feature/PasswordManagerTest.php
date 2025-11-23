<?php

use App\Models\User;
use App\Models\Password;

test('passwords can be created', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/passwords', [
        'domain' => 'example.com',
        'username' => 'johndoe',
        'password' => 'secret',
    ]);

    $response->assertRedirect();
    
    $this->assertDatabaseHas('passwords', [
        'user_id' => $user->id,
        'domain' => 'example.com',
        'username' => 'johndoe',
    ]);
});

test('passwords cannot be created with invalid data', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/passwords', [
        'domain' => '',
        'username' => '',
        'password' => '',
    ]);

    $response->assertSessionHasErrors(['domain', 'username', 'password']);
});
