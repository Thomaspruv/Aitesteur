<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('admin.dashboard'));
    $response->assertRedirect(route('login'));
});

test('a non-admin authenticated user is rejected', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $this->actingAs($user);

    $response = $this->get(route('admin.dashboard'));
    $response->assertForbidden();
});

test('an admin can view the dashboard', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $this->actingAs($user);

    $response = $this->get(route('admin.dashboard'));
    $response->assertOk();
});

test('an admin can view any project drill-down, not just their own', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $otherUser = User::factory()->create(['is_admin' => false]);
    $otherProject = $otherUser->currentProject();

    $this->actingAs($admin);

    $response = $this->get(route('admin.project', $otherProject));
    $response->assertOk();
});

test('a non-admin is rejected from the project drill-down too', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $project = $user->currentProject();
    $this->actingAs($user);

    $response = $this->get(route('admin.project', $project));
    $response->assertForbidden();
});
