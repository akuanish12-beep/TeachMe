<?php

declare(strict_types=1);

namespace App\Application\Helpers;

use Psr\Http\Message\ServerRequestInterface as Request;

class AuthHeader
{
    /**
     * Resolve Authorization header (Apache/CGI often strips it from PSR-7 requests).
     */
    public static function getLine(Request $request): string
    {
        $header = $request->getHeaderLine('Authorization');
        if ($header !== '') {
            return $header;
        }

        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['HTTP_AUTHORIZATION'];
        }

        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strtolower((string) $name) === 'authorization') {
                        return (string) $value;
                    }
                }
            }
        }

        return '';
    }

    public static function bearerToken(Request $request): ?string
    {
        $header = self::getLine($request);
        if ($header !== '' && preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
