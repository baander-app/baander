<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\HttpFoundation;

use Swoole\Http\Request as SwooleRequest;
use Symfony\Component\HttpFoundation\Request as HttpFoundationRequest;

use function SwooleBundle\SwooleBundle\canonical_ip_address;

final class DefaultRequestFactory implements RequestFactory
{
    public function make(SwooleRequest $request): HttpFoundationRequest
    {
        $server = array_change_key_case($request->server, CASE_UPPER);
        // Proxy trust, rate limits and access logs then see an IPv4 peer of a dual-stack socket in one form.
        if (is_string($server['REMOTE_ADDR'] ?? null)) {
            $server['REMOTE_ADDR'] = canonical_ip_address($server['REMOTE_ADDR']);
        }

        // Add formatted headers to server
        foreach ($request->header as $key => $value) {
            $server['HTTP_' . mb_strtoupper(str_replace('-', '_', (string) $key))] = $value;
        }

        $queryString = $server['QUERY_STRING'] ?? '';
        $server['REQUEST_URI'] ??= '';
        $server['REQUEST_URI'] .= $queryString !== '' ? '?' . $queryString : '';
        $rawContent = $request->rawContent();

        return new HttpFoundationRequest(
            $request->get ?? [],
            $request->post ?? [],
            [],
            $request->cookie ?? [],
            $request->files ?? [],
            $server,
            $rawContent === false ? '' : (string) $rawContent,
        );
    }
}
