<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Security\Csrf;
use App\Security\ExternalIdentityService;
use App\Security\LoginThrottle;
use App\Support\Auth;
use App\Support\ControllerFailure;
use App\Support\Flash;

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
            ControllerFailure::flash($e, 'CPE_AUTH_PASSWORD_FAILURE', 'auth.password');
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
            ControllerFailure::flash($e, 'CPE_AUTH_LOGOUT_FAILURE', 'auth.logout');
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
            ControllerFailure::flash($e, 'CPE_AUTH_SSO_FAILURE', 'auth.sso');
            redirect(url('login'));
        }
    }
}
