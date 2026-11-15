<?php

/*
|--------------------------------------------------------------------------
| Trusted proxies: Cloudflare
|--------------------------------------------------------------------------
|
| Every reader reaches the help centre through Cloudflare (ADR-0002, ticket #33), so a request
| arrives from a Cloudflare edge address with the reader's own address at the end of
| X-Forwarded-For. Laravel's TrustProxies middleware reads this list: a request from one of these
| ranges is taken as Cloudflare's, so $request->ip() is the reader's address (the rate limits key
| on it) and X-Forwarded-Proto says whether the reader used https. A request from anywhere else
| is taken at its word for nothing: its own address is the reader's, whatever headers it sends.
|
| This is Cloudflare's published list: https://www.cloudflare.com/ips-v4 and
| https://www.cloudflare.com/ips-v6 (also https://api.cloudflare.com/client/v4/ips), checked
| against those pages on 25 October 2026. Compare it with them again whenever the server's
| firewall list is updated: that firewall must accept web traffic only from these same ranges.
| Cloudflare rarely changes it.
|
*/

return [

    'proxies' => [
        // IPv4
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ],

];
