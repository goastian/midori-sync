<?php

$proxies = array_values(array_filter(
    array_map('trim', explode(',', (string) env('SYNC_TRUSTED_PROXIES', ''))),
    fn (string $proxy): bool => $proxy !== '',
));

foreach ($proxies as $proxy) {
    [$address, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);

    if (filter_var($address, FILTER_VALIDATE_IP) === false ||
        ($prefix !== null && (! ctype_digit($prefix) || (int) $prefix < 1 ||
            (int) $prefix > (str_contains($address, ':') ? 128 : 32)))) {
        throw new InvalidArgumentException('SYNC_TRUSTED_PROXIES must contain explicit IP addresses or CIDRs with a nonzero prefix.');
    }
}

return [
    'proxies' => $proxies,
];
