<?php
/** Approved public production identity; internal navigation remains relative. */
const PRISM_PUBLIC_BASE_URL = 'https://www.rpmsceu.online/';
function prism_public_legal_url(string $policy): string
{
    if (!in_array($policy, ['privacy','terms'], true)) throw new InvalidArgumentException('Unknown legal policy.');
    return PRISM_PUBLIC_BASE_URL.$policy.'.php';
}
