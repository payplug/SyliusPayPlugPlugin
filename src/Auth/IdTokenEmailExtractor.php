<?php

declare(strict_types=1);

namespace PayPlug\SyliusPayPlugPlugin\Auth;

/**
 * Reads the `email` claim out of an OpenID Connect ID token.
 *
 * This is the only place a connected PayPlug account's email is available to the plugin. The
 * `/account` endpoint does not carry one — its payload is `id`, `company_ref`, `country`, `object`,
 * `is_live`, `configuration`, `permissions`, `payment_methods` and nothing else — and the
 * client-credentials token used for every background API call authenticates a machine, so it names
 * no user either. The address therefore has to be captured from the `id_token` of the interactive
 * authorization-code exchange, which is why {@see \PayPlug\SyliusPayPlugPlugin\Action\Admin\Auth\UnifiedAuthenticationController}
 * stores it on the gateway config at login time rather than fetching it on demand.
 *
 * The JWT's signature is deliberately NOT verified. The token is not a credential here and grants
 * nothing: it reaches us over TLS as the direct response body of a server-to-server POST to the
 * identity provider's token endpoint — never from the browser, never from a redirect parameter — and
 * the single claim read from it is echoed back to an admin as display text. There is no attacker
 * positioned to substitute a token without already controlling that response, and verifying the
 * signature would buy nothing while adding a JWKS fetch and key-rotation handling to an admin screen.
 *
 * Every method is total: malformed input of any shape yields null rather than an exception, because
 * the sole caller runs inside the OAuth callback, where throwing would abort a login that has
 * otherwise fully succeeded — the credentials are already minted by that point, and losing them over
 * an unreadable display value would be a far worse outcome than showing no email.
 */
final class IdTokenEmailExtractor
{
    private const PAYLOAD_SEGMENT = 1;

    private const SEGMENT_COUNT = 3;

    public function extract(?string $idToken): ?string
    {
        $claims = $this->decodeClaims($idToken);

        $email = $claims['email'] ?? null;

        if (!\is_string($email) || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    /**
     * @return array<array-key, mixed> The token's claims, or an empty array for anything unreadable
     */
    private function decodeClaims(?string $idToken): array
    {
        if (null === $idToken || '' === $idToken) {
            return [];
        }

        $segments = explode('.', $idToken);

        if (self::SEGMENT_COUNT !== \count($segments)) {
            return [];
        }

        $payload = $this->base64UrlDecode($segments[self::PAYLOAD_SEGMENT]);

        if (null === $payload) {
            return [];
        }

        $claims = json_decode($payload, true);

        return \is_array($claims) ? $claims : [];
    }

    /**
     * JWT segments use base64url (RFC 7515 §2): `-`/`_` in place of `+`/`/`, and no `=` padding.
     * Feeding one straight to base64_decode() silently corrupts any payload containing those
     * characters, which real id tokens routinely do.
     */
    private function base64UrlDecode(string $segment): ?string
    {
        $padded = str_pad(strtr($segment, '-_', '+/'), (int) (ceil(\strlen($segment) / 4) * 4), '=');

        $decoded = base64_decode($padded, true);

        return false === $decoded ? null : $decoded;
    }
}
