<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Compression gzip des réponses JSON/CSV (le serveur PHP intégré ne compresse pas). */
class CompressResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse
            || $response->headers->has('Content-Encoding')
            || ! str_contains((string) $request->header('Accept-Encoding'), 'gzip')
            || ! function_exists('gzencode')) {
            return $response;
        }
        $content = (string) $response->getContent();
        if (strlen($content) < 1024) {
            return $response;
        }
        $response->setContent(gzencode($content, 6));
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Vary', 'Accept-Encoding', false);
        $response->headers->remove('Content-Length');

        return $response;
    }
}
