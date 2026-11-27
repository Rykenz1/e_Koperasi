<?php
// includes/csrf_helpers.php
// Shared CSRF protection for every state-changing POST in the app.
// Storefront pages reach this through includes/storefront_helpers.php.

const CSRF_SESSION_KEY = 'csrf_token';

/**
 * Return the current session's CSRF token, creating one on first use.
 */
function csrf_token(): string
{
    if (empty($_SESSION[CSRF_SESSION_KEY])) {
        $_SESSION[CSRF_SESSION_KEY] = bin2hex(random_bytes(32));
    }

    return $_SESSION[CSRF_SESSION_KEY];
}

/**
 * Compare a submitted token against the session token in constant time.
 */
function csrf_valid($token): bool
{
    return is_string($token)
        && !empty($_SESSION[CSRF_SESSION_KEY])
        && hash_equals($_SESSION[CSRF_SESSION_KEY], $token);
}

/**
 * Hidden input for a POST form.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
