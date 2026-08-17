<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Security\Csrf;
use App\Security\ExternalIdentityService;
use App\Security\LoginThrottle;
use App\Support\Auth;
use App\Support\Flash;
use App\Support\StructuredLogger;

final class AuthController
{
    public function show(): void
    {
        view('login', ['ssoEnabled' => ExternalIdentityService::enabled()]);
    }

    public function login(): void
    {
        try {
            Csrf::verify($_POST['_token'] ?? null);
            $email = (string) ($_POST['email'] ?? '');
            $throttle = new LoginThrottle();
            $throttle->assertAllowed($email);
            if (Auth::attempt($email, (string) ($_POST['password'] ?? ''))) {
                $throttle->recordSuccess($email);
                Csrf::rotate();
                redirect('/');
            }
            $throttle->recordFailure($email);
            Flash::add('error', 'Invalid login.');
            redirect(url('login'));
        } catch (\Throwable $e) {
            StructuredLogger::log('warning', 'auth.password_error', ['exception' => get_class($e), 'message' => $e->getMessage()]);
            $message = str_starts_with($e->getMessage(), 'Too many sign-in attempts')
                ? $e->getMessage()
                : 'Sign-in could not be completed. Please try again.';
            Flash::add('error', $message);
            redirect(url('login'));
        }
    }

    public function logout(): void
    {
        try {
            Csrf::verify($_POST['_token'] ?? null);
            Auth::logout();
            Csrf::rotate();
            Flash::add('success', 'Signed out.');
        } catch (\Throwable $e) {
            StructuredLogger::log('warning', 'auth.logout_error', ['exception' => get_class($e), 'message' => $e->getMessage()]);
            Flash::add('error', 'Sign-out could not be completed. Please try again.');
        }
        redirect(url('login'));
    }

    public function sso(): void
    {
        try {
            (new ExternalIdentityService())->authenticateRequest();
            Csrf::rotate();
            redirect('/');
        } catch (\Throwable $e) {
            StructuredLogger::log('warning', 'auth.sso_error', ['exception' => get_class($e), 'message' => $e->getMessage()]);
            Flash::add('error', 'Institutional sign-in could not be completed. Contact your portal administrator.');
            redirect(url('login'));
        }
    }
}
