<?php

namespace ReportingEngine\Api;

use ReportingEngine\Core\Auth;
use ReportingEngine\Core\Request;
use ReportingEngine\Core\Response;

class AuthController
{
    public function login(Request $request): Response
    {
        $username = $request->body['username'] ?? '';
        $password = $request->body['password'] ?? '';

        if ($username === '' || $password === '') {
            return Response::error('Username and password are required', 422);
        }

        $token = Auth::authenticate($username, $password);
        if ($token === null) {
            return Response::error('Invalid credentials', 401);
        }

        return Response::json([
            'token' => $token,
            'user' => $username,
        ], 200, 'Login successful');
    }

    public function me(Request $request): Response
    {
        $user = Auth::getCurrentUser($request);
        if ($user === null) {
            return Response::error('Not authenticated', 401);
        }
        return Response::json(['user' => $user]);
    }

    public function logout(Request $request): Response
    {
        return Response::json(null, 200, 'Logged out');
    }
}
